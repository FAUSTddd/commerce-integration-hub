<?php
declare(strict_types=1);

namespace App\Monitoring\Service;

use App\Monitoring\Dto\ImportRunSnapshot;
use App\Monitoring\Dto\OutboxMetricSnapshot;
use App\Monitoring\Dto\QueueMetricSnapshot;
use App\Monitoring\Dto\SystemHealthOverview;
use App\Monitoring\Repository\MonitoringQueryRepository;
use App\Monitoring\Sanitizer\MonitoringDataSanitizer;
use DateTimeImmutable;
use Throwable;

class SystemHealthMonitorService
{
    private const KNOWN_QUEUES = ['async_catalog', 'async_offers'];
    private const STALE_TIMEOUT_CATALOG = 5400;
    private const STALE_TIMEOUT_OFFERS = 900;

    public function __construct(
        private MonitoringQueryRepository $repository,
        private MonitoringDataSanitizer $sanitizer
    ) {}

    /**
     * @return ImportRunSnapshot[]
     */
    public function getActiveRuns(): array
    {
        $rows = $this->repository->getActiveImportRuns();
        $snapshots = [];
        $now = time();

        foreach ($rows as $row) {
            $type = (string) $row['type'];
            $createdAt = isset($row['created_at']) ? strtotime((string) $row['created_at']) : $now;
            $duration = max(0, $now - ($createdAt !== false ? $createdAt : $now));
            
            $threshold = ($type === 'catalog') ? self::STALE_TIMEOUT_CATALOG : self::STALE_TIMEOUT_OFFERS;
            $isStale = ($row['status'] === 'PROCESSING') && ($duration > $threshold);

            $snapshots[] = new ImportRunSnapshot(
                (int) $row['id'],
                $type,
                (string) $row['status'],
                (int) $row['attempt'],
                $duration,
                $isStale,
                $this->sanitizer->sanitizeFilename((string) $row['filename']),
                $this->sanitizer->sanitizeError($row['last_error'] ?? null)
            );
        }

        return $snapshots;
    }

    public function getSystemOverview(): SystemHealthOverview
    {
        $timestamp = new DateTimeImmutable();
        $anomalies = [];
        $hasCritical = false;
        $hasDegraded = false;

        // 1. Check Import Runs
        $activeRuns = [];
        try {
            $activeRuns = $this->getActiveRuns();
            foreach ($activeRuns as $run) {
                if ($run->isStale) {
                    $hasDegraded = true;
                    $anomalies[] = sprintf('Stale import run detected: ID %d (%s, %ds)', $run->id, $run->type, $run->durationSeconds);
                }
            }
        } catch (Throwable $e) {
            $hasCritical = true;
            $anomalies[] = 'ImportRun data source failure: ' . $e->getMessage();
        }

        // 2. Check Messenger Queues & Failed Transport
        $queueMetrics = [];
        try {
            foreach (self::KNOWN_QUEUES as $queueName) {
                $metrics = $this->repository->getMessengerMetrics($queueName);
                $queueMetrics[] = new QueueMetricSnapshot(
                    $queueName,
                    $metrics['pending'],
                    $metrics['in_flight'],
                    $metrics['delayed'],
                    $metrics['oldest_age']
                );
            }

            $failedCount = $this->repository->getFailedTransportCount();
            $queueMetrics[] = new QueueMetricSnapshot(
                'failed',
                $failedCount,
                0,
                0,
                null
            );

            if ($failedCount > 0) {
                $hasDegraded = true;
                $anomalies[] = sprintf('Failed transport contains %d message(s)', $failedCount);
            }
        } catch (Throwable $e) {
            $hasCritical = true;
            $anomalies[] = 'Queue/Messenger data source failure: ' . $e->getMessage();
        }

        // 3. Check Outbox
        $outboxSnapshot = null;
        try {
            $outboxData = $this->repository->getOutboxMetrics();
            $lastPublishedAt = null;
            if ($outboxData['last_published_at'] !== null) {
                $lastPublishedAt = new DateTimeImmutable($outboxData['last_published_at']);
            }

            $outboxSnapshot = new OutboxMetricSnapshot(
                $outboxData['unpublished'],
                $outboxData['deferred_retries'],
                $outboxData['oldest_unpublished_age'],
                $lastPublishedAt
            );

            if ($outboxData['deferred_retries'] > 0) {
                $hasDegraded = true;
                $anomalies[] = sprintf('Outbox deferred retries detected: %d message(s)', $outboxData['deferred_retries']);
            }
        } catch (Throwable $e) {
            $hasCritical = true;
            $anomalies[] = 'Outbox data source failure: ' . $e->getMessage();
        }

        // 4. Resolve Overall Status
        $status = 'HEALTHY';
        if ($hasCritical) {
            $status = 'CRITICAL';
        } elseif ($hasDegraded) {
            $status = 'DEGRADED';
        }

        return new SystemHealthOverview(
            $status,
            $timestamp,
            $activeRuns,
            $queueMetrics,
            $outboxSnapshot,
            $anomalies
        );
    }
}
