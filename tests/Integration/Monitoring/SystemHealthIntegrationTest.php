<?php
declare(strict_types=1);

namespace App\Tests\Integration\Monitoring;

use App\Entity\ImportRun;
use App\Entity\ImportRunOutbox;
use App\Monitoring\Repository\MonitoringQueryRepository;
use App\Monitoring\Sanitizer\MonitoringDataSanitizer;
use App\Monitoring\Service\SystemHealthMonitorService;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SystemHealthIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MonitoringQueryRepository $repository;
    private SystemHealthMonitorService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();
        $this->repository = new MonitoringQueryRepository($this->connection);
        $this->service = new SystemHealthMonitorService($this->repository, new MonitoringDataSanitizer());
    }

    public function testSystemOverviewReturnsHealthyByDefault(): void
    {
        $overview = $this->service->getSystemOverview();

        $this->assertSame('HEALTHY', $overview->status);
        $this->assertEmpty($overview->anomalies);
        $this->assertIsArray($overview->activeRuns);
        $this->assertCount(3, $overview->queueMetrics); // catalog, offers, failed
        $this->assertNotNull($overview->outboxMetrics);
    }

    public function testSystemOverviewDetectsDegradedOnFailedTransportMessages(): void
    {
        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement(
                "INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) 
                 VALUES ('{}', '[]', 'failed', NOW(), NOW())"
            );

            $overview = $this->service->getSystemOverview();

            $this->assertSame('DEGRADED', $overview->status);
            $this->assertNotEmpty($overview->anomalies);
            $this->assertStringContainsString('Failed transport contains 1 message(s)', $overview->anomalies[0]);

            $this->connection->rollBack();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function testSystemOverviewDetectsDegradedOnOutboxDeferredRetries(): void
    {
        $this->connection->beginTransaction();
        try {
            $run = new ImportRun('offers', '/tmp/offers.xml', 'hash_outbox');
            $this->em->persist($run);
            $this->em->flush();

            $outbox = new ImportRunOutbox(
                $run->getId(),
                1,
                ImportRunOutbox::KIND_DEFER,
                'idempotency_key_test_1',
                'async_offers',
                ['header' => 'val'],
                ['body' => 'val'],
                new DateTimeImmutable('+10 seconds')
            );
            $this->em->persist($outbox);
            $this->em->flush();

            $overview = $this->service->getSystemOverview();

            $this->assertSame('DEGRADED', $overview->status);
            $this->assertNotNull($overview->outboxMetrics);
            $this->assertSame(1, $overview->outboxMetrics->deferredRetryCount);
            $this->assertNotEmpty($overview->anomalies);

            $this->connection->rollBack();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function testSystemOverviewDetectsDegradedOnStaleProcessingRun(): void
    {
        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement(
                "INSERT INTO import_runs (type, filename, file_hash, status, attempt, created_at) 
                 VALUES ('offers', '/tmp/stale.xml', 'hash_stale', 'PROCESSING', 1, NOW() - INTERVAL '1000 seconds')"
            );

            $overview = $this->service->getSystemOverview();

            $this->assertSame('DEGRADED', $overview->status);
            $this->assertNotEmpty($overview->anomalies);
            $this->assertStringContainsString('Stale import run detected', $overview->anomalies[0]);

            $this->connection->rollBack();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function testSystemOverviewDetectsCriticalOnDataSourceFailure(): void
    {
        $failingRepository = new class($this->connection) extends MonitoringQueryRepository {
            public function getActiveImportRuns(): array
            {
                throw new \RuntimeException('Database unreachable or query timed out');
            }
        };

        $serviceWithFailure = new SystemHealthMonitorService($failingRepository, new MonitoringDataSanitizer());
        $overview = $serviceWithFailure->getSystemOverview();

        $this->assertSame('CRITICAL', $overview->status);
        $this->assertNotEmpty($overview->anomalies);
        $this->assertStringContainsString('Database unreachable', $overview->anomalies[0]);
    }
}
