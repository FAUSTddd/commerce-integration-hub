<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\PublishOutboxMessagesCommand;
use App\Outbox\OutboxPublisher;
use App\Outbox\OutboxPublisherInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PublishOutboxMessagesCommandTest extends TestCase
{
    public function testExecuteOneShotModeDefault(): void
    {
        $dbConnection = $this->createMock(Connection::class);
        $dbConnection->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                return 3;
            });

        $publisher = new OutboxPublisher($dbConnection);

        $commandConnection = $this->createMock(Connection::class);
        $command = new PublishOutboxMessagesCommand($publisher, $commandConnection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Successfully published 3 outbox message(s)', $tester->getDisplay());
    }

    public function testExecuteOneShotWithCustomBatchSize(): void
    {
        $dbConnection = $this->createMock(Connection::class);
        $dbConnection->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                return 0;
            });

        $publisher = new OutboxPublisher($dbConnection);

        $commandConnection = $this->createMock(Connection::class);
        $command = new PublishOutboxMessagesCommand($publisher, $commandConnection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--batch-size' => 25]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No available outbox messages to publish', $tester->getDisplay());
    }

    public function testExecuteOneShotWithLimitAlias(): void
    {
        $dbConnection = $this->createMock(Connection::class);
        $dbConnection->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                return 5;
            });

        $publisher = new OutboxPublisher($dbConnection);

        $commandConnection = $this->createMock(Connection::class);
        $command = new PublishOutboxMessagesCommand($publisher, $commandConnection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--limit' => 15]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Successfully published 5 outbox message(s)', $tester->getDisplay());
    }

    public function testExecuteDaemonExitsOnMemoryLimit(): void
    {
        $dbConnection = $this->createMock(Connection::class);
        // Returns 2 (< batch-size 10), so Zero-Wait does not loop and memory check is reached
        $dbConnection->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                return 2;
            });

        $publisher = new OutboxPublisher($dbConnection);

        $commandConnection = $this->createMock(Connection::class);
        $command = new PublishOutboxMessagesCommand($publisher, $commandConnection);
        $tester = new CommandTester($command);

        // Setting memory limit to 1 byte will immediately trigger exit on first check
        $exitCode = $tester->execute([
            '--daemon' => true,
            '--batch-size' => 10,
            '--interval' => 1,
            '--memory-limit' => '1',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Memory limit of 1 reached', $tester->getDisplay());
        $this->assertStringContainsString('Outbox Publisher Daemon stopped gracefully', $tester->getDisplay());
    }

    public function testExecuteOneShotWithValidRunIdPassesArgumentToPublisher(): void
    {
        $publisher = $this->createMock(OutboxPublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publishAvailableMessages')
            ->with(50, 87)
            ->willReturn(1);

        $commandConnection = $this->createMock(Connection::class);
        $command = new PublishOutboxMessagesCommand($publisher, $commandConnection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--run-id' => '87']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Successfully published 1 outbox message(s)', $tester->getDisplay());
    }

    public function testExecuteFailsOnNonPositiveRunIdWithoutCallingPublisher(): void
    {
        $publisher = $this->createMock(OutboxPublisherInterface::class);
        $publisher->expects($this->never())->method('publishAvailableMessages');

        $commandConnection = $this->createMock(Connection::class);
        $command = new PublishOutboxMessagesCommand($publisher, $commandConnection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--run-id' => '-5']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Option --run-id must be a positive integer, got "-5"', $tester->getDisplay());
    }

    public function testExecuteFailsOnNonIntegerRunIdWithoutCallingPublisher(): void
    {
        $publisher = $this->createMock(OutboxPublisherInterface::class);
        $publisher->expects($this->never())->method('publishAvailableMessages');

        $commandConnection = $this->createMock(Connection::class);
        $command = new PublishOutboxMessagesCommand($publisher, $commandConnection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--run-id' => 'abc']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Option --run-id must be a positive integer, got "abc"', $tester->getDisplay());
    }

    public function testExecuteFailsOnZeroRunIdWithoutCallingPublisher(): void
    {
        $publisher = $this->createMock(OutboxPublisherInterface::class);
        $publisher->expects($this->never())->method('publishAvailableMessages');

        $commandConnection = $this->createMock(Connection::class);
        $command = new PublishOutboxMessagesCommand($publisher, $commandConnection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--run-id' => '0']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Option --run-id must be a positive integer, got "0"', $tester->getDisplay());
    }

    public function testExecuteDaemonPassesRunIdToPublisher(): void
    {
        $publisher = $this->createMock(OutboxPublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publishAvailableMessages')
            ->with(10, 87)
            ->willReturn(2);

        $commandConnection = $this->createMock(Connection::class);
        $command = new PublishOutboxMessagesCommand($publisher, $commandConnection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--daemon' => true,
            '--batch-size' => 10,
            '--interval' => 1,
            '--memory-limit' => '1',
            '--run-id' => '87',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Run ID: 87', $tester->getDisplay());
        $this->assertStringContainsString('Outbox Publisher Daemon stopped gracefully', $tester->getDisplay());
    }
}
