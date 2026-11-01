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
