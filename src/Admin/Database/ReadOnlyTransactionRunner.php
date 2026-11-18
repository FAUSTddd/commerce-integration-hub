<?php

declare(strict_types=1);

namespace App\Admin\Database;

use Doctrine\DBAL\Connection;
use Throwable;

final readonly class ReadOnlyTransactionRunner
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Executes the given callable strictly inside a PostgreSQL READ ONLY transaction.
     * Guaranteed lifecycle: BEGIN -> SET TRANSACTION READ ONLY -> callable -> COMMIT / ROLLBACK.
     * Prohibits executing queries prior to setting transaction characteristics.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     * @throws Throwable
     */
    public function run(callable $operation): mixed
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement('SET TRANSACTION READ ONLY');
            $result = $operation();
            $this->connection->commit();

            return $result;
        } catch (Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }
}
