<?php

declare(strict_types=1);

namespace App\Tests\Unit\Outbox;

use App\Outbox\OutboxPublisher;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\TestCase;

final class OutboxPublisherRunIdQueryTest extends TestCase
{
    public function testQueryIncludesRunIdFilterAndTypesWhenForRunIdProvided(): void
    {
        $limit = 20;
        $forRunId = 87;

        $dbConnection = $this->createMock(Connection::class);
        $dbConnection->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(fn(callable $callback) => $callback());

        $dbConnection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                $this->callback(function (string $sql): bool {
                    $this->assertStringContainsString('AND run_id = :forRunId', $sql);
                    $this->assertStringContainsString('FOR UPDATE SKIP LOCKED', $sql);
                    $this->assertStringContainsString('LIMIT :limit', $sql);
                    return true;
                }),
                $this->identicalTo([
                    'limit' => $limit,
                    'forRunId' => $forRunId,
                ]),
                $this->identicalTo([
                    'limit' => ParameterType::INTEGER,
                    'forRunId' => ParameterType::INTEGER,
                ])
            )
            ->willReturn([]);

        $publisher = new OutboxPublisher($dbConnection);
        $result = $publisher->publishAvailableMessages($limit, $forRunId);

        $this->assertSame(0, $result);
    }

    public function testQueryOmitsRunIdFilterAndTypesWhenForRunIdIsNull(): void
    {
        $limit = 15;

        $dbConnection = $this->createMock(Connection::class);
        $dbConnection->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(fn(callable $callback) => $callback());

        $dbConnection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                $this->callback(function (string $sql): bool {
                    $this->assertStringNotContainsString(':forRunId', $sql);
                    $this->assertStringNotContainsString('run_id =', $sql);
                    $this->assertStringContainsString('FOR UPDATE SKIP LOCKED', $sql);
                    $this->assertStringContainsString('LIMIT :limit', $sql);
                    return true;
                }),
                $this->identicalTo([
                    'limit' => $limit,
                ]),
                $this->identicalTo([
                    'limit' => ParameterType::INTEGER,
                ])
            )
            ->willReturn([]);

        $publisher = new OutboxPublisher($dbConnection);
        $result = $publisher->publishAvailableMessages($limit, null);

        $this->assertSame(0, $result);
    }
}
