<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\ImportRun;
use App\Messenger\ImportRunStamp;
use App\Repository\ImportRunRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

final class RecoverStaleRunsCliCommandTest extends KernelTestCase
{
    private ImportRunRepository $importRunRepository;
    private Application $application;
    private Connection $connection;
    private PhpSerializer $serializer;

    private string $testRunSuffix;

    /**
     * @var list<int>
     */
    private array $initialQueueMessageIds = [];

    /**
     * @var list<int>
     */
    private array $baselineGuardMessageIds = [];

    /**
     * @var list<int>
     */
    private array $createdRunIds = [];

    /**
     * @var list<int>
     */
    private array $createdMessageIds = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $env = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null;
        if ($env !== 'test') {
            throw new \RuntimeException('ABORT: Test must run with APP_ENV=test. Current: ' . (string) $env);
        }

        $container = static::getContainer();
        $this->importRunRepository = $container->get(ImportRunRepository::class);
        $this->application = new Application(static::$kernel);
        $this->connection = $container->get(Connection::class);
        $this->serializer = new PhpSerializer();

        $dbName = $this->connection->getDatabase();
        if ($dbName !== 'commerce_db_test') {
            throw new \RuntimeException('ABORT: Test DB must be commerce_db_test. Current DB: ' . (string) $dbName);
        }

        $this->testRunSuffix = bin2hex(random_bytes(6));
        $this->createdRunIds = [];
        $this->createdMessageIds = [];
        $this->baselineGuardMessageIds = [];

        // Snapshot existing baseline message IDs
        $rawInitialIds = $this->connection->fetchFirstColumn('SELECT id FROM messenger_messages');
        $this->initialQueueMessageIds = array_map('intval', $rawInitialIds);

