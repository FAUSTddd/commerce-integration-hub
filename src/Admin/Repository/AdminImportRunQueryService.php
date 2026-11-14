<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Admin\Database\ReadOnlyTransactionRunner;
use App\Admin\Dto\ImportRunDetailView;
use App\Admin\Dto\ImportRunItemView;
use App\Admin\Dto\OutboxEventItemView;
use App\Admin\Dto\PaginatedResult;
use App\Monitoring\Sanitizer\MonitoringDataSanitizer;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

class AdminImportRunQueryService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ReadOnlyTransactionRunner $transactionRunner,
        private readonly MonitoringDataSanitizer $sanitizer,
    ) {
    }

    /**
     * @return PaginatedResult<ImportRunItemView>
     */
    public function findPaginatedRuns(int $page = 1, int $limit = 20, ?string $status = null, ?string $type = null): PaginatedResult
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $offset = ($page - 1) * $limit;

        return $this->transactionRunner->run(function () use ($page, $limit, $offset, $status, $type): PaginatedResult {
            $whereParts = [];
            $params = [];

            if ($status !== null && $status !== '') {
                $whereParts[] = 'status = :status';
                $params['status'] = $status;
            }

            if ($type !== null && $type !== '') {
                $whereParts[] = 'type = :type';
                $params['type'] = $type;
            }

            $whereSql = '';
            if (!empty($whereParts)) {
                $whereSql = 'WHERE ' . implode(' AND ', $whereParts);
            }

            // 1. Efficient count over indexed columns
            $countSql = "SELECT COUNT(id) FROM import_runs {$whereSql}";
            $totalItems = (int) $this->connection->fetchOne($countSql, $params);
            $totalPages = (int) ceil($totalItems / $limit);
            if ($totalPages === 0) {
                $totalPages = 1;
            }

            if ($totalItems === 0) {
                return new PaginatedResult([], $page, $limit, 0, 1);
            }

            // 2. Fetch page using index on created_at DESC, id DESC
            $sql = <<<SQL
                SELECT id, type, filename, status, attempt, items_count, created_at, started_at, finished_at, error_message
                FROM import_runs
                {$whereSql}
                ORDER BY created_at DESC, id DESC
                LIMIT :limit OFFSET :offset
            SQL;

            $params['limit'] = $limit;
            $params['offset'] = $offset;

            $rows = $this->connection->fetchAllAssociative($sql, $params);

            $items = [];
            foreach ($rows as $row) {
                $items[] = new ImportRunItemView(
                    (int) $row['id'],
                    (string) $row['type'],
                    (string) $row['filename'],
                    (string) $row['status'],
                    (int) $row['attempt'],
                    (int) $row['items_count'],
                    new DateTimeImmutable((string) $row['created_at']),
                    $row['started_at'] !== null ? new DateTimeImmutable((string) $row['started_at']) : null,
                    $row['finished_at'] !== null ? new DateTimeImmutable((string) $row['finished_at']) : null,
                    $row['error_message'] !== null && $row['error_message'] !== '',
                );
            }

            return new PaginatedResult($items, $page, $limit, $totalItems, $totalPages);
        });
    }

    public function findRunDetail(int $id): ?ImportRunDetailView
    {
        return $this->transactionRunner->run(function () use ($id): ?ImportRunDetailView {
            $sql = <<<SQL
                SELECT id, type, filename, file_hash, status, attempt, fencing_token,
                       items_count, created_at, started_at, finished_at, retry_scheduled_at,
                       has_deferred_retry, error_message
                FROM import_runs
                WHERE id = :id
            SQL;

            $runRow = $this->connection->fetchAssociative($sql, ['id' => $id]);
            if ($runRow === false) {
                return null;
            }

            $outboxSql = <<<SQL
                SELECT id, attempt, kind, idempotency_key, queue_name, available_at, published_at, acked_at, created_at
                FROM import_run_outbox
                WHERE run_id = :id
                ORDER BY created_at DESC, id DESC
            SQL;

            $outboxRows = $this->connection->fetchAllAssociative($outboxSql, ['id' => $id]);

            $outboxEvents = [];
            foreach ($outboxRows as $oRow) {
                $outboxEvents[] = new OutboxEventItemView(
                    (int) $oRow['id'],
                    (int) $oRow['attempt'],
                    (string) $oRow['kind'],
                    (string) $oRow['idempotency_key'],
                    (string) $oRow['queue_name'],
                    new DateTimeImmutable((string) $oRow['available_at']),
                    $oRow['published_at'] !== null ? new DateTimeImmutable((string) $oRow['published_at']) : null,
                    $oRow['acked_at'] !== null ? new DateTimeImmutable((string) $oRow['acked_at']) : null,
                    new DateTimeImmutable((string) $oRow['created_at']),
                );
            }

            $rawError = $runRow['error_message'] !== null ? (string) $runRow['error_message'] : null;
            $sanitizedError = $this->sanitizer->sanitizeError($rawError);

            return new ImportRunDetailView(
                (int) $runRow['id'],
                (string) $runRow['type'],
                (string) $runRow['filename'],
                (string) $runRow['file_hash'],
                (string) $runRow['status'],
                (int) $runRow['attempt'],
                $runRow['fencing_token'] !== null ? (string) $runRow['fencing_token'] : null,
                (int) $runRow['items_count'],
                new DateTimeImmutable((string) $runRow['created_at']),
                $runRow['started_at'] !== null ? new DateTimeImmutable((string) $runRow['started_at']) : null,
                $runRow['finished_at'] !== null ? new DateTimeImmutable((string) $runRow['finished_at']) : null,
                $runRow['retry_scheduled_at'] !== null ? new DateTimeImmutable((string) $runRow['retry_scheduled_at']) : null,
                (bool) $runRow['has_deferred_retry'],
                $sanitizedError,
                $outboxEvents,
            );
        });
    }
}
