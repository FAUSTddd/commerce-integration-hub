<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ImportRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportRun>
 */
class ImportRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportRun::class);
    }

    public function save(ImportRun $importRun): void
    {
        $this->getEntityManager()->persist($importRun);
        $this->getEntityManager()->flush();
    }

    public function findActiveRun(string $type, string $filename): ?ImportRun
    {
        return $this->createQueryBuilder('r')
            ->where('r.type = :type')
            ->andWhere('r.filename = :filename')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('type', $type)
            ->setParameter('filename', $filename)
            ->setParameter('statuses', [ImportRun::STATUS_PENDING, ImportRun::STATUS_PROCESSING])
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Atomically creates a new ImportRun in PENDING status if no active run (PENDING or PROCESSING)
     * exists for the given type and filename.
     *
     * Relies on PostgreSQL partial unique index:
     * CREATE UNIQUE INDEX uniq_import_runs_active_type_filename ON import_runs (type, filename)
     * WHERE status IN ('PENDING', 'PROCESSING');
     *
     * Returns the allocated integer ID on success, or null if an active run already exists (ON CONFLICT DO NOTHING).
     * If the partial unique index does not exist in the database, the DBAL exception is explicitly propagated.
     */
    public function tryCreateActiveRun(string $type, string $filename, string $fileHash): ?int
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<SQL
            INSERT INTO import_runs (type, filename, file_hash, status, items_count, created_at)
            VALUES (:type, :filename, :fileHash, 'PENDING', 0, NOW())
            ON CONFLICT (type, filename) WHERE status IN ('PENDING', 'PROCESSING')
            DO NOTHING
            RETURNING id
        SQL;

        $result = $conn->fetchOne($sql, [
            'type' => $type,
            'filename' => $filename,
            'fileHash' => $fileHash,
        ]);

        return $result !== false && $result !== null ? (int) $result : null;
    }

    /**
     * Verifies if any message matching the given runId is present in the transport table.
     */
    public function hasMessageForRunId(int $runId): bool
    {
        $conn = $this->getEntityManager()->getConnection();
        $pattern = '%ImportRunStamp%';

        try {
            $rows = $conn->fetchAllAssociative(
                'SELECT body FROM messenger_messages WHERE body LIKE :pattern',
                ['pattern' => $pattern]
            );
        } catch (\Throwable) {
            return false;
        }

        foreach ($rows as $row) {
            $body = (string) $row['body'];
            if ($this->extractImportRunIdFromBody($body) === $runId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Atomically tries to acquire a session-level PostgreSQL advisory lock for the run ID.
     */
    public function tryAcquireSessionAdvisoryLock(int $runId): bool
    {
        $conn = $this->getEntityManager()->getConnection();
        return (bool) $conn->fetchOne('SELECT pg_try_advisory_lock(:id)', ['id' => $runId]);
    }

    /**
     * Releases the session-level PostgreSQL advisory lock for the run ID.
     */
    public function releaseSessionAdvisoryLock(int $runId): bool
    {
        $conn = $this->getEntityManager()->getConnection();
        return (bool) $conn->fetchOne('SELECT pg_advisory_unlock(:id)', ['id' => $runId]);
    }

    /**
     * CAS2: Atomically transitions an ImportRun into active PROCESSING state,
     * assigning a new UUID fencing token and incrementing the attempt counter.
     * Claim is allowed from PENDING (attempt=0) or from PROCESSING with NULL fencing_token
     * when retry_scheduled_at is NULL or in the past.
     *
     * Returns the assigned UUID fencing token on success, or null on failure.
     */
    public function claimRunCas2(int $runId, string $fencingToken): bool
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<SQL
            UPDATE import_runs
            SET status = 'PROCESSING',
                attempt = attempt + 1,
                fencing_token = :token::uuid,
                retry_scheduled_at = NULL,
                has_deferred_retry = FALSE,
                started_at = COALESCE(started_at, NOW())
            WHERE id = :id
              AND (
                (status = 'PENDING' AND attempt = 0 AND fencing_token IS NULL)
                OR
                (status = 'PROCESSING' AND fencing_token IS NULL AND (retry_scheduled_at IS NULL OR retry_scheduled_at <= NOW()))
                OR
                (status = 'PROCESSING' AND (retry_scheduled_at IS NULL OR retry_scheduled_at <= NOW()))
              )
        SQL;

        $rows = $conn->executeStatement($sql, [
            'id' => $runId,
            'token' => $fencingToken,
        ]);

        if ($rows === 1) {
            $em = $this->getEntityManager();
            $entity = $em->find(ImportRun::class, $runId);
            if ($entity !== null) {
                $em->refresh($entity);
            }
            return true;
        }

        return false;
    }

    /**
     * CAS5: Atomically marks a stale ImportRun as FAILED within a transaction
     * while holding a transaction-level advisory lock pg_try_advisory_xact_lock.
     *
     * Guarantees mutual exclusion with active workers and rejects recovery
     * if the run was concurrently claimed, modified, or has an unexpired retry_scheduled_at.
     */
    public function markStaleRunFailedCas5(
        int $runId,
        string $errorMessage,
        int $expectedAttempt,
        ?string $expectedToken
    ): bool {
        $conn = $this->getEntityManager()->getConnection();

        return (bool) $conn->transactional(function () use ($conn, $runId, $errorMessage, $expectedAttempt, $expectedToken): bool {
            // 1. Transaction-level advisory lock
            $locked = (bool) $conn->fetchOne('SELECT pg_try_advisory_xact_lock(:id)', ['id' => $runId]);
            if (!$locked) {
                return false;
            }

            // Check if there are active unpublished or in-flight outbox records
            $activeOutbox = (bool) $conn->fetchOne(
                'SELECT 1 FROM import_run_outbox WHERE run_id = :runId AND acked_at IS NULL LIMIT 1',
                ['runId' => $runId]
            );

            if ($activeOutbox) {
                // Active outbox entry exists (pending retry, defer, or in-flight delivery)
                return false;
            }

            // 2. CAS predicate check and update - strictly NO increment of attempt!
            $sql = <<<SQL
                UPDATE import_runs
                SET status = 'FAILED',
                    fencing_token = NULL,
                    error_message = :error,
                    finished_at = NOW(),
                    retry_scheduled_at = NULL,
                    has_deferred_retry = FALSE
                WHERE id = :id
                  AND status = 'PROCESSING'
                  AND attempt = :expectedAttempt
                  AND fencing_token IS NOT DISTINCT FROM (:expectedToken)::uuid
                  AND (retry_scheduled_at IS NULL OR retry_scheduled_at <= NOW())
            SQL;

            $rows = $conn->executeStatement($sql, [
                'id' => $runId,
                'error' => mb_substr($errorMessage, 0, 2000),
                'expectedAttempt' => $expectedAttempt,
                'expectedToken' => $expectedToken,
            ]);

            return $rows === 1;
        });
    }

    /**
     * CAS4A: Transitions an ImportRun into retry waiting state.
     * Clears fencing_token, sets retry_scheduled_at to the exact available_at of the retry message
     * in messenger_messages (AT TIME ZONE 'UTC').
     *
     * Preconditions:
     * - Status must be 'PROCESSING'
     * - Attempt must match expectedAttempt
     * - Fencing token must match expectedToken
     * - The retry message must exist in messenger_messages with a non-null available_at.
     */
    public function markRunForRetryCas4A(
        int $runId,
        int|string $messengerMessageId,
        int $expectedAttempt,
        ?string $expectedToken
    ): bool {
        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<SQL
            UPDATE import_runs
            SET fencing_token = NULL,
                retry_scheduled_at = (
                    SELECT available_at AT TIME ZONE 'UTC'
                    FROM messenger_messages
                    WHERE id = :msgId
                ),
                has_deferred_retry = FALSE
            WHERE id = :id
              AND status = 'PROCESSING'
              AND attempt = :expectedAttempt
              AND fencing_token IS NOT DISTINCT FROM (:expectedToken)::uuid
              AND EXISTS (
                  SELECT 1 FROM messenger_messages WHERE id = :msgId AND available_at IS NOT NULL
              )
        SQL;

        $rows = $conn->executeStatement(
            $sql,
            [
                'id' => $runId,
                'msgId' => (int) $messengerMessageId,
                'expectedAttempt' => $expectedAttempt,
                'expectedToken' => $expectedToken,
            ],
            [
                'id' => Types::BIGINT,
                'msgId' => Types::BIGINT,
                'expectedAttempt' => Types::INTEGER,
                'expectedToken' => Types::STRING,
            ]
        );

        return $rows === 1;
    }

    /**
     * Atomically registers a Defer intent into import_run_outbox when advisory lock is held.
     * Prevents lost messages by ensuring a persistent outbox task exists before ACK.
     *
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $payload
     * @return bool True if a new defer was created OR an active unpublished defer already exists
     */
    public function scheduleOutboxDeferCas(
        int $runId,
        int $attempt,
        string $queueName,
        array $headers,
        array $payload,
        int $delaySeconds = 3
    ): bool {
        $conn = $this->getEntityManager()->getConnection();

        return (bool) $conn->transactional(function () use (
            $conn,
            $runId,
            $attempt,
            $queueName,
            $headers,
            $payload,
            $delaySeconds
        ): bool {
            // Check if there is already an active unpublished defer for this run
            $hasActiveDefer = (bool) $conn->fetchOne(
                "SELECT 1 FROM import_run_outbox WHERE run_id = :runId AND kind = 'defer' AND published_at IS NULL",
                ['runId' => $runId]
            );

            if ($hasActiveDefer) {
                return true;
            }

            $idempotencyKey = sprintf('run:%d:defer:%s', $runId, bin2hex(random_bytes(8)));

            $sqlInsert = <<<SQL
                INSERT INTO import_run_outbox (
                    run_id,
                    attempt,
                    kind,
                    idempotency_key,
                    queue_name,
                    headers,
                    payload,
                    available_at,
                    created_at
                ) VALUES (
                    :runId,
                    :attempt,
                    'defer',
                    :idempotencyKey,
                    :queueName,
                    :headers::jsonb,
                    :payload::jsonb,
                    NOW() + make_interval(secs => :delaySec),
                    NOW()
                )
                ON CONFLICT (run_id) WHERE (kind = 'defer' AND published_at IS NULL) DO NOTHING
            SQL;

            $conn->executeStatement($sqlInsert, [
                'runId' => $runId,
                'attempt' => $attempt,
                'idempotencyKey' => $idempotencyKey,
                'queueName' => $queueName,
                'headers' => json_encode($headers, JSON_THROW_ON_ERROR),
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'delaySec' => $delaySeconds,
            ]);

            return true;
        });
    }

    /**
     * Accurately marks an Outbox entry as ACKed (consumed) by primary key ID.
     * Enforces published_at IS NOT NULL and acked_at IS NULL for idempotency.
     */
    public function markOutboxAckedById(int $outboxId): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $conn->executeStatement(
            'UPDATE import_run_outbox
             SET acked_at = NOW()
             WHERE id = :id
               AND published_at IS NOT NULL
               AND acked_at IS NULL',
            ['id' => $outboxId]
        );
    }

    /**
     * Marks an Outbox entry as ACKed (consumed) by the worker.
     * For 'defer' entries, the worker consumes the message after claimRunCas2 increments attempt (attempt = defer_attempt + 1).
     * For 'retry' entries, attempt matches the run's expectedAttempt.
     * Strictly targets published entries for the given run_id matching either current attempt or attempt - 1 (for defer),
     * preventing ACK of unconsumed or concurrent future attempts.
     */
    public function markOutboxAcked(int $runId, int $attempt): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $conn->executeStatement(
            "UPDATE import_run_outbox
             SET acked_at = NOW()
             WHERE run_id = :runId
               AND published_at IS NOT NULL
               AND acked_at IS NULL
               AND (
                   (kind = 'defer' AND (attempt = :attempt OR attempt = :attempt - 1))
                   OR
                   (kind <> 'defer' AND attempt = :attempt)
               )",
            ['runId' => $runId, 'attempt' => $attempt]
        );
    }

    /**
     * Attempts to acquire single-flight defer permission.
     * Sets has_deferred_retry = TRUE if it is currently FALSE.
     */
    public function tryAcquireSingleFlightDefer(int $runId): bool
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<SQL
            UPDATE import_runs
            SET has_deferred_retry = TRUE
            WHERE id = :id
              AND (status = 'PENDING' OR status = 'PROCESSING')
              AND has_deferred_retry = FALSE
        SQL;

        return $conn->executeStatement($sql, ['id' => $runId]) === 1;
    }

    /**
     * Atomically executes CAS4A and inserts retry message into import_run_outbox.
     *
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $payload
     */
    public function markRunForRetryWithOutboxCas4A(
        int $runId,
        int $delaySeconds,
        int $expectedAttempt,
        ?string $expectedToken,
        string $queueName,
        array $headers,
        array $payload
    ): bool {
        $conn = $this->getEntityManager()->getConnection();

        return (bool) $conn->transactional(function () use (
            $conn,
            $runId,
            $delaySeconds,
            $expectedAttempt,
            $expectedToken,
            $queueName,
            $headers,
            $payload
        ): bool {
            $sqlCas4A = <<<SQL
                UPDATE import_runs
                SET fencing_token = NULL,
                    retry_scheduled_at = NOW() + make_interval(secs => :delaySec),
                    has_deferred_retry = FALSE
                WHERE id = :id
                  AND status = 'PROCESSING'
                  AND attempt = :expectedAttempt
                  AND fencing_token IS NOT DISTINCT FROM (:expectedToken)::uuid
            SQL;

            $rows = $conn->executeStatement($sqlCas4A, [
                'id' => $runId,
                'delaySec' => $delaySeconds,
                'expectedAttempt' => $expectedAttempt,
                'expectedToken' => $expectedToken,
            ]);

            if ($rows !== 1) {
                return false;
            }

            $sqlOutbox = <<<SQL
                INSERT INTO import_run_outbox (
                    run_id,
                    attempt,
                    kind,
                    idempotency_key,
                    queue_name,
                    headers,
                    payload,
                    available_at,
                    created_at
                ) VALUES (
                    :runId,
                    :attempt,
                    'retry',
                    :idempotencyKey,
                    :queueName,
                    :headers::jsonb,
                    :payload::jsonb,
                    NOW() + make_interval(secs => :delaySec),
                    NOW()
                )
                ON CONFLICT (idempotency_key) DO NOTHING
            SQL;

            $idempotencyKey = sprintf('run:%d:attempt:%d:retry', $runId, $expectedAttempt);

            $conn->executeStatement($sqlOutbox, [
                'runId' => $runId,
                'attempt' => $expectedAttempt,
                'idempotencyKey' => $idempotencyKey,
                'queueName' => $queueName,
                'headers' => json_encode($headers, JSON_THROW_ON_ERROR),
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'delaySec' => $delaySeconds,
            ]);

            return true;
        });
    }

    /**
     * CAS4B: Transitions an ImportRun into terminal FAILED state when retries are exhausted.
     */
    public function markRunExhaustedFailedCas4B(
        int $runId,
        string $errorMessage,
        int $expectedAttempt,
        ?string $expectedToken
    ): bool {
        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<SQL
            UPDATE import_runs
            SET status = 'FAILED',
                fencing_token = NULL,
                error_message = :error,
                finished_at = NOW()
            WHERE id = :id
              AND status = 'PROCESSING'
              AND attempt = :expectedAttempt
              AND fencing_token IS NOT DISTINCT FROM (:expectedToken)::uuid
        SQL;

        $rows = $conn->executeStatement($sql, [
            'id' => $runId,
            'error' => mb_substr($errorMessage, 0, 2000),
            'expectedAttempt' => $expectedAttempt,
            'expectedToken' => $expectedToken,
        ]);

        return $rows === 1;
    }

    /**
     * Atomically marks an ImportRun as FAILED with a strict CAS-predicate (status = 'PROCESSING').
     *
     * Returns true if the status was successfully transitioned from PROCESSING to FAILED,
     * or false if the run was already completed/changed by a worker (status !== 'PROCESSING').
     */
    public function markStaleRunFailedCas(int $runId, string $errorMessage): bool
    {
        $conn = $this->getEntityManager()->getConnection();

        $rowsAffected = $conn->executeStatement(
            "UPDATE import_runs
             SET status = :failedStatus,
                 error_message = :error,
                 finished_at = NOW()
             WHERE id = :id AND status = :processingStatus",
            [
                'failedStatus' => ImportRun::STATUS_FAILED,
                'processingStatus' => ImportRun::STATUS_PROCESSING,
                'error' => mb_substr($errorMessage, 0, 2000),
                'id' => $runId,
            ]
        );

        if ($rowsAffected === 1) {
            $em = $this->getEntityManager();
            $entity = $em->find(ImportRun::class, $runId);
            if ($entity !== null) {
                $em->refresh($entity);
            }
            return true;
        }

        return false;
    }

    /**
     * Atomically marks an ImportRun as FAILED with a strict CAS-predicate (status = 'PENDING').
     *
     * Returns true if the status was successfully transitioned to FAILED,
     * or false if the run was already picked up by a worker (status !== 'PENDING').
     */
    public function compensateFailedEnqueue(int $runId, string $errorMessage): bool
    {
        $conn = $this->getEntityManager()->getConnection();

        $rowsAffected = $conn->executeStatement(
            "UPDATE import_runs
             SET status = :failedStatus,
                 error_message = :error,
                 finished_at = NOW()
             WHERE id = :id AND status = :pendingStatus",
            [
                'failedStatus' => ImportRun::STATUS_FAILED,
                'pendingStatus' => ImportRun::STATUS_PENDING,
                'error' => mb_substr($errorMessage, 0, 2000),
                'id' => $runId,
            ]
        );

        return $rowsAffected > 0;
    }

    public function findLatestPendingOrProcessing(string $type, string $filename): ?ImportRun
    {
        return $this->findActiveRun($type, $filename);
    }

    /**
     * @return string[]
     */
    public function findActiveFilenames(): array
    {
        /** @var string[] $results */
        $results = $this->createQueryBuilder('r')
            ->select('DISTINCT r.filename')
            ->where('r.status IN (:statuses)')
            ->setParameter('statuses', [ImportRun::STATUS_PENDING, ImportRun::STATUS_PROCESSING])
            ->getQuery()
            ->getSingleColumnResult();

        return $results;
    }

    /**
     * Finds runs stuck in PROCESSING status older than the given threshold.
     *
     * @return ImportRun[]
     */
    public function findStaleProcessingRuns(\DateTimeInterface $threshold): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status = :status')
            ->andWhere('r.startedAt IS NOT NULL AND r.startedAt <= :threshold')
            ->setParameter('status', ImportRun::STATUS_PROCESSING)
            ->setParameter('threshold', $threshold)
            ->orderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Checks if there is a message currently handled by a worker in the messenger_messages table
     * associated with the given ImportRun that falls within the transport's redeliver timeout.
     *
     * IMPORTANT ARCHITECTURAL NOTE:
     * A recent delivered_at timestamp does NOT prove that the worker is still alive (the process
     * might be frozen or crashed mid-execution). It only acts as a safety guard against premature
     * recovery while the message is within the worker redelivery window (Doctrine transport default: 3600s).
     *
     * Messages with delivered_at IS NULL (waiting in queue, not picked up by a worker) or expired
     * delivered_at (> redeliver_timeout) do not protect against recovery.
     */
    public function isRunActivelyHandledByWorker(ImportRun $run, int $redeliverTimeoutSeconds = 3600): bool
    {
        $runId = $run->getId();
        if ($runId === null) {
            return false;
        }

        $conn = $this->getEntityManager()->getConnection();

        // Pattern match on ImportRunStamp as a pre-filter
        $pattern = '%ImportRunStamp%';
        $rows = $conn->fetchAllAssociative(
            'SELECT id, queue_name, delivered_at, available_at, body FROM messenger_messages WHERE body LIKE :pattern',
            ['pattern' => $pattern]
        );

        if (empty($rows)) {
            return false;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $redeliveryThreshold = $now->modify(sprintf('-%d seconds', $redeliverTimeoutSeconds));

        foreach ($rows as $row) {
            $body = (string) $row['body'];

            if (!str_contains($body, 'ImportRunStamp')) {
                continue;
            }

            // Extract and verify exact ID: do not rely solely on loose substring match
            $extractedId = $this->extractImportRunIdFromBody($body);
            if ($extractedId !== $runId) {
                continue;
            }

            // If delivered_at is NULL, the message is only in queue and has not been picked up by a worker
            if ($row['delivered_at'] === null) {
                continue;
            }

            // Parse delivered_at: PostgreSQL driver returns 'Y-m-d H:i:s' without timezone
            $deliveredAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $row['delivered_at'], new \DateTimeZone('UTC'));
            if ($deliveredAt === false) {
                $deliveredAt = new \DateTimeImmutable((string) $row['delivered_at'], new \DateTimeZone('UTC'));
            }

            if ($deliveredAt >= $redeliveryThreshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extracts the exact integer importRunId from serialized message body (PHP serialize or JSON).
     */
    private function extractImportRunIdFromBody(string $body): ?int
    {
        // 1. PHP serialized format generated by Symfony Messenger PhpSerializer:
        // Matches `importRunId` followed by closing quote (escaped or literal) and `;i:123;`
        // without calling stripslashes() on the entire message body (which would corrupt other escape sequences).
        if (preg_match('/importRunId[^a-zA-Z0-9]*;i:(\d+);/', $body, $matches)) {
            return (int) $matches[1];
        }

        // 2. JSON format (e.g. "importRunId":123)
        if (preg_match('/"importRunId"\s*:\s*(\d+)/', $body, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
