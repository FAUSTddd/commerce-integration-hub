<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Admin\Database\ReadOnlyTransactionRunner;
use App\Admin\Dto\OutboxRecentItemView;
use App\Admin\Dto\QueuesOverviewViewModel;
use App\Monitoring\Repository\MonitoringQueryRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class AdminQueueQueryService
{
    private const KNOWN_QUEUES = ['async_catalog', 'async_offers'];

    public function __construct(
        private MonitoringQueryRepository $monitoringRepo,
        private Connection $connection,
        private ReadOnlyTransactionRunner $transactionRunner,
    ) {
    }

    public function getQueuesOverview(): QueuesOverviewViewModel
    {
        return $this->transactionRunner->run(function (): QueuesOverviewViewModel {
            // 1. Messenger Queues
            $queues = [];
            $workerStarvationDetected = false;

            foreach (self::KNOWN_QUEUES as $queueName) {
                $metrics = $this->monitoringRepo->getMessengerMetrics($queueName);
                $pending = $metrics['pending'];
                $oldestAge = $metrics['oldest_age'] ?? 0;

                // Thresholds according to contract:
                // async_catalog: age > 3600s (WARN), > 5400s (CRITICAL)
                // async_offers: age > 300s (WARN), > 900s (CRITICAL)
                $status = 'OK';
                if ($queueName === 'async_catalog') {
                    if ($oldestAge > 5400) {
                        $status = 'CRITICAL';
                    } elseif ($oldestAge > 3600) {
                        $status = 'WARN';
                    }
                } elseif ($queueName === 'async_offers') {
                    if ($oldestAge > 900) {
                        $status = 'CRITICAL';
                    } elseif ($oldestAge > 300) {
                        $status = 'WARN';
                    }
                }

                if ($pending > 0 && $oldestAge > 900) {
                    $workerStarvationDetected = true;
                }

                $queues[] = [
                    'name' => $queueName,
                    'size' => $pending,
                    'oldest_message_age_seconds' => $oldestAge,
                    'status' => $status,
                ];
            }

            // Failed Transport
            $failedCount = $this->monitoringRepo->getFailedTransportCount();
            $queues[] = [
                'name' => 'failed',
                'size' => $failedCount,
                'oldest_message_age_seconds' => 0,
                'status' => $failedCount > 0 ? 'CRITICAL' : 'OK',
            ];

            // 2. Outbox Metrics
            $outboxMetrics = $this->monitoringRepo->getOutboxMetrics();

            // Stale defers count: kind = 'defer' and created_at < NOW() - INTERVAL '15 minutes' and published_at IS NULL
            $staleDefersSql = <<<SQL
                SELECT COUNT(*)
                FROM import_run_outbox
                WHERE kind = 'defer'
                  AND published_at IS NULL
                  AND created_at < NOW() - INTERVAL '15 minutes'
            SQL;
            $staleDefersCount = (int) $this->connection->fetchOne($staleDefersSql);

            // Recent unpublished (max 20)
            $recentUnpublishedSql = <<<SQL
                SELECT id, run_id, attempt, kind, queue_name, available_at, created_at
                FROM import_run_outbox
                WHERE published_at IS NULL
                ORDER BY created_at DESC, id DESC
                LIMIT 20
            SQL;
            $recentRows = $this->connection->fetchAllAssociative($recentUnpublishedSql);

            $recentUnpublished = [];
            foreach ($recentRows as $r) {
                $recentUnpublished[] = new OutboxRecentItemView(
                    (int) $r['id'],
                    (int) $r['run_id'],
                    (int) $r['attempt'],
                    (string) $r['kind'],
                    (string) $r['queue_name'],
                    new DateTimeImmutable((string) $r['available_at']),
                    new DateTimeImmutable((string) $r['created_at']),
                );
            }

            return new QueuesOverviewViewModel(
                queues: $queues,
                outbox: [
                    'unpublished_count' => $outboxMetrics['unpublished'],
                    'max_lag_seconds' => $outboxMetrics['oldest_unpublished_age'] ?? 0,
                    'stale_defers_count' => $staleDefersCount,
                    'recent_unpublished' => $recentUnpublished,
                ],
                workerStarvationDetected: $workerStarvationDetected,
            );
        });
    }
}