        // Snapshot existing PROCESSING runs before any test-created runs exist.
        // Protect only this baseline, never IDs that a test may allocate later.
        $baselineRunIds = $this->connection->fetchFirstColumn(
            'SELECT id FROM import_runs WHERE status = :status',
            ['status' => ImportRun::STATUS_PROCESSING]
        );
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        foreach ($baselineRunIds as $baselineRunId) {
            $envelope = new Envelope(new \stdClass(), [new ImportRunStamp((int) $baselineRunId)]);
            $encoded = $this->serializer->encode($envelope);

            $id = $this->connection->fetchOne(
                "INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at, delivered_at)
                 VALUES (:body, :headers, 'async_catalog', :created, :available, :delivered)
                 RETURNING id",
                [
                    'body' => $encoded['body'],
                    'headers' => json_encode($encoded['headers'] ?? []),
                    'created' => $now,
                    'available' => $now,
                    'delivered' => $now,
                ]
            );
            $this->baselineGuardMessageIds[] = (int) $id;
        }
    }

    protected function tearDown(): void
    {
        $cleanupException = null;
        $parentException = null;

        try {
            if ($this->connection->isConnected()) {
                // 1. Remove baseline guard messages
                if (!empty($this->baselineGuardMessageIds)) {
                    $this->connection->executeStatement(
                        'DELETE FROM messenger_messages WHERE id IN (:ids)',
                        ['ids' => $this->baselineGuardMessageIds],
                        ['ids' => ArrayParameterType::INTEGER]
                    );
                    $this->baselineGuardMessageIds = [];
                }

                // 2. Remove test-created messenger messages
                if (!empty($this->createdMessageIds)) {
                    $this->connection->executeStatement(
                        'DELETE FROM messenger_messages WHERE id IN (:ids)',
                        ['ids' => $this->createdMessageIds],
                        ['ids' => ArrayParameterType::INTEGER]
                    );
                    $this->createdMessageIds = [];
                }

                // 3. Remove test-created outbox records (if any were produced)
                if (!empty($this->createdRunIds)) {
                    $this->connection->executeStatement(
                        'DELETE FROM import_run_outbox WHERE run_id IN (:runIds)',
                        ['runIds' => $this->createdRunIds],
                        ['runIds' => ArrayParameterType::INTEGER]
                    );

                    // 4. Remove test-created import runs
                    $this->connection->executeStatement(
                        'DELETE FROM import_runs WHERE id IN (:runIds)',
                        ['runIds' => $this->createdRunIds],
                        ['runIds' => ArrayParameterType::INTEGER]
                    );
                    $this->createdRunIds = [];
                }
            }
        } catch (\Throwable $e) {
            $cleanupException = $e;
        } finally {
            try {
                parent::tearDown();
            } catch (\Throwable $e) {
                $parentException = $e;
            }

            if ($cleanupException !== null && $parentException !== null) {
                throw new \RuntimeException(
                    sprintf("Multiple tearDown failures:
Cleanup: %s
Parent: %s", $cleanupException->getMessage(), $parentException->getMessage()),
                    0,
                    $cleanupException
                );
            }
            if ($cleanupException !== null) {
                throw $cleanupException;
            }
            if ($parentException !== null) {
                throw $parentException;
            }
        }
    }

    public function testDryRunDoesNotModifyDatabase(): void
    {
        $filename = 'stale_test_' . $this->testRunSuffix . '.xml';
        $staleRun = new ImportRun('catalog', $filename, 'hash1');
        $staleRun->setStatus(ImportRun::STATUS_PROCESSING);
        $staleRun->setStartedAt(new \DateTimeImmutable('-3 hours'));
        $this->importRunRepository->save($staleRun);
        $staleId = (int) $staleRun->getId();
        $this->createdRunIds[] = $staleId;

        $command = $this->application->find('app:cml:recover-stale-runs');
        $tester = new CommandTester($command);

        // Dry run
        $tester->execute(['--dry-run' => true]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('[DRY-RUN] Found 1 candidate run(s) eligible for recovery', $tester->getDisplay());

        $runAfterDry = $this->importRunRepository->find($staleId);
        $this->assertNotNull($runAfterDry);
        $this->assertSame(ImportRun::STATUS_PROCESSING, $runAfterDry->getStatus());
        $this->assertNull($runAfterDry->getFinishedAt());
    }

    public function testNonInteractiveExecutionWithoutForceFailsAndDoesNotModifyState(): void
    {
        $filename = 'stale_test_' . $this->testRunSuffix . '.xml';
        $staleRun = new ImportRun('catalog', $filename, 'hash1');
        $staleRun->setStatus(ImportRun::STATUS_PROCESSING);
        $staleRun->setStartedAt(new \DateTimeImmutable('-3 hours'));
        $this->importRunRepository->save($staleRun);
        $staleId = (int) $staleRun->getId();
        $this->createdRunIds[] = $staleId;

        $command = $this->application->find('app:cml:recover-stale-runs');
        $tester = new CommandTester($command);

        // Без флага --force в неинтерактивном тесте должно падать с ошибкой и не менять БД
        $tester->execute([], ['interactive' => false]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Non-interactive execution requires the --force (-f) flag', $tester->getDisplay());

        $reloaded = $this->importRunRepository->find($staleId);
        $this->assertSame(ImportRun::STATUS_PROCESSING, $reloaded?->getStatus());
    }

    public function testForcedRecoveryMarksStaleRunsFailedAndLeavesFreshUntouched(): void
    {
        // 1. Создаём зависший run старше 3 часов без активных сообщений
        $staleFilename = 'stale_test_' . $this->testRunSuffix . '.xml';
        $staleRun = new ImportRun('catalog', $staleFilename, 'hash1');
        $staleRun->setStatus(ImportRun::STATUS_PROCESSING);
        $staleRun->setStartedAt(new \DateTimeImmutable('-3 hours'));
        $this->importRunRepository->save($staleRun);
        $staleId = (int) $staleRun->getId();
        $this->createdRunIds[] = $staleId;

        // 2. Создаём свежий run (30 минут назад)
        $freshFilename = 'fresh_test_' . $this->testRunSuffix . '.xml';
        $freshRun = new ImportRun('offers', $freshFilename, 'hash2');
        $freshRun->setStatus(ImportRun::STATUS_PROCESSING);
        $freshRun->setStartedAt(new \DateTimeImmutable('-30 minutes'));
        $this->importRunRepository->save($freshRun);
        $freshId = (int) $freshRun->getId();
        $this->createdRunIds[] = $freshId;

        $command = $this->application->find('app:cml:recover-stale-runs');
        $tester = new CommandTester($command);

        // Применяем принудительное восстановление с --force
        $tester->execute(['--force' => true], ['interactive' => false]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Successfully marked 1 stale run(s) as FAILED', $tester->getDisplay());

        $reloadedStale = $this->importRunRepository->find($staleId);
        $this->assertNotNull($reloadedStale);
        $this->assertSame(ImportRun::STATUS_FAILED, $reloadedStale->getStatus());
        $this->assertNotNull($reloadedStale->getFinishedAt());
        $this->assertStringContainsString('stuck in PROCESSING', (string) $reloadedStale->getErrorMessage());

        $reloadedFresh = $this->importRunRepository->find($freshId);
        $this->assertNotNull($reloadedFresh);
        $this->assertSame(ImportRun::STATUS_PROCESSING, $reloadedFresh->getStatus());
    }

    public function testInteractiveConfirmationAbortedLeavesStateIntact(): void
    {
        $filename = 'stale_abort_' . $this->testRunSuffix . '.xml';
        $staleRun = new ImportRun('catalog', $filename, 'hash_abort');
        $staleRun->setStatus(ImportRun::STATUS_PROCESSING);
        $staleRun->setStartedAt(new \DateTimeImmutable('-3 hours'));
        $this->importRunRepository->save($staleRun);
        $staleId = (int) $staleRun->getId();
        $this->createdRunIds[] = $staleId;

        $command = $this->application->find('app:cml:recover-stale-runs');
        $tester = new CommandTester($command);

        // Имитируем ввод "no" в интерактивном режиме
        $tester->setInputs(['no']);
        $tester->execute([], ['interactive' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Operation cancelled by operator. No records modified.', $tester->getDisplay());

        $reloaded = $this->importRunRepository->find($staleId);
        $this->assertSame(ImportRun::STATUS_PROCESSING, $reloaded?->getStatus());
    }

    public function testMessageWithinRedeliverTimeoutProtectsAgainstRecovery(): void
    {
        // Создаём длительный run (> 3 часов)
        $filename = 'long_running_worker_' . $this->testRunSuffix . '.xml';
        $run = new ImportRun('catalog', $filename, 'hash_long');
        $run->setStatus(ImportRun::STATUS_PROCESSING);
        $run->setStartedAt(new \DateTimeImmutable('-3 hours'));
        $this->importRunRepository->save($run);
        $runId = (int) $run->getId();
        $this->createdRunIds[] = $runId;

        // Сообщение находится в процессе обработки с delivered_at 15 минут назад (< 1 час redeliver_timeout)
        $msg = new \stdClass();
        $msg->test = true;
        $envelope = new Envelope($msg, [new ImportRunStamp($runId)]);
        $encoded = $this->serializer->encode($envelope);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $fifteenMinutesAgo = $now->modify('-15 minutes');

        $msgId = $this->connection->fetchOne(
            'INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at, delivered_at)
             VALUES (:body, :headers, :queue, :created, :available, :delivered)
             RETURNING id',
            [
                'body' => $encoded['body'],
                'headers' => json_encode($encoded['headers'] ?? []),
                'queue' => 'async_catalog',
                'created' => $now->format('Y-m-d H:i:s'),
                'available' => $now->format('Y-m-d H:i:s'),
                'delivered' => $fifteenMinutesAgo->format('Y-m-d H:i:s'),
            ]
        );
        $this->createdMessageIds[] = (int) $msgId;

        $command = $this->application->find('app:cml:recover-stale-runs');
        $tester = new CommandTester($command);
        $tester->execute(['--force' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('is within Doctrine worker redelivery window (< 1h). Skipping premature recovery', $tester->getDisplay());

        // Запуск не должен быть переведён в FAILED
        $reloaded = $this->importRunRepository->find($runId);
        $this->assertSame(ImportRun::STATUS_PROCESSING, $reloaded?->getStatus());
    }

    public function testMessageInQueueWithoutDeliveredAtDoesNotBlockRecovery(): void
    {
        // Создаём старый run (> 3 часов)
        $filename = 'unprocessed_queue_msg_' . $this->testRunSuffix . '.xml';
        $run = new ImportRun('catalog', $filename, 'hash_queue');
        $run->setStatus(ImportRun::STATUS_PROCESSING);
        $run->setStartedAt(new \DateTimeImmutable('-3 hours'));
        $this->importRunRepository->save($run);
        $runId = (int) $run->getId();
        $this->createdRunIds[] = $runId;

        // Сообщение находится в очереди, НО delivered_at IS NULL (воркер не брал его)
        $msg = new \stdClass();
        $envelope = new Envelope($msg, [new ImportRunStamp($runId)]);
        $encoded = $this->serializer->encode($envelope);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $twoHoursAgo = $now->modify('-2 hours');
        $msgId = $this->connection->fetchOne(
            'INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at, delivered_at)
             VALUES (:body, :headers, :queue, :created, :available, NULL)
             RETURNING id',
            [
                'body' => $encoded['body'],
                'headers' => json_encode($encoded['headers'] ?? []),
                'queue' => 'catalog',
                'created' => $twoHoursAgo->format('Y-m-d H:i:s'),
                'available' => $twoHoursAgo->format('Y-m-d H:i:s'),
            ]
        );
        $this->createdMessageIds[] = (int) $msgId;

        $command = $this->application->find('app:cml:recover-stale-runs');
        $tester = new CommandTester($command);
        $tester->execute(['--force' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Successfully marked 1 stale run(s) as FAILED', $tester->getDisplay());

        $reloaded = $this->importRunRepository->find($runId);
        $this->assertSame(ImportRun::STATUS_FAILED, $reloaded?->getStatus());
    }

    public function testMessageWithDifferentRunIdInBodyDoesNotProtectTargetRun(): void
    {
        $filename = 'target_run_' . $this->testRunSuffix . '.xml';
        $targetRun = new ImportRun('catalog', $filename, 'hash_target');
        $targetRun->setStatus(ImportRun::STATUS_PROCESSING);
        $targetRun->setStartedAt(new \DateTimeImmutable('-3 hours'));
        $this->importRunRepository->save($targetRun);
        $targetId = (int) $targetRun->getId();
        $this->createdRunIds[] = $targetId;

        // Создаём сообщение в messenger_messages, содержащее ImportRunStamp с ID = 12000 + targetId
        $differentRunId = 12000 + $targetId;
        $msg = new \stdClass();
        $envelope = new Envelope($msg, [new ImportRunStamp($differentRunId)]);
        $encoded = $this->serializer->encode($envelope);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $msgId = $this->connection->fetchOne(
            'INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at, delivered_at)
             VALUES (:body, :headers, :queue, :created, :available, :delivered)
             RETURNING id',
            [
                'body' => $encoded['body'],
                'headers' => json_encode($encoded['headers'] ?? []),
                'queue' => 'async_catalog',
                'created' => $now->format('Y-m-d H:i:s'),
                'available' => $now->format('Y-m-d H:i:s'),
                'delivered' => $now->format('Y-m-d H:i:s'),
            ]
        );
        $this->createdMessageIds[] = (int) $msgId;

        $command = $this->application->find('app:cml:recover-stale-runs');
        $tester = new CommandTester($command);
        $tester->execute(['--force' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Successfully marked 1 stale run(s) as FAILED', $tester->getDisplay());

        $reloaded = $this->importRunRepository->find($targetId);
        $this->assertSame(ImportRun::STATUS_FAILED, $reloaded?->getStatus());
    }

    public function testCasSkipsRunIfStatusChangedConcurrentlyFromProcessing(): void
    {
        $filename = 'stale_concurrent_' . $this->testRunSuffix . '.xml';
        $staleRun = new ImportRun('catalog', $filename, 'hash_concurrent');
        $staleRun->setStatus(ImportRun::STATUS_PROCESSING);
        $staleRun->setStartedAt(new \DateTimeImmutable('-3 hours'));
        $this->importRunRepository->save($staleRun);
        $staleId = (int) $staleRun->getId();
        $this->createdRunIds[] = $staleId;

        $this->connection->executeStatement(
            "UPDATE import_runs SET status = 'SUCCESS', items_count = 100, finished_at = NOW() WHERE id = :id",
            ['id' => $staleId]
        );

        $errorMessage = 'Administrative recovery';
        $casResult = $this->importRunRepository->markStaleRunFailedCas($staleId, $errorMessage);
        $this->assertFalse($casResult, 'CAS must return false when status is no longer PROCESSING');

        // Проверяем, что в базе статус остался SUCCESS и не был перезаписан
        static::getContainer()->get('doctrine')->getManager()->clear();
        $reloaded = $this->importRunRepository->find($staleId);
        $this->assertNotNull($reloaded);
        $this->assertSame(ImportRun::STATUS_SUCCESS, $reloaded->getStatus());
        $this->assertSame(100, $reloaded->getItemsCount());
    }
}
