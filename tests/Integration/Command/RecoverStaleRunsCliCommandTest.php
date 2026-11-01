<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\ImportRun;
use App\Entity\Product;
use App\Messenger\ImportRunStamp;
use App\Repository\ImportRunRepository;
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

    protected function setUp(): void
    {
        self::bootKernel();
        $this->importRunRepository = static::getContainer()->get(ImportRunRepository::class);
        $this->application = new Application(static::$kernel);
        $this->connection = static::getContainer()->get(Connection::class);

        // Очищаем тестовые таблицы перед каждым тестом
        $this->connection->executeStatement('DELETE FROM messenger_messages');
        $this->connection->executeStatement('DELETE FROM product_variants');
        $this->connection->executeStatement('DELETE FROM products');
        $this->connection->executeStatement('DELETE FROM import_runs');
    }

    public function testDryRunDoesNotModifyDatabase(): void
    {
        $staleRun = new ImportRun('catalog', 'stale_test.xml', 'hash1');
        $staleRun->setStatus(ImportRun::STATUS_PROCESSING);
        $staleRun->setStartedAt(new \DateTimeImmutable('-3 hours'));
        $this->importRunRepository->save($staleRun);
        $staleId = $staleRun->getId();

        $command = $this->application->find('app:cml:recover-stale-runs');
        $tester = new CommandTester($command);

        // Dry run
        $tester->execute(['--dry-run' => true]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('[DRY-RUN] Found 1 candidate run(s) eligible for recovery', $tester->getDisplay());

        $runAfterDry = $this->importRunRepository->find($staleId);
        $this->assertSame(ImportRun::STATUS_PROCESSING, $runAfterDry?->getStatus());
        $this->assertNull($runAfterDry?->getFinishedAt());
    }

    public function testNonInteractiveExecutionWithoutForceFailsAndDoesNotModifyState(): void
    {
        $staleRun = new ImportRun('catalog', 'stale_test.xml', 'hash1');
        $staleRun->setStatus(ImportRun::STATUS_PROCESSING);
        $staleRun->setStartedAt(new \DateTimeImmutable('-3 hours'));
        $this->importRunRepository->save($staleRun);
        $staleId = $staleRun->getId();

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
        $staleRun = new ImportRun('catalog', 'stale_test.xml', 'hash1');
        $staleRun->setStatus(ImportRun::STATUS_PROCESSING);
        $staleRun->setStartedAt(new \DateTimeImmutable('-3 hours'));
        $this->importRunRepository->save($staleRun);
        $staleId = $staleRun->getId();

        // 2. Создаём свежий run (30 минут назад)
        $freshRun = new ImportRun('offers', 'fresh_test.xml', 'hash2');
        $freshRun->setStatus(ImportRun::STATUS_PROCESSING);
        $freshRun->setStartedAt(new \DateTimeImmutable('-30 minutes'));
        $this->importRunRepository->save($freshRun);
        $freshId = $freshRun->getId();

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
        $staleRun = new ImportRun('catalog', 'stale_abort.xml', 'hash_abort');
        $staleRun->setStatus(ImportRun::STATUS_PROCESSING);
        $staleRun->setStartedAt(new \DateTimeImmutable('-3 hours'));
        $this->importRunRepository->save($staleRun);
        $staleId = $staleRun->getId();

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
        $run = new ImportRun('catalog', 'long_running_worker.xml', 'hash_long');
        $run->setStatus(ImportRun::STATUS_PROCESSING);
        $run->setStartedAt(new \DateTimeImmutable('-3 hours'));
        $this->importRunRepository->save($run);
        $runId = $run->getId();

        // Сообщение находится в процессе обработки с delivered_at 15 минут назад (< 1 час redeliver_timeout)
        $serializer = new PhpSerializer();
        $msg = new \stdClass();
        $msg->test = true;
        $envelope = new Envelope($msg, [new ImportRunStamp($runId)]);
        $encoded = $serializer->encode($envelope);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $fifteenMinutesAgo = $now->modify('-15 minutes');

        $this->connection->executeStatement(
            'INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at, delivered_at)
             VALUES (:body, :headers, :queue, :created, :available, :delivered)',
            [
                'body' => $encoded['body'],
                'headers' => json_encode($encoded['headers'] ?? []),
                'queue' => 'async_catalog',
                'created' => $now->format('Y-m-d H:i:s'),
                'available' => $now->format('Y-m-d H:i:s'),
                'delivered' => $fifteenMinutesAgo->format('Y-m-d H:i:s'),
            ]
        );

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
        $run = new ImportRun('catalog', 'unprocessed_queue_msg.xml', 'hash_queue');
        $run->setStatus(ImportRun::STATUS_PROCESSING);
        $run->setStartedAt(new \DateTimeImmutable('-3 hours'));
        $this->importRunRepository->save($run);
        $runId = $run->getId();

        // Сообщение находится в очереди, НО delivered_at IS NULL (воркер не брал его)
        $serializer = new PhpSerializer();
        $msg = new \stdClass();
        $envelope = new Envelope($msg, [new ImportRunStamp($runId)]);
        $encoded = $serializer->encode($envelope);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->connection->executeStatement(
            'INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at, delivered_at)
             VALUES (:body, :headers, :queue, :created, :available, NULL)',
            [
                'body' => $encoded['body'],
                'headers' => json_encode($encoded['headers'] ?? []),
                'queue' => 'catalog',
                'created' => $now->format('Y-m-d H:i:s'),
                'available' => $now->format('Y-m-d H:i:s'),
            ]
        );

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
        // Создаём run с ID = 12
        $targetRun = new ImportRun('catalog', 'target_run.xml', 'hash_target');
        $targetRun->setStatus(ImportRun::STATUS_PROCESSING);
        $targetRun->setStartedAt(new \DateTimeImmutable('-3 hours'));
        $this->importRunRepository->save($targetRun);
        $targetId = $targetRun->getId();

        // Создаём сообщение в messenger_messages, содержащее ImportRunStamp с ID = 123 (где "12" является подстрокой!)
        $differentRunId = 12000 + $targetId;
        $serializer = new PhpSerializer();
        $msg = new \stdClass();
        $envelope = new Envelope($msg, [new ImportRunStamp($differentRunId)]);
        $encoded = $serializer->encode($envelope);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->connection->executeStatement(
            'INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at, delivered_at)
             VALUES (:body, :headers, :queue, :created, :available, :delivered)',
            [
                'body' => $encoded['body'],
                'headers' => json_encode($encoded['headers'] ?? []),
                'queue' => 'async_catalog',
                'created' => $now->format('Y-m-d H:i:s'),
                'available' => $now->format('Y-m-d H:i:s'),
                'delivered' => $now->format('Y-m-d H:i:s'),
            ]
        );

        $command = $this->application->find('app:cml:recover-stale-runs');
        $tester = new CommandTester($command);
        $tester->execute(['--force' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Successfully marked 1 stale run(s) as FAILED', $tester->getDisplay());

        $reloaded = $this->importRunRepository->find($targetId);
        $this->assertSame(ImportRun::STATUS_FAILED, $reloaded?->getStatus());
    }
}
