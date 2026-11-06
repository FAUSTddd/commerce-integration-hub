<?php

declare(strict_types=1);

namespace App\Doctrine\ExecutionGuard;

use App\Messenger\Exception\StaleSessionAttemptException;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Exception\ConnectionLost;
use Throwable;

final class ExecutionGuardConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        DriverConnection $connection,
        private readonly ExecutionGuardState $state,
    ) {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        $this->checkState();

        try {
            return parent::prepare($sql);
        } catch (Throwable $e) {
            $this->handlePotentialConnectionLoss($e);
            throw $e;
        }
    }

    public function query(string $sql): Result
    {
        $this->checkState();

        try {
            return parent::query($sql);
        } catch (Throwable $e) {
            $this->handlePotentialConnectionLoss($e);
            throw $e;
        }
    }

    public function exec(string $sql): int|string
    {
        $this->checkState();

        try {
            return parent::exec($sql);
        } catch (Throwable $e) {
            $this->handlePotentialConnectionLoss($e);
            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        $this->checkState();

        try {
            parent::beginTransaction();
        } catch (Throwable $e) {
            $this->handlePotentialConnectionLoss($e);
            throw $e;
        }
    }

    public function commit(): void
    {
        $this->checkState();

        try {
            parent::commit();
        } catch (Throwable $e) {
            $this->handlePotentialConnectionLoss($e);
            throw $e;
        }
    }

    public function rollBack(): void
    {
        // Don't fail if already poisoned during rollback attempt
        if ($this->state->isPoisoned()) {
            return;
        }

        try {
            parent::rollBack();
        } catch (Throwable $e) {
            $this->handlePotentialConnectionLoss($e);
            throw $e;
        }
    }

    private function checkState(): void
    {
        if ($this->state->isPoisoned()) {
            throw StaleSessionAttemptException::dueToPoisonedConnection();
        }
    }

    private function handlePotentialConnectionLoss(Throwable $e): void
    {
        if ($e instanceof ConnectionLost || str_contains(strtolower($e->getMessage()), 'connection lost') || str_contains(strtolower($e->getMessage()), 'server closed the connection unexpectedly') || str_contains(strtolower($e->getMessage()), 'terminating connection due to administrator command')) {
            $this->state->poisonCurrentEpoch();
        }
    }
}
