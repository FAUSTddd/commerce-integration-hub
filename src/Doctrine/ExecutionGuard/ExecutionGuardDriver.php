<?php

declare(strict_types=1);

namespace App\Doctrine\ExecutionGuard;

use App\Messenger\Exception\StaleSessionAttemptException;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

final class ExecutionGuardDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly ExecutionGuardState $state,
    ) {
        parent::__construct($driver);
    }

    public function connect(
        #[SensitiveParameter]
        array $params,
    ): DriverConnection {
        if ($this->state->isPoisoned()) {
            throw StaleSessionAttemptException::dueToPoisonedConnection();
        }

        $connection = parent::connect($params);

        return new ExecutionGuardConnection($connection, $this->state);
    }
}
