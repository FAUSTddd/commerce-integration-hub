<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin\Database;

use App\Admin\Database\ReadOnlyTransactionRunner;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReadOnlyTransactionRunnerTest extends TestCase
{
    public function testExecutesCallableInsideReadOnlyTransactionLifecycle(): void
    {
        $connection = $this->createMock(Connection::class);

        $executionOrder = [];

        $connection->expects(self::once())
            ->method('beginTransaction')
            ->willReturnCallback(function () use (&$executionOrder): void {
                $executionOrder[] = 'beginTransaction';
            });

        $connection->expects(self::once())
            ->method('executeStatement')
            ->with('SET TRANSACTION READ ONLY')
            ->willReturnCallback(function (string $sql) use (&$executionOrder): int {
                $executionOrder[] = $sql;
                return 0;
            });

        $connection->expects(self::once())
            ->method('commit')
            ->willReturnCallback(function () use (&$executionOrder): void {
                $executionOrder[] = 'commit';
            });

        $connection->expects(self::never())
            ->method('rollBack');

        $runner = new ReadOnlyTransactionRunner($connection);

        $result = $runner->run(function () use (&$executionOrder): string {
            $executionOrder[] = 'user_callable';
            return 'expected_data';
        });

        self::assertSame('expected_data', $result);
        self::assertSame([
            'beginTransaction',
            'SET TRANSACTION READ ONLY',
            'user_callable',
            'commit',
        ], $executionOrder);
    }

    public function testRollsBackAndRethrowsExceptionWhenCallableFails(): void
    {
        $connection = $this->createMock(Connection::class);

        $executionOrder = [];

        $connection->expects(self::once())
            ->method('beginTransaction')
            ->willReturnCallback(function () use (&$executionOrder): void {
                $executionOrder[] = 'beginTransaction';
            });

        $connection->expects(self::once())
            ->method('executeStatement')
            ->with('SET TRANSACTION READ ONLY')
            ->willReturnCallback(function (string $sql) use (&$executionOrder): int {
                $executionOrder[] = $sql;
                return 0;
            });

        $connection->expects(self::never())
            ->method('commit');

        $connection->expects(self::once())
            ->method('rollBack')
            ->willReturnCallback(function () use (&$executionOrder): void {
                $executionOrder[] = 'rollBack';
            });

        $runner = new ReadOnlyTransactionRunner($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Query failed during read-only operation');

        try {
            $runner->run(function (): void {
                throw new RuntimeException('Query failed during read-only operation');
            });
        } finally {
            self::assertSame([
                'beginTransaction',
                'SET TRANSACTION READ ONLY',
                'rollBack',
            ], $executionOrder);
        }
    }
}
