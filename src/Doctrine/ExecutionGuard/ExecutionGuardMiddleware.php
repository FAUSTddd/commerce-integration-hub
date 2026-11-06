<?php

declare(strict_types=1);

namespace App\Doctrine\ExecutionGuard;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;

final class ExecutionGuardMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ExecutionGuardState $state,
    ) {}

    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new ExecutionGuardDriver($driver, $this->state);
    }
}
