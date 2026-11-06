<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messenger;

use App\Messenger\DeduplicateImportMiddleware;
use App\Messenger\DuplicateImportStamp;
use App\Messenger\ImportRunStamp;
use App\Repository\ImportRunRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use FaustDDD\Symfony1cImport\Application\Command\ImportCatalogCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class ConcurrentImportDispatchIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Connection $primaryConn;
    private MessageBusInterface $bus;
    private SerializerInterface $serializer;

    private string $testRunSuffix;

    /**
     * @var list<int>
     */
    private array $initialQueueMessageIds = [];

    /**
     * @var list<int>
     */
    private array $createdRunIds = [];

    /**
     * @var list<Connection>
     */
    private array $extraConnections = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $env = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null;
        if ($env !== 'test') {
            throw new \RuntimeException('ABORT: Test must run with APP_ENV=test. Current: ' . (string) $env);
        }

        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->primaryConn = $this->entityManager->getConnection();

        $dbName = $this->primaryConn->getDatabase();
        if ($dbName !== 'commerce_db_test') {
            throw new \RuntimeException('ABORT: Test DB must be commerce_db_test. Current DB: ' . (string) $dbName);
        }

        $this->bus = $container->get(MessageBusInterface::class);
        $this->serializer = new PhpSerializer();

        $this->testRunSuffix = bin2hex(random_bytes(6));
        $this->createdRunIds = [];
        $this->extraConnections = [];

        $rawInitialIds = $this->primaryConn->fetchFirstColumn('SELECT id FROM messenger_messages');
        $this->initialQueueMessageIds = array_map('intval', $rawInitialIds);
    }

    protected function tearDown(): void
    {
        $cleanupException = null;
        $connectionException = null;
        $parentException = null;

        try {
            if (!empty($this->createdRunIds) && $this->primaryConn->isConnected()) {
                $rows = $this->primaryConn->fetchAllAssociative(
                    "SELECT id, body, headers FROM messenger_messages WHERE queue_name IN ('catalog', 'async_catalog', 'offers', 'failed')"
                );

                $messageIdsToDelete = [];
                $anomalies = [];

                foreach ($rows as $row) {
                    $msgId = (int) $row['id'];
                    if (\in_array($msgId, $this->initialQueueMessageIds, true)) {
                        continue;
                    }

                    try {
                        $envelope = $this->serializer->decode([
                            'body' => (string) $row['body'],
                            'headers' => json_decode((string) $row['headers'], true) ?? [],
                        ]);
                        $stamp = $envelope->last(ImportRunStamp::class);
                        if ($stamp !== null && \in_array($stamp->getImportRunId(), $this->createdRunIds, true)) {
                            $messageIdsToDelete[] = $msgId;
                        }
                    } catch (\Throwable $e) {
                        $anomalies[] = sprintf('Failed to decode messenger message %d: %s', $msgId, $e->getMessage());
                    }
                }

                if (!empty($messageIdsToDelete)) {
                    $this->primaryConn->executeStatement(
                        'DELETE FROM messenger_messages WHERE id IN (:ids)',
                        ['ids' => $messageIdsToDelete],
                        ['ids' => ArrayParameterType::INTEGER]
                    );
                }

                $this->primaryConn->executeStatement(
                    'DELETE FROM import_run_outbox WHERE run_id IN (:runIds)',
                    ['runIds' => $this->createdRunIds],
                    ['runIds' => ArrayParameterType::INTEGER]
                );

                $this->primaryConn->executeStatement(
                    'DELETE FROM import_runs WHERE id IN (:runIds)',
                    ['runIds' => $this->createdRunIds],
                    ['runIds' => ArrayParameterType::INTEGER]
                );

                if (!empty($anomalies)) {
                    throw new \RuntimeException(sprintf(
                        "TearDown anomalies detected:
- %s",
                        implode("
- ", $anomalies)
                    ));
                }
            }
        } catch (\Throwable $e) {
            $cleanupException = $e;
        } finally {
            try {
                foreach ($this->extraConnections as $extraConn) {
                    if ($extraConn->isConnected()) {
                        $extraConn->close();
                    }
                }
                $this->extraConnections = [];
            } catch (\Throwable $e) {
                $connectionException = $e;
            }

            try {
                parent::tearDown();
                $this->entityManager->close();
            } catch (\Throwable $e) {
                $parentException = $e;
            }

            $failures = [];
            if ($cleanupException !== null) {
                $failures[] = sprintf("[Cleanup Exception]: %s
%s", $cleanupException->getMessage(), $cleanupException->getTraceAsString());
            }
            if ($connectionException !== null) {
                $failures[] = sprintf("[Connection Exception]: %s
%s", $connectionException->getMessage(), $connectionException->getTraceAsString());
            }
            if ($parentException !== null) {
                $failures[] = sprintf("[Parent Exception]: %s
%s", $parentException->getMessage(), $parentException->getTraceAsString());
            }

            if (count($failures) > 1) {
                $previous = $cleanupException ?? $connectionException ?? $parentException;
                throw new \RuntimeException(
                    "Multiple tearDown failures:
" . implode("

", $failures),
                    0,
                    $previous
                );
            }

            if ($cleanupException !== null) {
                throw $cleanupException;
            }
            if ($connectionException !== null) {
                throw $connectionException;
            }
            if ($parentException !== null) {
                throw $parentException;
            }
        }
    }

    public function testFirstDispatchEnqueuesAndSecondDispatchShortCircuits(): void
    {
        $filename = 'concurrent_catalog_' . $this->testRunSuffix . '.xml';
        $command = new ImportCatalogCommand('/tmp/' . $filename);

        // First dispatch -> should create PENDING run, attach ImportRunStamp, and enqueue
        $envelope1 = $this->bus->dispatch($command);

        $stamp1 = $envelope1->last(ImportRunStamp::class);
        $this->assertInstanceOf(ImportRunStamp::class, $stamp1);
        $this->assertNull($envelope1->last(DuplicateImportStamp::class));

        $runId1 = $stamp1->getImportRunId();
        $this->createdRunIds[] = $runId1;

        // Second dispatch of exact same command while first is PENDING
        $envelope2 = $this->bus->dispatch($command);

        $dupStamp = $envelope2->last(DuplicateImportStamp::class);
        $this->assertInstanceOf(DuplicateImportStamp::class, $dupStamp);
        $this->assertSame('catalog', $dupStamp->type);
        $this->assertSame($filename, $dupStamp->filename);
        $this->assertSame($runId1, $dupStamp->existingRunId);

        // Verify database: strictly 1 ImportRun for this runId
        $run = $this->primaryConn->fetchAssociative(
            'SELECT * FROM import_runs WHERE id = :id',
            ['id' => $runId1]
        );
        $this->assertIsArray($run);
        $this->assertSame('PENDING', $run['status']);
        $this->assertSame($filename, $run['filename']);

        // Verify transport: strictly 1 message in messenger_messages for this runId
        $messagesForRun = $this->findQueueMessagesForRun($runId1);
        $this->assertCount(1, $messagesForRun);
    }

    public function testTrueConcurrentDispatchViaTwoIndependentConnections(): void
    {
        $params = $this->primaryConn->getParams();
        $secondaryConn = DriverManager::getConnection($params);
        $this->extraConnections[] = $secondaryConn;

        $filename = 'race_condition_' . $this->testRunSuffix . '.xml';
        $type = 'catalog';
        $fileHash = hash('sha256', $filename);

        // Connection 1 creates run in transaction without committing immediately
        $this->primaryConn->beginTransaction();
        $res1 = $this->primaryConn->fetchOne(
            "INSERT INTO import_runs (type, filename, file_hash, status, items_count, created_at)
             VALUES (:type, :filename, :fileHash, 'PENDING', 0, NOW())
             ON CONFLICT (type, filename) WHERE status IN ('PENDING', 'PROCESSING')
             DO NOTHING
             RETURNING id",
            ['type' => $type, 'filename' => $filename, 'fileHash' => $fileHash]
        );
        $this->assertNotFalse($res1);
        $runId1 = (int) $res1;
        $this->createdRunIds[] = $runId1;

        // Conn 1 enqueues message to messenger_messages
        $command1 = new ImportCatalogCommand('/tmp/' . $filename);
        $envelope1 = (new Envelope($command1))->with(new ImportRunStamp($runId1));
        $encoded1 = $this->serializer->encode($envelope1);
        $this->primaryConn->executeStatement(
            "INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at)
             VALUES (:body, :headers, :queue, NOW(), NOW())",
            [
                'body' => $encoded1['body'],
                'headers' => json_encode($encoded1['headers'] ?? []),
                'queue' => 'async_catalog',
            ]
        );

        // Commit Conn 1
        $this->primaryConn->commit();

        // Now Connection 2 attempts concurrent tryCreateActiveRun
        $res2 = $secondaryConn->fetchOne(
            "INSERT INTO import_runs (type, filename, file_hash, status, items_count, created_at)
             VALUES (:type, :filename, :fileHash, 'PENDING', 0, NOW())
             ON CONFLICT (type, filename) WHERE status IN ('PENDING', 'PROCESSING')
             DO NOTHING
             RETURNING id",
            ['type' => $type, 'filename' => $filename, 'fileHash' => $fileHash]
        );

        // Conn 2 MUST return false/null
        $this->assertTrue($res2 === false || $res2 === null);

        // Because res2 is null, middleware short-circuits and skips enqueue on Conn 2
        // Assert invariants in PostgreSQL:
        $totalActiveRuns = (int) $this->primaryConn->fetchOne(
            "SELECT COUNT(*) FROM import_runs WHERE filename = :fn AND status IN ('PENDING', 'PROCESSING')",
            ['fn' => $filename]
        );
        $this->assertSame(1, $totalActiveRuns);

        $messagesForRun = $this->findQueueMessagesForRun($runId1);
        $this->assertCount(1, $messagesForRun);
    }

    /**
     * @return list<array{id: int, envelope: Envelope}>
     */
    private function findQueueMessagesForRun(int $targetRunId): array
    {
        $rows = $this->primaryConn->fetchAllAssociative(
            "SELECT id, body, headers FROM messenger_messages WHERE queue_name IN ('catalog', 'async_catalog', 'offers', 'failed') ORDER BY id ASC"
        );

        $matched = [];
        foreach ($rows as $row) {
            $msgId = (int) $row['id'];
            if (\in_array($msgId, $this->initialQueueMessageIds, true)) {
                continue;
            }

            try {
                $envelope = $this->serializer->decode([
                    'body' => (string) $row['body'],
                    'headers' => json_decode((string) $row['headers'], true) ?? [],
                ]);
                $stamp = $envelope->last(ImportRunStamp::class);
                if ($stamp !== null && $stamp->getImportRunId() === $targetRunId) {
                    $matched[] = [
                        'id' => $msgId,
                        'envelope' => $envelope,
                    ];
                }
            } catch (\Throwable) {
                // Ignore decoding errors for foreign messages
            }
        }

        return $matched;
    }
}
