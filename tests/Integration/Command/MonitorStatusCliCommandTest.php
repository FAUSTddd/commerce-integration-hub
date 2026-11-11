<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\MonitorStatusCliCommand;
use App\Entity\ImportRun;
use App\Entity\ImportRunOutbox;
use App\Monitoring\Dto\OutboxMetricSnapshot;
use App\Monitoring\Dto\QueueMetricSnapshot;
use App\Monitoring\Dto\SystemHealthOverview;
use App\Monitoring\Repository\MonitoringQueryRepository;
use App\Monitoring\Sanitizer\MonitoringDataSanitizer;
use App\Monitoring\Service\SystemHealthMonitorService;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class MonitorStatusCliCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();
    }

    public function testExecuteOutputsHealthyTextWithExitCodeZero(): void
    {
        $application = new Application(self::$kernel);
        $command = $application->find('monitor:status');
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([]);

        $this->assertSame(MonitorStatusCliCommand::EXIT_HEALTHY_OR_DEGRADED, $exitCode);
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('[OK] Commerce Integration Hub: HEALTHY', $output);
        $this->assertStringContainsString('Queues (Doctrine Messenger):', $output);
        $this->assertStringContainsString('Outbox:', $output);
    }

    public function testExecuteOutputsJsonFormat(): void
    {
        $application = new Application(self::$kernel);
        $command = $application->find('monitor:status');
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute(['--format' => 'json']);

        $this->assertSame(MonitorStatusCliCommand::EXIT_HEALTHY_OR_DEGRADED, $exitCode);
        $output = $commandTester->getDisplay();
        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertSame('HEALTHY', $data['status']);
        $this->assertArrayHasKey('import_runs', $data);
        $this->assertArrayHasKey('queues', $data);
        $this->assertArrayHasKey('failed_transport', $data);
        $this->assertArrayHasKey('outbox', $data);
        $this->assertArrayHasKey('anomalies', $data);
    }

    public function testExecuteReturnsExitCodeZeroOnDegradedStatus(): void
    {
        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement(
                "INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) 
                 VALUES ('{}', '[]', 'failed', NOW(), NOW())"
            );

            $application = new Application(self::$kernel);
            $command = $application->find('monitor:status');
            $commandTester = new CommandTester($command);

            $exitCode = $commandTester->execute([]);

            $this->assertSame(MonitorStatusCliCommand::EXIT_HEALTHY_OR_DEGRADED, $exitCode);
            $output = $commandTester->getDisplay();
            $this->assertStringContainsString('[WARNING] Commerce Integration Hub: DEGRADED', $output);
            $this->assertStringContainsString('failed_transport: 1 messages', $output);

            $this->connection->rollBack();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function testExecuteReturnsExitCodeTwoOnCriticalStatus(): void
    {
        $failingRepository = new class($this->connection) extends MonitoringQueryRepository {
            public function getActiveImportRuns(): array
            {
                throw new \RuntimeException('Database unreachable or connection refused');
            }
        };

        $sanitizer = new MonitoringDataSanitizer();
        $service = new SystemHealthMonitorService($failingRepository, $sanitizer);
        $command = new MonitorStatusCliCommand($service, $sanitizer);

        $commandTester = new CommandTester($command);
        $exitCode = $commandTester->execute([]);

        $this->assertSame(MonitorStatusCliCommand::EXIT_CRITICAL, $exitCode);
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('[CRITICAL] Commerce Integration Hub: CRITICAL', $output);
        $this->assertStringContainsString('Database unreachable', $output);
    }

    public function testExecuteSanitizesSensitiveAnomaliesInJsonAndText(): void
    {
        $repository = new class($this->connection) extends MonitoringQueryRepository {
            public function getActiveImportRuns(): array
            {
                return [];
            }
            public function getMessengerMetrics(string $queueName): array
            {
                return ['pending' => 0, 'in_flight' => 0, 'delayed' => 0, 'oldest_age' => null];
            }
            public function getOutboxMetrics(): array
            {
                return [
                    'unpublished' => 0,
                    'deferred_retries' => 0,
                    'oldest_unpublished_age' => null,
                    'last_published_at' => null,
                ];
            }
            public function getFailedTransportCount(): int
            {
                throw new \RuntimeException('DB Error at /var/www/secret_path/config with postgresql://user:super_secret_password@db:5432/db');
            }
        };

        $sanitizer = new MonitoringDataSanitizer();
        $service = new SystemHealthMonitorService($repository, $sanitizer);
        $command = new MonitorStatusCliCommand($service, $sanitizer);

        $commandTester = new CommandTester($command);
        $exitCode = $commandTester->execute(['--format' => 'json']);

        $this->assertSame(MonitorStatusCliCommand::EXIT_CRITICAL, $exitCode);
        $output = $commandTester->getDisplay();

        $this->assertStringNotContainsString('super_secret_password', $output);
        $this->assertStringNotContainsString('/var/www/secret_path', $output);
        $this->assertStringContainsString('[REDACTED_DSN]', $output);
    }
}
