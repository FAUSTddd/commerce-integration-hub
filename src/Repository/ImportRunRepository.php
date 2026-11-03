<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ImportRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
