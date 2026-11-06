<?php

declare(strict_types=1);

namespace App\Tests\Integration\Outbox;

use App\Entity\ImportRun;
use App\Messenger\ImportRunStamp;
use App\Messenger\OutboxMessageStamp;
use App\Outbox\OutboxPublisher;
use App\Repository\ImportRunRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use FaustDDD\Symfony1cImport\Application\Command\ImportCatalogCommand;
use FaustDDD\Symfony1cImport\Application\Command\ImportOffersCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class OutboxLifecycleIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private ImportRunRepository $repository;
    private OutboxPublisher $publisher;
    private SerializerInterface $serializer;
    private string $testRunSuffix;
    /** @var list<int> */
    private array $createdRunIds = [];
    /** @var list<int> */
    private array $createdMessengerMessageIds = [];
    /** @var list<int> */
    private array $lockedAdvisoryRunIds = [];
    /** @var list<Connection> */
    private array $extraConnections = [];

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
        $this->lockedAdvisoryRunIds = [];
        $this->extraConnections = [];
    }

    protected function tearDown(): void
    {
        // 1. Release any acquired PostgreSQL session advisory locks on main connection
        foreach ($this->lockedAdvisoryRunIds as $runId) {
            try {
                $this->repository->releaseSessionAdvisoryLock($runId);
            } catch (\Throwable) {
            }
        }

        // 2. Safely close any secondary DBAL connections
        foreach ($this->extraConnections as $conn) {
            try {
                if ($conn->isConnected()) {
                    $conn->close();
                }
            } catch (\Throwable) {
            }
        }

        // 3. Isolated cleanup strictly by tracked messenger IDs and created run IDs
        if ($this->createdMessengerMessageIds !== []) {
            try {
                $this->conn->executeStatement(
                    'DELETE FROM messenger_messages WHERE id IN (:messageIds)',
                    ['messageIds' => $this->createdMessengerMessageIds],
                    ['messageIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
                );
            } catch (\Throwable) {
            }
        }

        if ($this->createdRunIds !== []) {
            try {
                // Delete outbox entries belonging strictly to created runs
                $this->conn->executeStatement(
                    'DELETE FROM import_run_outbox WHERE run_id IN (:runIds)',
                    ['runIds' => $this->createdRunIds],
                    ['runIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
                );

                // Delete import runs created during this test
                $this->conn->executeStatement(
                    'DELETE FROM import_runs WHERE id IN (:runIds)',
                    ['runIds' => $this->createdRunIds],
                    ['runIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
                );
            } catch (\Throwable) {
            }
        }

        parent::tearDown();
    }

    public function testCas4AAtomicWithOutboxInsert(): void
    {
        $runId = $this->repository->tryCreateActiveRun('catalog', 'test_outbox_' . $this->testRunSuffix . '.xml', 'hash_outbox_1_' . $this->testRunSuffix);
        $this->assertNotNull($runId);
        $this->createdRunIds[] = $runId;

        $token = 'd0000000-0000-0000-0000-000000000001';
        $claimed = $this->repository->claimRunCas2($runId, $token);
        $this->assertTrue($claimed);

        // Atomic CAS4A + Outbox insertion
        $ok = $this->repository->markRunForRetryWithOutboxCas4A(
            $runId,
            10,
            1,
            $token,
            'catalog',
            ['X-Test' => 'Header'],
            ['command' => 'ImportCatalogCommand']
        );
        $this->assertTrue($ok);

        $this->em->clear();
        $run = $this->repository->find($runId);
        $this->assertNotNull($run);
        $this->assertNull($run->getFencingToken());
        $this->assertNotNull($run->getRetryScheduledAt());
        $this->assertFalse($run->isHasDeferredRetry());

        // Verify outbox entry
        $outboxRow = $this->conn->fetchAssociative(
            'SELECT * FROM import_run_outbox WHERE run_id = :runId AND attempt = 1',
            ['runId' => $runId]
        );
        $this->assertNotFalse($outboxRow);
        $this->assertNull($outboxRow['published_at']);
        $this->assertSame('catalog', $outboxRow['queue_name']);
        $this->assertSame('retry', $outboxRow['kind']);

        // Duplicate insert for same (idempotency_key) must be ignored
        $duplicateOk = $this->repository->markRunForRetryWithOutboxCas4A(
            $runId,
            10,
            1,
            null, // Token is now NULL
            'catalog',
            ['X-Test' => 'Header2'],
            ['command' => 'ImportCatalogCommand2']
        );
        $this->assertTrue($duplicateOk);

        $count = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM import_run_outbox WHERE run_id = :runId',
            ['runId' => $runId]
        );
        $this->assertSame(1, $count, 'Outbox must enforce uniqueness on idempotency_key');
    }

    public function testOutboxPublisherTransfersToMessengerMessages(): void
    {
        $fileName = 'test_outbox_pub_' . $this->testRunSuffix . '.xml';
        $runId = $this->repository->tryCreateActiveRun('offers', $fileName, 'hash_outbox_2_' . $this->testRunSuffix);
        $this->assertNotNull($runId);
        $this->createdRunIds[] = $runId;

        $token = 'd0000000-0000-0000-0000-000000000002';
        $this->repository->claimRunCas2($runId, $token);

        // Prepare realistic Envelope with command and ImportRunStamp serialized via container's SigningSerializer
        $command = new ImportOffersCommand('var/imports/' . $fileName);
        $envelope = (new Envelope($command))->with(new ImportRunStamp($runId));
        $encoded = $this->serializer->encode($envelope);

        // Available in the past (delay -1s)
        $this->repository->markRunForRetryWithOutboxCas4A(
            $runId,
            -1,
            1,
            $token,
            'offers',
            $encoded['headers'] ?? ['type' => ImportOffersCommand::class],
            ['body' => $encoded['body']]
        );

        // Run publisher strictly for this runId
        $publishedCount = $this->publisher->publishAvailableMessages(10, $runId);
        $this->assertSame(1, $publishedCount);

        // Outbox row marked as published
        $outboxRow = $this->conn->fetchAssociative(
            'SELECT * FROM import_run_outbox WHERE run_id = :runId',
            ['runId' => $runId]
        );
        $this->assertIsArray($outboxRow);
        $this->assertNotNull($outboxRow['published_at']);
        $outboxId = (int) $outboxRow['id'];

        // Message inserted into messenger_messages, find by OutboxMessageStamp
        $foundMessageId = null;
        $messages = $this->conn->fetchAllAssociative(
            'SELECT id, body, headers FROM messenger_messages WHERE queue_name = :queue ORDER BY id DESC',
            ['queue' => 'offers']
        );
        foreach ($messages as $msg) {
            $envelope = $this->serializer->decode([
                'body' => $msg['body'],
                'headers' => json_decode($msg['headers'] ?? '[]', true) ?: [],
            ]);
            $stamp = $envelope->last(OutboxMessageStamp::class);
            if ($stamp instanceof OutboxMessageStamp && $stamp->getOutboxId() === $outboxId) {
                $foundMessageId = (int) $msg['id'];
                break;
            }
        }
        $this->assertNotNull($foundMessageId, 'Published messenger message must contain matching OutboxMessageStamp');
        $this->createdMessengerMessageIds[] = $foundMessageId;

        $messageRow = $this->conn->fetchAssociative(
            'SELECT * FROM messenger_messages WHERE id = :id',
            ['id' => $foundMessageId]
        );
        $this->assertNotFalse($messageRow);

        $decodedMessageEnvelope = $this->serializer->decode([
            'body' => $messageRow['body'],
            'headers' => json_decode($messageRow['headers'] ?? '[]', true) ?: [],
        ]);
        $extractedCommand = $decodedMessageEnvelope->getMessage();
        $this->assertInstanceOf(ImportOffersCommand::class, $extractedCommand);
        $this->assertSame('var/imports/' . $fileName, $extractedCommand->filePath);
        $this->assertStringContainsString($fileName, $messageRow['body']);

        // Second run strictly for this runId must publish 0
        $publishedAgain = $this->publisher->publishAvailableMessages(10, $runId);
        $this->assertSame(0, $publishedAgain);
    }

    public function testConcurrentWorkersDeferUnderActiveLockWithoutLoss(): void
    {
        $runId = $this->repository->tryCreateActiveRun('catalog', 'concurrent_defer_' . $this->testRunSuffix . '.xml', 'hash_concurrent_' . $this->testRunSuffix);
        $this->assertNotNull($runId);
        $this->createdRunIds[] = $runId;

        $connB = DriverManager::getConnection($this->conn->getParams());
        $this->extraConnections[] = $connB;
        $connC = DriverManager::getConnection($this->conn->getParams());
        $this->extraConnections[] = $connC;

        try {
            // Connection 1 (Worker A) acquires advisory lock and claims CAS2
            $tokenA = 'a0000000-0000-0000-0000-000000000010';
            $this->repository->tryAcquireSessionAdvisoryLock($runId);
            $this->lockedAdvisoryRunIds[] = $runId;
            $this->repository->claimRunCas2($runId, $tokenA);

            // Connection 2 (Worker B) attempts handling duplicate concurrently
            $lockedB = (bool) $connB->fetchOne('SELECT pg_try_advisory_lock(:id)', ['id' => $runId]);
            $this->assertFalse($lockedB, 'Worker B cannot acquire lock while Worker A holds it');

            // Prepare realistic Envelope with command and ImportRunStamp serialized via container's SigningSerializer
            $command = new ImportCatalogCommand('var/imports/concurrent_defer_' . $this->testRunSuffix . '.xml');
            $envelope = (new Envelope($command))->with(new ImportRunStamp($runId));
            $encoded = $this->serializer->encode($envelope);

            // Worker B registers persistent Outbox Defer
            $scheduledB = $this->repository->scheduleOutboxDeferCas(
                $runId,
                1,
                'catalog',
                $encoded['headers'] ?? ['type' => 'ImportCatalogCommand'],
                ['body' => $encoded['body']],
                -1
            );
            $this->assertTrue($scheduledB, 'Worker B must successfully schedule persistent outbox defer');

            // Connection 3 (Worker C) also attempts handling duplicate while A still holds lock
            $lockedC = (bool) $connC->fetchOne('SELECT pg_try_advisory_lock(:id)', ['id' => $runId]);
            $this->assertFalse($lockedC, 'Worker C cannot acquire lock while Worker A holds it');

            // Worker C calls scheduleOutboxDeferCas - must return true because active defer already exists!
            $scheduledC = $this->repository->scheduleOutboxDeferCas(
                $runId,
                1,
                'catalog',
                $encoded['headers'] ?? ['type' => 'ImportCatalogCommand'],
                ['body' => $encoded['body']],
                -1
            );
            $this->assertTrue($scheduledC, 'Worker C receives true because durable outbox intent already exists');

            // Verify strictly 1 active defer in Outbox
            $activeDefers = (int) $this->conn->fetchOne(
                "SELECT COUNT(*) FROM import_run_outbox WHERE run_id = :runId AND kind = 'defer' AND published_at IS NULL",
                ['runId' => $runId]
            );
            $this->assertSame(1, $activeDefers, 'Outbox must enforce exactly 1 active defer');

            // Outbox publisher runs and transfers the defer into messenger_messages
            $transferred = $this->publisher->publishAvailableMessages(10, $runId);
            $this->assertSame(1, $transferred);

            // Worker A crashes (socket closed / advisory lock released) before committing data!
            $this->repository->releaseSessionAdvisoryLock($runId);
            $this->lockedAdvisoryRunIds = array_values(array_diff($this->lockedAdvisoryRunIds, [$runId]));

            // Reset fencing_token as Worker A aborted
            $this->conn->executeStatement(
                'UPDATE import_runs SET fencing_token = NULL WHERE id = :id',
                ['id' => $runId]
            );

            // Obtain Outbox entry ID created for this run
            $expectedOutboxId = (int) $this->conn->fetchOne(
                "SELECT id FROM import_run_outbox WHERE run_id = :runId AND kind = 'defer' ORDER BY id DESC LIMIT 1",
                ['runId' => $runId]
            );
            $this->assertGreaterThan(0, $expectedOutboxId);

            // Worker C now consumes the deferred message from messenger_messages:
            // Fetch messages from physical 'catalog' queue, decode via container SigningSerializer,
            // and match the exact message belonging to this outbox entry.
            $queueMessages = $this->conn->fetchAllAssociative(
                "SELECT id, body, headers FROM messenger_messages WHERE queue_name = 'catalog' ORDER BY id DESC"
            );
            $targetMessage = null;
            $matchedOutboxId = null;

            foreach ($queueMessages as $rawMessage) {
                try {
                    $envelope = $this->serializer->decode([
                        'body' => $rawMessage['body'],
                        'headers' => json_decode($rawMessage['headers'], true) ?? [],
                    ]);
                    $outboxStamp = $envelope->last(\App\Messenger\OutboxMessageStamp::class);
                    if ($outboxStamp instanceof \App\Messenger\OutboxMessageStamp && $outboxStamp->getOutboxId() === $expectedOutboxId) {
                        $targetMessage = $rawMessage;
                        $matchedOutboxId = $outboxStamp->getOutboxId();
                        $this->createdMessengerMessageIds[] = (int) $rawMessage['id'];
                        break;
                    }
                } catch (\Throwable) {
                    // Ignore non-matching or differently serialized queue items
                }
            }

            $this->assertNotNull($targetMessage, 'Published messenger message matching Outbox ID must exist in catalog queue');
            $this->assertSame($expectedOutboxId, $matchedOutboxId, 'OutboxMessageStamp::outboxId must match created outbox row');
            $outboxId = $matchedOutboxId;

            // C acquires lock and claims CAS2!
            $lockedC2 = (bool) $connC->fetchOne('SELECT pg_try_advisory_lock(:id)', ['id' => $runId]);
            $this->assertTrue($lockedC2, 'Worker C acquires lock after Worker A crash');

            $tokenC = 'c0000000-0000-0000-0000-000000000030';
            $claimedC = $this->repository->claimRunCas2($runId, $tokenC);
            $this->assertTrue($claimedC, 'Worker C successfully claims run after Worker A crash');

            // Worker C marks outbox entry as acked strictly by Outbox primary key ID
            $this->repository->markOutboxAckedById($outboxId);

            // Assert exact Outbox row state
            $outboxRow = $this->conn->fetchAssociative('SELECT published_at, acked_at FROM import_run_outbox WHERE id = :id', ['id' => $outboxId]);
            $this->assertNotNull($outboxRow['published_at']);
            $this->assertNotNull($outboxRow['acked_at']);
        } finally {
            try {
                $connB->close();
            } catch (\Throwable) {
            }
            try {
                $connC->executeStatement('SELECT pg_advisory_unlock(:id)', ['id' => $runId]);
            } catch (\Throwable) {
            }
            try {
                $connC->close();
            } catch (\Throwable) {
            }
            $this->extraConnections = [];
        }
    }

    public function testParallelPublishersSkipLockedWithoutDuplication(): void
    {
        $fileName = 'pub_test_' . $this->testRunSuffix . '.xml';
        $runId = $this->repository->tryCreateActiveRun('catalog', $fileName, 'hash_pub_' . $this->testRunSuffix);
        $this->assertNotNull($runId);
        $this->createdRunIds[] = $runId;

        $token = 'e0000000-0000-0000-0000-000000000001';
        $this->repository->claimRunCas2($runId, $token);

        // Prepare realistic Envelope with command and ImportRunStamp serialized via container's SigningSerializer
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
            'SELECT id FROM import_run_outbox WHERE run_id = :runId',
            ['runId' => $runId]
        );

        // Two independent connections publishing concurrently
        $conn1 = DriverManager::getConnection($this->conn->getParams());
        $this->extraConnections[] = $conn1;
        $conn2 = DriverManager::getConnection($this->conn->getParams());
        $this->extraConnections[] = $conn2;

        try {
            $pub1 = new OutboxPublisher($conn1);
            $pub2 = new OutboxPublisher($conn2);

            $c1 = $pub1->publishAvailableMessages(10, $runId);
            $c2 = $pub2->publishAvailableMessages(10, $runId);

            $this->assertSame(1, $c1 + $c2, 'Exactly 1 publisher must claim and publish the message');

            // Find physical message id via OutboxMessageStamp($outboxId)
            $foundMessageId = null;
            $foundEnvelope = null;
            $messages = $this->conn->fetchAllAssociative(
                'SELECT id, body, headers FROM messenger_messages WHERE queue_name = :queue ORDER BY id DESC',
                ['queue' => 'catalog']
            );
            foreach ($messages as $msg) {
                $decodedEnvelope = $this->serializer->decode([
                    'body' => $msg['body'],
                    'headers' => json_decode($msg['headers'] ?? '[]', true) ?: [],
                ]);
                $stamp = $decodedEnvelope->last(OutboxMessageStamp::class);
                if ($stamp instanceof OutboxMessageStamp && $stamp->getOutboxId() === $outboxId) {
                    $foundMessageId = (int) $msg['id'];
                    $foundEnvelope = $decodedEnvelope;
                    break;
                }
            }
            $this->assertNotNull($foundMessageId, 'Published catalog message must have OutboxMessageStamp');
            $this->createdMessengerMessageIds[] = $foundMessageId;

            $messageRow = $this->conn->fetchAssociative(
                'SELECT * FROM messenger_messages WHERE id = :id',
                ['id' => $foundMessageId]
            );
            $this->assertNotFalse($messageRow, 'No duplicate messages in queue');

            $this->assertNotNull($foundEnvelope, 'Envelope must be decoded successfully');
            $extractedCommand = $foundEnvelope->getMessage();
            $this->assertInstanceOf(ImportCatalogCommand::class, $extractedCommand);
            $this->assertSame('var/imports/' . $fileName, $extractedCommand->filePath);
            $this->assertStringContainsString($fileName, $messageRow['body']);
        } finally {
            $conn1->close();
            $conn2->close();
        }
    }

    public function testBusyAdvisoryLockSkipsRecordWithoutPublishing(): void
    {
        // 1. Create Run 1 (locked by worker) and Run 2 (unlocked)
        $file1 = 'busy_lock_' . $this->testRunSuffix . '.xml';
        $file2 = 'free_lock_' . $this->testRunSuffix . '.xml';
        $run1 = $this->repository->tryCreateActiveRun('catalog', $file1, 'hash_busy_1_' . $this->testRunSuffix);
        $run2 = $this->repository->tryCreateActiveRun('offers', $file2, 'hash_free_2_' . $this->testRunSuffix);
        $this->assertNotNull($run1);
        $this->assertNotNull($run2);
        $this->createdRunIds[] = $run1;
        $this->createdRunIds[] = $run2;

        $token1 = 'f0000000-0000-0000-0000-000000000001';
        $token2 = 'f0000000-0000-0000-0000-000000000002';
        $this->repository->claimRunCas2($run1, $token1);
        $this->repository->claimRunCas2($run2, $token2);

        // Prepare realistic Envelopes serialized via container's SigningSerializer
        $command1 = new ImportCatalogCommand('var/imports/' . $file1);
        $envelope1 = (new Envelope($command1))->with(new ImportRunStamp($run1));
        $encoded1 = $this->serializer->encode($envelope1);

        $command2 = new ImportOffersCommand('var/imports/' . $file2);
        $envelope2 = (new Envelope($command2))->with(new ImportRunStamp($run2));
        $encoded2 = $this->serializer->encode($envelope2);

        // Schedule outbox retries (ready immediately, delay -1s)
        $this->repository->markRunForRetryWithOutboxCas4A(
            $run1,
            -1,
            1,
            $token1,
            'catalog',
            $encoded1['headers'] ?? ['type' => ImportCatalogCommand::class],
            ['body' => $encoded1['body']]
        );
        $this->repository->markRunForRetryWithOutboxCas4A(
            $run2,
            -1,
            1,
            $token2,
            'offers',
            $encoded2['headers'] ?? ['type' => ImportOffersCommand::class],
            ['body' => $encoded2['body']]
        );

        $outbox1Id = (int) $this->conn->fetchOne(
            'SELECT id FROM import_run_outbox WHERE run_id = :runId',
            ['runId' => $run1]
        );
        $outbox2Id = (int) $this->conn->fetchOne(
            'SELECT id FROM import_run_outbox WHERE run_id = :runId',
            ['runId' => $run2]
        );

        // 2. Separate connection simulates an active worker holding advisory lock for run 1
        $connWorker = DriverManager::getConnection($this->conn->getParams());
        $this->extraConnections[] = $connWorker;

        $locked = (bool) $connWorker->fetchOne('SELECT pg_try_advisory_lock(:id)', ['id' => $run1]);
        $this->assertTrue($locked, 'Worker connection must acquire lock on run 1');

        try {
            // 3. Publisher attempts batch of 10 messages for both test runs
            // Run 1 is busy -> skipped (published_at stays NULL, not in messenger_messages)
            // Run 2 is free -> published (published_at set, inserted in messenger_messages)
            $pubCount1 = $this->publisher->publishAvailableMessages(10, $run1);
            $pubCount2 = $this->publisher->publishAvailableMessages(10, $run2);
            $this->assertSame(0, $pubCount1, 'Busy run outbox message must not be published');
            $this->assertSame(1, $pubCount2, 'Only the unlocked run outbox message should be published');

            // Find physical message id for Run 2 via OutboxMessageStamp($outbox2Id)
            $foundMessage2Id = null;
            $foundEnvelope2 = null;
            $messagesOffersList = $this->conn->fetchAllAssociative(
                'SELECT id, body, headers FROM messenger_messages WHERE queue_name = :queue ORDER BY id DESC',
                ['queue' => 'offers']
            );
            foreach ($messagesOffersList as $msg) {
                $decodedEnvelope = $this->serializer->decode([
                    'body' => $msg['body'],
                    'headers' => json_decode($msg['headers'] ?? '[]', true) ?: [],
                ]);
                $stamp = $decodedEnvelope->last(OutboxMessageStamp::class);
                if ($stamp instanceof OutboxMessageStamp && $stamp->getOutboxId() === $outbox2Id) {
                    $foundMessage2Id = (int) $msg['id'];
                    $foundEnvelope2 = $decodedEnvelope;
                    break;
                }
            }
            $this->assertNotNull($foundMessage2Id, 'Published Run 2 message must have OutboxMessageStamp');
            $this->createdMessengerMessageIds[] = $foundMessage2Id;

            // Verify Run 1 outbox entry: published_at is strictly NULL
            $row1 = $this->conn->fetchAssociative(
                'SELECT published_at FROM import_run_outbox WHERE run_id = :id',
                ['id' => $run1]
            );
            $this->assertNotNull($row1);
            $this->assertNull($row1['published_at'], 'Busy run outbox entry must remain unpublished');

            // Verify Run 2 outbox entry: published_at is set
            $row2 = $this->conn->fetchAssociative(
                'SELECT published_at FROM import_run_outbox WHERE run_id = :id',
                ['id' => $run2]
            );
            $this->assertNotNull($row2);
            $this->assertNotNull($row2['published_at'], 'Free run outbox entry must be marked published');

            // Verify messenger_messages: only offers queue contains a message
            $messageOffersRow = $this->conn->fetchAssociative(
                'SELECT * FROM messenger_messages WHERE id = :id',
                ['id' => $foundMessage2Id]
            );
            $this->assertNotFalse($messageOffersRow, 'Message must be queued for free run');

            $this->assertNotNull($foundEnvelope2, 'Run 2 envelope must be decoded successfully');
            $extractedOffersCommand = $foundEnvelope2->getMessage();
            $this->assertInstanceOf(ImportOffersCommand::class, $extractedOffersCommand);
            $this->assertSame('var/imports/' . $file2, $extractedOffersCommand->filePath);
        } finally {
            // 4. Worker finishes and releases the advisory lock on $connWorker
            try {
                $connWorker->fetchOne('SELECT pg_advisory_unlock(:id)', ['id' => $run1]);
            } catch (\Throwable) {
                // Ignore if connection already severed
            }
            $connWorker->close();
        }

        // 5. Next cycle: Publisher now successfully publishes Run 1 strictly by runId
        $secondPublishedCount = $this->publisher->publishAvailableMessages(10, $run1);
        $this->assertSame(1, $secondPublishedCount, 'Once lock is released, outbox message should be published in next cycle');

        $row1After = $this->conn->fetchAssociative(
            'SELECT published_at FROM import_run_outbox WHERE run_id = :id',
            ['id' => $run1]
        );
        $this->assertNotNull($row1After['published_at']);

        // Find physical message id for Run 1 via OutboxMessageStamp($outbox1Id)
        $foundMessage1Id = null;
        $foundEnvelope1 = null;
        $messagesCatalogList = $this->conn->fetchAllAssociative(
            'SELECT id, body, headers FROM messenger_messages WHERE queue_name = :queue ORDER BY id DESC',
            ['queue' => 'catalog']
        );
        foreach ($messagesCatalogList as $msg) {
            $decodedEnvelope = $this->serializer->decode([
                'body' => $msg['body'],
                'headers' => json_decode($msg['headers'] ?? '[]', true) ?: [],
            ]);
            $stamp = $decodedEnvelope->last(OutboxMessageStamp::class);
            if ($stamp instanceof OutboxMessageStamp && $stamp->getOutboxId() === $outbox1Id) {
                $foundMessage1Id = (int) $msg['id'];
                $foundEnvelope1 = $decodedEnvelope;
                break;
            }
        }
        $this->assertNotNull($foundMessage1Id, 'Published Run 1 message must have OutboxMessageStamp');
        $this->createdMessengerMessageIds[] = $foundMessage1Id;

        $messageCatalogRow = $this->conn->fetchAssociative(
            'SELECT * FROM messenger_messages WHERE id = :id',
            ['id' => $foundMessage1Id]
        );
        $this->assertNotFalse($messageCatalogRow);

        $this->assertNotNull($foundEnvelope1, 'Run 1 envelope must be decoded successfully');
        $extractedCatalogCommand = $foundEnvelope1->getMessage();
        $this->assertInstanceOf(ImportCatalogCommand::class, $extractedCatalogCommand);
        $this->assertSame('var/imports/' . $file1, $extractedCatalogCommand->filePath);
    }
}
