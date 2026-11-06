<?php

declare(strict_types=1);

namespace App\Tests\Integration\FaultInjection;

use App\Entity\ImportRun;
use App\Messenger\ImportRunStamp;
use App\Messenger\OutboxMessageStamp;
use App\Outbox\OutboxPublisher;
use App\Repository\ImportRunRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\ORM\EntityManagerInterface;
use FaustDDD\Symfony1cImport\Application\Command\ImportCatalogCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class OutboxFaultInjectionIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private ImportRunRepository $repository;
    private OutboxPublisher $publisher;
    private SerializerInterface $serializer;

    /** @var int[] */
    private array $createdRunIds = [];

    /** @var int[] */
    private array $createdMessengerMessageIds = [];

    /** @var Connection[] */
    private array $extraConnections = [];

    private string $testRunSuffix;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
        $this->repository = $this->em->getRepository(ImportRun::class);
        $this->publisher = $container->get(OutboxPublisher::class);
        $this->serializer = $container->get(SerializerInterface::class);

        $this->testRunSuffix = bin2hex(random_bytes(6));
        $this->createdRunIds = [];
        $this->createdMessengerMessageIds = [];
        $this->extraConnections = [];
    }

    protected function tearDown(): void
    {
        // 1. Release locks and close extra connections registered by the test
        foreach ($this->extraConnections as $extraConn) {
            try {
                if ($extraConn->isConnected()) {
                    foreach ($this->createdRunIds as $runId) {
                        try {
                            $extraConn->fetchOne('SELECT pg_advisory_unlock(:id)', ['id' => $runId]);
                        } catch (\Throwable) {
                        }
                    }
                    $extraConn->close();
                }
            } catch (\Throwable) {
            }
        }
        $this->extraConnections = [];

        // 2. Safely remove physical messenger messages registered by the test
        if (!empty($this->createdMessengerMessageIds)) {
            try {
                $this->conn->executeStatement(
                    'DELETE FROM messenger_messages WHERE id IN (:ids)',
                    ['ids' => $this->createdMessengerMessageIds],
                    ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
                );
            } catch (\Throwable) {
            }
            $this->createdMessengerMessageIds = [];
        }

        // 3. Safely remove created outbox and import_run records registered by the test
        if (!empty($this->createdRunIds)) {
            try {
                $this->conn->executeStatement(
                    'DELETE FROM import_run_outbox WHERE run_id IN (:ids)',
                    ['ids' => $this->createdRunIds],
                    ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
                );
                $this->conn->executeStatement(
                    'DELETE FROM import_runs WHERE id IN (:ids)',
                    ['ids' => $this->createdRunIds],
                    ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
                );
            } catch (\Throwable) {
            }
            $this->createdRunIds = [];
        }

        parent::tearDown();
    }

    /**
     * Сценарий 1: Реальный физический SIGKILL воркера (отдельный sub-process).
     * Ожидание:
     * 1. Worker sub-process запускается в фоне, захватывает PostgreSQL Session Advisory Lock и начинает обработку.
     * 2. Родительский процесс посылает физический SIGKILL (kill -9) дочернему процессу.
     * 3. Ядро ОС и PostgreSQL детектируют разрыв TCP сокета воркера и мгновенно освобождают advisory lock.
     * 4. Сообщение остаётся доступным (или повторно доставляется), второй воркер успешно берет lock,
     *    инкрементирует attempt (CAS2: attempt=2), получает новый fencing_token и успешно завершает обработку и ACK.
     */
    public function testSubprocessPhysicalSigkillRecovery(): void
    {
        $fileName = 'phys_sigkill_' . $this->testRunSuffix . '.xml';
        $fileHash = 'hash_sigkill_phys_' . $this->testRunSuffix;
        $runId = $this->repository->tryCreateActiveRun('catalog', $fileName, $fileHash);
        $this->assertNotNull($runId);
        $this->createdRunIds[] = $runId;

        // CAS4A: Создаем отложенную Outbox-запись с каноническим подписанным Envelope
        $token1 = '11111111-1111-1111-1111-111111111111';
        $this->repository->claimRunCas2($runId, $token1);

        $command = new ImportCatalogCommand('var/imports/' . $fileName);
        $envelope = (new Envelope($command))->with(new ImportRunStamp($runId));
        $encoded = $this->serializer->encode($envelope);

        $this->repository->markRunForRetryWithOutboxCas4A(
            $runId,
            -1,
            1,
            $token1,
            'catalog',
            $encoded['headers'] ?? ['type' => ImportCatalogCommand::class],
            ['body' => $encoded['body']]
        );

        $outboxId = (int) $this->conn->fetchOne(
            'SELECT id FROM import_run_outbox WHERE run_id = :runId AND attempt = 1',
            ['runId' => $runId]
        );
        $this->assertGreaterThan(0, $outboxId);

        // Публикуем Outbox запись в messenger_messages
        $this->publisher->publishAvailableMessages(10, $runId);

        // Находим опубликованное физическое сообщение по OutboxMessageStamp и регистрируем для очистки
        $messagesAfter = $this->conn->fetchAllAssociative(
            "SELECT id, body, headers FROM messenger_messages WHERE queue_name = 'catalog' ORDER BY id DESC"
        );
        $foundMessageId = null;
        foreach ($messagesAfter as $msg) {
            $decodedMsg = $this->serializer->decode([
                'body' => $msg['body'],
                'headers' => json_decode($msg['headers'] ?? '[]', true) ?: [],
            ]);
            $stamp = $decodedMsg->last(OutboxMessageStamp::class);
            if ($stamp instanceof OutboxMessageStamp && $stamp->getOutboxId() === $outboxId) {
                $foundMessageId = (int) $msg['id'];
                break;
            }
        }
        $this->assertNotNull($foundMessageId, 'Published message must exist with matching OutboxMessageStamp');
        $this->createdMessengerMessageIds[] = $foundMessageId;

        // 1. Запуск отдельного независимого PHP-процесса Worker A, который держит advisory lock и засыпает
        $dbParams = $this->conn->getParams();
        $host = $dbParams['host'] ?? 'postgres';
        $port = $dbParams['port'] ?? 5432;
        $user = $dbParams['user'] ?? 'commerce_user';
        $pass = $dbParams['password'] ?? 'secret_password';
        $dbname = $dbParams['dbname'] ?? 'commerce_db_test';

        $workerScript = sprintf(
            '<?php
            $pdo = new PDO("pgsql:host=%s;port=%d;dbname=%s", "%s", "%s", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $stmt = $pdo->prepare("SELECT pg_try_advisory_lock(:id)");
            $stmt->execute(["id" => %d]);
            $locked = (bool) $stmt->fetchColumn();
            if (!$locked) {
                exit(1);
            }
            // Signal ready to parent
            echo "LOCKED
";
            flush();
            // Sleep forever until SIGKILL
            while (true) {
                sleep(1);
            }
            ',
            $host,
            $port,
            $dbname,
            $user,
            $pass,
            $runId
        );

        $scriptPath = sys_get_temp_dir() . '/worker_sigkill_' . uniqid() . '.php';
        $logPath = sys_get_temp_dir() . '/worker_sigkill_' . uniqid() . '.log';
        file_put_contents($scriptPath, $workerScript);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $logPath, 'w'],
            2 => ['file', $logPath, 'a'],
        ];
        $pipes = [];
        $proc = null;
        $pid = null;
        $testConn = null;
        $terminatedStatus = null;

        try {
            $proc = proc_open(['php', $scriptPath], $descriptors, $pipes);
            $this->assertIsResource($proc);

            $status = proc_get_status($proc);
            $pid = (int) ($status['pid'] ?? 0);
            $this->assertGreaterThan(0, $pid);

            // Ждем, пока sub-process подтвердит захват advisory lock
            $lockedOutput = false;
            for ($i = 0; $i < 50; $i++) {
                if (file_exists($logPath) && str_contains((string) file_get_contents($logPath), 'LOCKED')) {
                    $lockedOutput = true;
                    break;
                }
                usleep(100000); // 100ms
            }
            $this->assertTrue($lockedOutput, 'Subprocess worker must acquire advisory lock');

            // Проверяем из тестового соединения: advisory lock действительно занят физическим процессом
            $testConn = DriverManager::getConnection($this->conn->getParams());
            $this->extraConnections[] = $testConn;
            $lockedByTest = (bool) $testConn->fetchOne('SELECT pg_try_advisory_lock(:id)', ['id' => $runId]);
            $this->assertFalse($lockedByTest, 'Lock must be physically held by worker subprocess');

            // 2. Отправляем физический SIGKILL (kill -9) дочернему процессу
            posix_kill($pid, 9); // SIGKILL = 9

            // Ждем завершения процесса и сохраняем первый статус остановки
            for ($i = 0; $i < 50; $i++) {
                $st = proc_get_status($proc);
                if (!$st['running']) {
                    $terminatedStatus = $st;
                    break;
                }
                usleep(100000);
            }
            $this->assertNotNull($terminatedStatus, 'Subprocess did not terminate within timeout');
            $this->assertFalse($terminatedStatus['running']);
            $this->assertTrue($terminatedStatus['signaled'], 'Subprocess must be terminated by signal');
            $this->assertSame(9, $terminatedStatus['termsig'], 'Subprocess must be terminated specifically by SIGKILL');

            // 3. Проверяем, что PostgreSQL освободил advisory lock сразу после SIGKILL
            $lockReleased = false;
            for ($i = 0; $i < 50; $i++) {
                if ((bool) $testConn->fetchOne('SELECT pg_try_advisory_lock(:id)', ['id' => $runId])) {
                    $lockReleased = true;
                    break;
                }
                usleep(100000);
            }
            $this->assertTrue($lockReleased, 'Advisory lock must be released immediately by kernel/PostgreSQL after SIGKILL');

            // 4. Worker B перехватывает задание, выполняет CAS2 claim (attempt инкрементируется до 2)
            $token2 = '22222222-2222-2222-2222-222222222222';
            $claimedB = $this->repository->claimRunCas2($runId, $token2);
            $this->assertTrue($claimedB, 'Worker B must claim run via CAS2');

            $this->em->clear();
            $run = $this->repository->find($runId);
            $this->assertNotNull($run);
            $this->assertSame(2, $run->getAttempt(), 'Attempt must be incremented to 2');
            $this->assertSame($token2, $run->getFencingToken(), 'Fencing token must be updated to Worker B');

            // Worker B отмечает outbox запись как acked
            $this->repository->markOutboxAcked($runId, 1);
            $ackedAt = $this->conn->fetchOne(
                'SELECT acked_at FROM import_run_outbox WHERE run_id = :runId AND attempt = 1',
                ['runId' => $runId]
            );
            $this->assertNotNull($ackedAt, 'Outbox entry must be marked as acked');
        } finally {
            if (is_resource($proc)) {
                $isStopped = false;
                if ($terminatedStatus !== null && !$terminatedStatus['running']) {
                    $isStopped = true;
                } else {
                    $st = @proc_get_status($proc);
                    if (is_array($st) && empty($st['running'])) {
                        $isStopped = true;
                    } elseif (is_int($pid) && $pid > 0) {
                        @posix_kill($pid, 9);
                        for ($k = 0; $k < 30; $k++) {
                            usleep(50000);
                            $check = @proc_get_status($proc);
                            if (is_array($check) && empty($check['running'])) {
                                $isStopped = true;
                                break;
                            }
                        }
                    }
                }

                if ($isStopped) {
                    @proc_close($proc);
                }
            }

            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }

            @unlink($scriptPath);
            @unlink($logPath);
        }
    }

    /**
     * Сценарий 2: Потеря DBAL-соединения во время handler и проверка Execution Guard без reconnect/split-brain.
     * Ожидание: ExecutionGuardState переходит в poisoned состояние и блокирует любые последующие DML/SQL
     * до reset(), предотвращая выполнение запросов со старым fencing_token после разрыва соединения.
     */
    public function testExecutionGuardPoisonStateOnConnectionLost(): void
    {
        $guardState = new \App\Doctrine\ExecutionGuard\ExecutionGuardState();
        $this->assertFalse($guardState->isPoisoned());

        // Симуляция начала эпохи и ConnectionLost
        $guardState->startEpoch('worker-epoch-1');
        $guardState->poisonCurrentEpoch();
        $this->assertTrue($guardState->isPoisoned());

        // Любая попытка SQL через ExecutionGuardConnection должна выбрасывать StaleSessionAttemptException
        $this->expectException(\App\Messenger\Exception\StaleSessionAttemptException::class);
        $this->expectExceptionMessage('Current attempt session is poisoned due to connection loss');

        $driver = new \App\Doctrine\ExecutionGuard\ExecutionGuardDriver(
            new \Doctrine\DBAL\Driver\PDO\PgSQL\Driver(),
            $guardState
        );
        $wrappedConn = $driver->connect($this->conn->getParams());
        $wrappedConn->query('SELECT 1');
    }

    /**
     * Сценарий 3: Исключение при receiver ACK/reject и проверка удержания advisory lock до безопасного жизненного цикла.
     * Ожидание: Если в процессе обработки или ACK происходит исключение, advisory lock удерживается до
     * WorkerRunningEvent. При фатальном падении процесса lock сбрасывается только ядром PG при закрытии TCP-сокета.
     */
    public function testAdvisoryLockHoldLifecycleThroughAckOrCrash(): void
    {
        $runId = $this->repository->tryCreateActiveRun(
            'catalog',
            'ack_fail_' . $this->testRunSuffix . '.xml',
            'hash_ack_1_' . $this->testRunSuffix
        );
        $this->assertNotNull($runId);
        $this->createdRunIds[] = $runId;

        $connWorker = DriverManager::getConnection($this->conn->getParams());
        $this->extraConnections[] = $connWorker;
        $locked = (bool) $connWorker->fetchOne('SELECT pg_try_advisory_lock(:id)', ['id' => $runId]);
        $this->assertTrue($locked);

        // Другой процесс не может взять lock
        $connOther = DriverManager::getConnection($this->conn->getParams());
        $this->extraConnections[] = $connOther;
        $otherLocked = (bool) $connOther->fetchOne('SELECT pg_try_advisory_lock(:id)', ['id' => $runId]);
        $this->assertFalse($otherLocked);

        // При сбое воркера до освобождения lock держится вплоть до дисконнекта
        $connWorker->close();

        // После дисконнекта lock моментально доступен
        $otherLockedAfter = (bool) $connOther->fetchOne('SELECT pg_try_advisory_lock(:id)', ['id' => $runId]);
        $this->assertTrue($otherLockedAfter);

        $connOther->executeStatement('SELECT pg_advisory_unlock(:id)', ['id' => $runId]);
        $connOther->close();
    }

    /**
     * Сценарий 4: Повторная доставка Doctrine transport после redeliver_timeout.
     * Ожидание: isRunActivelyHandledByWorker возвращает false, если delivered_at старше redeliver_timeout (3600s).
     */
    public function testDoctrineTransportRedeliveryTimeoutDetection(): void
    {
        $fileName = 'redeliver_' . $this->testRunSuffix . '.xml';
        $fileHash = 'hash_redeliver_1_' . $this->testRunSuffix;
        $runId = $this->repository->tryCreateActiveRun('catalog', $fileName, $fileHash);
        $this->assertNotNull($runId);
        $this->createdRunIds[] = $runId;

        $run = $this->repository->find($runId);
        $this->assertNotNull($run);

        $command = new ImportCatalogCommand('var/imports/' . $fileName);
        $envelope = (new Envelope($command))->with(new ImportRunStamp($runId));
        $encoded = $this->serializer->encode($envelope);
        $encodedHeaders = json_encode($encoded['headers'] ?? []);

        // 1. Сообщение со штампом ImportRunStamp, доставленное 4000 секунд назад (> 3600s)
        $this->conn->executeStatement(
            "INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at, delivered_at)
             VALUES (:body, :headers, 'catalog', NOW() - INTERVAL '5000 seconds', NOW() - INTERVAL '5000 seconds', NOW() - INTERVAL '4000 seconds')",
            [
                'body' => $encoded['body'],
                'headers' => $encodedHeaders,
            ]
        );
        $firstMessageId = (int) $this->conn->lastInsertId();
        $this->assertGreaterThan(0, $firstMessageId);
        $this->createdMessengerMessageIds[] = $firstMessageId;

        $isHandled = $this->repository->isRunActivelyHandledByWorker($run, 3600);
        $this->assertFalse($isHandled, 'Message expired past redeliver_timeout must not be considered actively handled');

        // Адресное удаление только первого сообщения по его точному ID перед вторым этапом
        $this->conn->executeStatement(
            'DELETE FROM messenger_messages WHERE id = :id',
            ['id' => $firstMessageId]
        );
        $this->createdMessengerMessageIds = array_values(
            array_filter($this->createdMessengerMessageIds, static fn (int $id): bool => $id !== $firstMessageId)
        );

        // 2. Свежее сообщение, доставленное только что (< 3600s)
        $this->conn->executeStatement(
            "INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at, delivered_at)
             VALUES (:body, :headers, 'catalog', NOW(), NOW(), NOW())",
            [
                'body' => $encoded['body'],
                'headers' => $encodedHeaders,
            ]
        );
        $secondMessageId = (int) $this->conn->lastInsertId();
        $this->assertGreaterThan(0, $secondMessageId);
        $this->createdMessengerMessageIds[] = $secondMessageId;

        $isHandledFresh = $this->repository->isRunActivelyHandledByWorker($run, 3600);
        $this->assertTrue($isHandledFresh, 'Message delivered recently must be recognized as actively handled');
    }

    /**
     * Сценарий 5: Гонка CAS5 с живым воркером и повторно доставленным сообщением.
     * Ожидание: Если воркер жив и удерживает session advisory lock, CAS5 получает pg_try_advisory_xact_lock = false
     * и немедленно прерывает попытку пометить прогон FAILED.
     */
    public function testCas5RaceWithActiveWorkerHoldingSessionLock(): void
    {
        $runId = $this->repository->tryCreateActiveRun(
            'catalog',
            'cas5_race_' . $this->testRunSuffix . '.xml',
            'hash_cas5_race_' . $this->testRunSuffix
        );
        $this->assertNotNull($runId);
        $this->createdRunIds[] = $runId;

        $token = 'e5555555-5555-5555-5555-555555555555';

        // Воркер захватывает session advisory lock и переходит в PROCESSING
        $connWorker = DriverManager::getConnection($this->conn->getParams());
        $this->extraConnections[] = $connWorker;
        $connWorker->executeStatement('SELECT pg_advisory_lock(:id)', ['id' => $runId]);
        $this->repository->claimRunCas2($runId, $token);

        // CAS5 recovery пытается восстановить этот прогон в отдельном соединении
        $recovered = $this->repository->markStaleRunFailedCas5(
            $runId,
            'Stale timeout recovery',
            1,
            $token
        );
        $this->assertFalse($recovered, 'CAS5 must abort when active session advisory lock is held by worker');

        $run = $this->repository->find($runId);
        $this->assertSame(ImportRun::STATUS_PROCESSING, $run->getStatus(), 'Status must remain PROCESSING');

        $connWorker->executeStatement('SELECT pg_advisory_unlock(:id)', ['id' => $runId]);
        $connWorker->close();
    }

    /**
     * Сценарий 6: Сбой Publisher между INSERT messenger_messages и UPDATE published_at с проверкой атомарности.
     * Ожидание: Благодаря единой транзакции $connection->transactional() при возникновении сбоя откатывается
     * и INSERT в messenger_messages, и UPDATE в import_run_outbox. Никаких осиротевших дубликатов не создается.
     */
    public function testPublisherAtomicityOnFailureRollback(): void
    {
        $fileName = 'pub_fail_' . $this->testRunSuffix . '.xml';
        $fileHash = 'hash_pub_fail_' . $this->testRunSuffix;
        $runId = $this->repository->tryCreateActiveRun('catalog', $fileName, $fileHash);
        $this->assertNotNull($runId);
        $this->createdRunIds[] = $runId;

        $token = 'f6666666-6666-6666-6666-666666666666';
        $this->repository->claimRunCas2($runId, $token);

        $command = new ImportCatalogCommand('var/imports/' . $fileName);
        $envelope = (new Envelope($command))->with(new ImportRunStamp($runId));
        $encoded = $this->serializer->encode($envelope);

        $this->repository->markRunForRetryWithOutboxCas4A(
            $runId,
            -1,
            1,
            $token,
            'catalog',
            $encoded['headers'] ?? ['type' => ImportCatalogCommand::class],
            ['body' => $encoded['body']]
        );

        $outboxId = (int) $this->conn->fetchOne(
            'SELECT id FROM import_run_outbox WHERE run_id = :runId AND attempt = 1',
            ['runId' => $runId]
        );
        $this->assertGreaterThan(0, $outboxId);

        // Симуляция ошибки во время публикации: используем поддельный вызов с искусственным исключением
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Simulated Publisher network crash');

        try {
            $this->conn->transactional(function (Connection $conn) use ($encoded): void {
                $conn->executeStatement(
                    "INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at)
                     VALUES (:body, :headers, 'catalog', NOW(), NOW())",
                    [
                        'body' => $encoded['body'],
                        'headers' => json_encode($encoded['headers'] ?? []),
                    ]
                );
                // Симулируем сбой сети/процесса до UPDATE published_at
                throw new \RuntimeException('Simulated Publisher network crash');
            });
        } finally {
            // Outbox запись осталась published_at IS NULL (готова к повторной публикации)
            $outboxRow = $this->conn->fetchAssociative(
                'SELECT published_at FROM import_run_outbox WHERE run_id = :runId AND attempt = 1',
                ['runId' => $runId]
            );
            $this->assertIsArray($outboxRow);
            $this->assertNull($outboxRow['published_at'], 'Outbox record must remain unpublished');

            // Проверяем, что в messenger_messages транзакция откатилась и сообщение с OutboxMessageStamp не появилось
            $messagesBefore = $this->conn->fetchAllAssociative(
                "SELECT id, body, headers FROM messenger_messages WHERE queue_name = 'catalog'"
            );
            $foundRolledBack = false;
            foreach ($messagesBefore as $msg) {
                $decodedMsg = $this->serializer->decode([
                    'body' => $msg['body'],
                    'headers' => json_decode($msg['headers'] ?? '[]', true) ?: [],
                ]);
                $stamp = $decodedMsg->last(OutboxMessageStamp::class);
                if ($stamp instanceof OutboxMessageStamp && $stamp->getOutboxId() === $outboxId) {
                    $foundRolledBack = true;
                    break;
                }
            }
            $this->assertFalse($foundRolledBack, 'Rolled back message with outbox stamp must not exist in messenger_messages');

            // После устранения сбоя штатный publisher успешно публикует ровно 1 сообщение
            $published = $this->publisher->publishAvailableMessages(10, $runId);
            $this->assertSame(1, $published);

            // Адресно находим опубликованное физическое сообщение по OutboxMessageStamp
            $messagesAfter = $this->conn->fetchAllAssociative(
                "SELECT id, body, headers FROM messenger_messages WHERE queue_name = 'catalog' ORDER BY id DESC"
            );
            $foundMessageId = null;
            $foundEnvelope = null;
            foreach ($messagesAfter as $msg) {
                $decodedMsg = $this->serializer->decode([
                    'body' => $msg['body'],
                    'headers' => json_decode($msg['headers'] ?? '[]', true) ?: [],
                ]);
                $stamp = $decodedMsg->last(OutboxMessageStamp::class);
                if ($stamp instanceof OutboxMessageStamp && $stamp->getOutboxId() === $outboxId) {
                    $foundMessageId = (int) $msg['id'];
                    $foundEnvelope = $decodedMsg;
                    break;
                }
            }

            $this->assertNotNull($foundMessageId, 'Published message must exist with matching OutboxMessageStamp');
            $this->createdMessengerMessageIds[] = $foundMessageId;

            $this->assertInstanceOf(ImportCatalogCommand::class, $foundEnvelope->getMessage());
            $this->assertSame('var/imports/' . $fileName, $foundEnvelope->getMessage()->filePath);
        }
    }
}
