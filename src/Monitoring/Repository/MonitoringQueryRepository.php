<?php
declare(strict_types=1);

namespace App\Monitoring\Repository;

use Doctrine\DBAL\Connection;

class MonitoringQueryRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * @return array<array<string, mixed>>
     */
    public function getActiveImportRuns(): array
    {
        $this->enforceStatementTimeout();

        $sql = "SELECT id, type, status, attempt, created_at, filename, error_message as last_error 
                FROM import_runs 
                WHERE status IN ('PENDING', 'PROCESSING')
                LIMIT 100";
        
        return $this->connection->executeQuery($sql)->fetchAllAssociative();
    }

    /**
     * @return array{pending: int, in_flight: int, delayed: int, oldest_age: ?int}
     */
    public function getMessengerMetrics(string $queueName): array
    {
        $this->enforceStatementTimeout();

        $sql = "SELECT 
                COUNT(*) FILTER (WHERE delivered_at IS NULL AND available_at <= NOW()) as pending,
                COUNT(*) FILTER (WHERE delivered_at IS NOT NULL) as in_flight,
                COUNT(*) FILTER (WHERE delivered_at IS NULL AND available_at > NOW()) as delayed,
                ROUND(EXTRACT(EPOCH FROM (NOW() - MIN(available_at) FILTER (WHERE delivered_at IS NULL AND available_at <= NOW())))) as oldest_age
                FROM messenger_messages 
                WHERE queue_name = :queue";
        
        $row = $this->connection->fetchAssociative($sql, ['queue' => $queueName]);
        if (!$row) {
            return ['pending' => 0, 'in_flight' => 0, 'delayed' => 0, 'oldest_age' => null];
        }

        return [
            'pending' => (int) ($row['pending'] ?? 0),
            'in_flight' => (int) ($row['in_flight'] ?? 0),
            'delayed' => (int) ($row['delayed'] ?? 0),
            'oldest_age' => isset($row['oldest_age']) && $row['oldest_age'] !== null ? (int) $row['oldest_age'] : null,
        ];
    }

    public function getFailedTransportCount(): int
    {
        $this->enforceStatementTimeout();

        $sql = "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'";
        return (int) $this->connection->fetchOne($sql);
    }

    /**
     * @return array{unpublished: int, deferred_retries: int, oldest_unpublished_age: ?int, last_published_at: ?string}
     */
    public function getOutboxMetrics(): array
    {
        $this->enforceStatementTimeout();

        $sql = "SELECT 
                COUNT(*) FILTER (WHERE published_at IS NULL) as unpublished,
                COUNT(*) FILTER (WHERE published_at IS NULL AND kind = 'defer') as deferred_retries,
                ROUND(EXTRACT(EPOCH FROM (NOW() - MIN(created_at) FILTER (WHERE published_at IS NULL)))) as oldest_unpublished_age,
                MAX(published_at) as last_published_at
                FROM import_run_outbox";

        $row = $this->connection->fetchAssociative($sql);
        if (!$row) {
            return [
                'unpublished' => 0,
                'deferred_retries' => 0,
                'oldest_unpublished_age' => null,
                'last_published_at' => null,
            ];
        }

        return [
            'unpublished' => (int) ($row['unpublished'] ?? 0),
            'deferred_retries' => (int) ($row['deferred_retries'] ?? 0),
            'oldest_unpublished_age' => isset($row['oldest_unpublished_age']) && $row['oldest_unpublished_age'] !== null ? (int) $row['oldest_unpublished_age'] : null,
            'last_published_at' => isset($row['last_published_at']) && $row['last_published_at'] !== null ? (string) $row['last_published_at'] : null,
        ];
    }

    private function enforceStatementTimeout(): void
    {
        $this->connection->executeStatement('SET LOCAL statement_timeout = 2000');
    }
}
