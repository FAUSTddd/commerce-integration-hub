<?php
declare(strict_types=1);

namespace App\Tests\Integration\Monitoring;

use App\Entity\ImportRun;
use App\Monitoring\Repository\MonitoringQueryRepository;
use App\Monitoring\Sanitizer\MonitoringDataSanitizer;
use App\Monitoring\Service\SystemHealthMonitorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MonitoringIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MonitoringQueryRepository $repository;
    private SystemHealthMonitorService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repository = new MonitoringQueryRepository($this->em->getConnection());
        $this->service = new SystemHealthMonitorService($this->repository, new MonitoringDataSanitizer());
    }

    public function testIntegrationOfMonitoringComponents(): void
    {
        // 1. Arrange: Create a test record in a transaction
        $this->em->beginTransaction();
        try {
            $run = new ImportRun('catalog', '/tmp/test.xml', 'hash123');
            $run->setStatus(ImportRun::STATUS_PROCESSING);
            $this->em->persist($run);
            $this->em->flush();
            $id = $run->getId();

            // 2. Act: Query via Repository
            $runs = $this->repository->getActiveImportRuns();
            
            // 3. Assert: Repository finds it
            $found = false;
            foreach ($runs as $r) {
                if ((int)$r['id'] === $id) {
                    $found = true;
                    $this->assertSame('PROCESSING', $r['status']);
                }
            }
            $this->assertTrue($found, 'Repository failed to find the active import run');

            // 4. Act: Verify Service Aggregation
            $snapshots = $this->service->getActiveRuns();
            $this->assertGreaterThanOrEqual(1, count($snapshots));
            
            // 5. Cleanup: Ensure Isolation (rollback)
            $this->em->rollback();
        } catch (\Exception $e) {
            $this->em->rollback();
            throw $e;
        }
    }
}
