<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\ImportRun;
use App\Repository\ImportRunRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdvisoryLockCasIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private ImportRunRepository $repository;

    /** @var int[] */
    private array $createdRunIds = [];

    /** @var Connection[] */
    private array $extraConnections = [];

    private string $testRunSuffix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
        $this->repository = $this->em->getRepository(ImportRun::class);
        $this->createdRunIds = [];
        $this->extraConnections = [];
        $this->testRunSuffix = bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->conn->isConnected()) {
            foreach ($this->createdRunIds as $runId) {
                try {
                    $this->conn->executeStatement('SELECT pg_advisory_unlock(:id)', ['id' => $runId]);
                } catch (\Throwable) {
                }
            }
        }

        foreach ($this->extraConnections as $extraConn) {
            if ($extraConn->isConnected()) {
                foreach ($this->createdRunIds as $runId) {
                    try {
                        $extraConn->executeStatement('SELECT pg_advisory_unlock(:id)', ['id' => $runId]);
                    } catch (\Throwable) {
                    }
                }
                try {
                    $extraConn->close();
                } catch (\Throwable) {
                }
            }
        }

        if (!empty($this->createdRunIds) && $this->conn->isConnected()) {
            try {
                $this->conn->executeStatement(
                    'DELETE FROM import_run_outbox WHERE run_id IN (:ids)',
                    ['ids' => $this->createdRunIds],
                    ['ids' => ArrayParameterType::INTEGER]
                );
                $this->conn->executeStatement(
                    'DELETE FROM import_runs WHERE id IN (:ids)',
                    ['ids' => $this->createdRunIds],
                    ['ids' => ArrayParameterType::INTEGER]
                );
            } catch (\Throwable) {
            }
        }

        parent::tearDown();
    }

    public function testSessionAdvisoryLockLifecycle(): void
    {
        $fileName = 'test_lock_' . $this->testRunSuffix . '.xml';
        $fileHash = 'hash1_' . $this->testRunSuffix;
        $runId = $this->repository->tryCreateActiveRun('catalog', $fileName, $fileHash);
        $this->assertNotNull($runId);
        $this->createdRunIds[] = $runId;

        // Acquire lock
        $acquired = $this->repository->tryAcquireSessionAdvisoryLock($runId);
        $this->assertTrue($acquired);

        // Same session re-entrant check in PG returns true
        $reacquired = $this->repository->tryAcquireSessionAdvisoryLock($runId);
        $this->assertTrue($reacquired);

        // Release lock
        $this->repository->releaseSessionAdvisoryLock($runId);
        $this->repository->releaseSessionAdvisoryLock($runId);
    }

    public function testCas2ClaimTransitionsPendingToProcessing(): void
    {
        $fileName = 'test_claim_' . $this->testRunSuffix . '.xml';
        $fileHash = 'hash2_' . $this->testRunSuffix;
        $runId = $this->repository->tryCreateActiveRun('catalog', $fileName, $fileHash);
        $this->assertNotNull($runId);
        $this->createdRunIds[] = $runId;

        $token = 'a0000000-0000-0000-0000-000000000001';
        $claimed = $this->repository->claimRunCas2($runId, $token);
        $this->assertTrue($claimed);

        $run = $this->repository->find($runId);
        $this->assertNotNull($run);
        $this->assertSame(ImportRun::STATUS_PROCESSING, $run->getStatus());
        $this->assertSame(1, $run->getAttempt());
        $this->assertSame($token, $run->getFencingToken());
        $this->assertNotNull($run->getStartedAt());
    }

    public function testCas5RecoveryMutualExclusionWithActiveLock(): void
    {
        $fileName = 'test_recovery_' . $this->testRunSuffix . '.xml';
        $fileHash = 'hash3_' . $this->testRunSuffix;
        $runId = $this->repository->tryCreateActiveRun('catalog', $fileName, $fileHash);
        $this->assertNotNull($runId);
        $this->createdRunIds[] = $runId;

        $token = 'b0000000-0000-0000-0000-000000000002';
        $claimed = $this->repository->claimRunCas2($runId, $token);
        $this->assertTrue($claimed);

        // 1. When session lock is held by worker
        $locked = $this->repository->tryAcquireSessionAdvisoryLock($runId);
        $this->assertTrue($locked);

        // CAS5 on separate connection must be blocked by transaction-level lock
        $params = $this->conn->getParams();
        $conn2 = \Doctrine\DBAL\DriverManager::getConnection($params);
        $this->extraConnections[] = $conn2;

        try {
            $cas5Success = (bool) $conn2->transactional(function () use ($conn2, $runId, $token): bool {
                $lockAcquired = (bool) $conn2->fetchOne('SELECT pg_try_advisory_xact_lock(:id)', ['id' => $runId]);
                if (!$lockAcquired) {
                    return false;
                }

                return $conn2->executeStatement(
                    "UPDATE import_runs SET status = 'FAILED' WHERE id = :id AND fencing_token = :token::uuid",
                    ['id' => $runId, 'token' => $token]
                ) === 1;
            });

            $this->assertFalse($cas5Success, 'CAS5 must fail while session advisory lock is held');

            // Release worker lock
            $this->repository->releaseSessionAdvisoryLock($runId);

            // Now CAS5 on conn2 must succeed
            $cas5SuccessAfterRelease = (bool) $conn2->transactional(function () use ($conn2, $runId, $token): bool {
                $lockAcquired = (bool) $conn2->fetchOne('SELECT pg_try_advisory_xact_lock(:id)', ['id' => $runId]);
                if (!$lockAcquired) {
                    return false;
                }

                return $conn2->executeStatement(
                    "UPDATE import_runs SET status = 'FAILED', fencing_token = NULL, finished_at = NOW() WHERE id = :id AND fencing_token = :token::uuid",
                    ['id' => $runId, 'token' => $token]
                ) === 1;
            });

            $this->assertTrue($cas5SuccessAfterRelease, 'CAS5 must succeed when session advisory lock is released');

            $this->em->clear();
            $run = $this->repository->find($runId);
            $this->assertSame(ImportRun::STATUS_FAILED, $run->getStatus());
            $this->assertNull($run->getFencingToken());
        } finally {
            $conn2->close();
        }
    }

    public function testCas3RollbackDmlOnFencingTokenMismatch(): void
    {
        $fileName = 'test_rollback_' . $this->testRunSuffix . '.xml';
        $fileHash = 'hash5_' . $this->testRunSuffix;
        $runId = $this->repository->tryCreateActiveRun('catalog', $fileName, $fileHash);
        $this->assertNotNull($runId);
        $this->createdRunIds[] = $runId;

        $token = 'c0000000-0000-0000-0000-000000000005';
        $claimed = $this->repository->claimRunCas2($runId, $token);
        $this->assertTrue($claimed);

        // Simulate Handler execution with DML insert followed by CAS3 with mismatched token
        $failedCas3 = false;
        try {
            $this->conn->transactional(function () use ($runId): void {
                // Insert a product
                $this->conn->executeStatement(
                    "INSERT INTO products (external_id, name, created_at, updated_at) VALUES ('PROD-TEST-1', 'Product 1', NOW(), NOW())"
                );

                // Execute CAS3 with wrong token
                $rows = $this->conn->executeStatement(
                    "UPDATE import_runs
                     SET status = 'SUCCESS', fencing_token = NULL, finished_at = NOW()
                     WHERE id = :id AND status = 'PROCESSING' AND fencing_token = :token::uuid",
                    ['id' => $runId, 'token' => 'f0000000-0000-0000-0000-000000000000']
                );

                if ($rows === 0) {
                    throw new \RuntimeException('CAS3 mismatch: rollback expected');
                }
            });
        } catch (\RuntimeException $e) {
            $failedCas3 = true;
        }

        $this->assertTrue($failedCas3);

        // Product DML must be completely rolled back
        $productCount = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM products WHERE external_id = 'PROD-TEST-1'"
        );
        $this->assertSame(0, $productCount, 'DML must be rolled back on CAS3 mismatch');

        // ImportRun remains in PROCESSING
        $this->em->clear();
        $run = $this->repository->find($runId);
        $this->assertSame(ImportRun::STATUS_PROCESSING, $run->getStatus());
        $this->assertSame($token, $run->getFencingToken());
    }

    public function testExecutionGuardPoisonOnConnectionLostAndBlockReconnect(): void
    {
        $guardState = new \App\Doctrine\ExecutionGuard\ExecutionGuardState();
        $guardState->startEpoch('test-epoch-1');

        $driver = new \Doctrine\DBAL\Driver\PDO\PgSQL\Driver();
        $guardDriver = new \App\Doctrine\ExecutionGuard\ExecutionGuardDriver($driver, $guardState);

        // Poison epoch manually (simulating ConnectionLost)
        $guardState->poisonCurrentEpoch();
        $this->assertTrue($guardState->isPoisoned());

        // Subsequent reconnect must throw StaleSessionAttemptException
        $this->expectException(\App\Messenger\Exception\StaleSessionAttemptException::class);
        $guardDriver->connect($this->conn->getParams());
    }
}
