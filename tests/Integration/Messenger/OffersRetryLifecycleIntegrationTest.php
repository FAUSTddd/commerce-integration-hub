<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messenger;

use App\Entity\ImportRun;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Messenger\ImportRunContext;
use App\Messenger\ImportRunStamp;
use App\Repository\ImportRunRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use FaustDDD\Symfony1cImport\Application\Command\ImportOffersCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class OffersRetryLifecycleIntegrationTest extends KernelTestCase
{
    private ?EntityManagerInterface $entityManager = null;
    private ?MessageBusInterface $messageBus = null;
    private ?ImportRunRepository $runRepo = null;
    private ?ImportRunContext $context = null;
    private ?\App\Outbox\OutboxPublisher $publisher = null;
    private ?SerializerInterface $serializer = null;
    private ?Application $application = null;
    private string $tempDir;
    private string $testRunSuffix;

    /**
     * @var list<string>
     */
    private array $expectedFilenames = [];

    /**
     * @var list<int>
     */
    private array $initialQueueMessageIds = [];

    /**
     * @var list<int>
     */
    private array $createdRunIds = [];

    /**
     * @var list<string>
     */
    private array $createdProductIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->runRepo = $container->get(ImportRunRepository::class);
        $this->context = $container->get(ImportRunContext::class);
        $this->publisher = $container->get(\App\Outbox\OutboxPublisher::class);
        $this->serializer = $container->get(SerializerInterface::class);

        // Verify environment isolation
        $dbName = $this->entityManager->getConnection()->getDatabase();
        if ($dbName !== 'commerce_db_test') {
            throw new \RuntimeException('ABORT: Test is not running against commerce_db_test. Current DB: ' . $dbName);
        }

        $this->testRunSuffix = uniqid('test_', true);
        $this->createdRunIds = [];
        $this->createdProductIds = [];

        $this->tempDir = sys_get_temp_dir() . '/offers_retry_' . $this->testRunSuffix;
        @mkdir($this->tempDir, 0777, true);

        $this->expectedFilenames = [
            'offers_failing_' . $this->testRunSuffix . '.xml',
            'offers_success_' . $this->testRunSuffix . '.xml',
        ];

        $rawInitialIds = $this->entityManager->getConnection()->fetchFirstColumn(
            "SELECT id FROM messenger_messages WHERE queue_name IN ('offers', 'failed')"
        );
        $this->initialQueueMessageIds = array_map('intval', $rawInitialIds);

        $this->application = new Application(self::$kernel);
        $this->application->setAutoExit(false);
    }

    protected function tearDown(): void
    {
        $cleanupException = null;
        $fileCleanupException = null;
        $parentException = null;

        try {
            $container = static::getContainer();
            /** @var Connection $conn */
            $conn = $this->entityManager?->getConnection() ?? $container->get('doctrine.dbal.default_connection');
            /** @var SerializerInterface $serializer */
            $serializer = $this->serializer ?? $container->get(SerializerInterface::class);

            // 1. Resolve and validate confirmed Run IDs via exact expectedFilenames
            $runRows = $conn->fetchAllAssociative(
                'SELECT id, filename FROM import_runs WHERE filename IN (:expectedFilenames)',
                ['expectedFilenames' => $this->expectedFilenames],
                ['expectedFilenames' => ArrayParameterType::STRING]
            );

            $discoveredRunIds = [];
            $runIdToFilename = [];
            foreach ($runRows as $runRow) {
                $rId = (int) $runRow['id'];
                $discoveredRunIds[] = $rId;
                $runIdToFilename[$rId] = (string) $runRow['filename'];
            }

            // Verify each createdRunId exists in discovered records; flag anomalies on mismatch
            $anomalies = [];
            foreach ($this->createdRunIds as $createdRunId) {
                if (!isset($runIdToFilename[$createdRunId])) {
                    $anomalies[] = sprintf(
                        'Registered createdRunId %d was not found in import_runs for expected filenames [%s].',
                        $createdRunId,
                        implode(', ', $this->expectedFilenames)
                    );
                }
            }

            // confirmedRunIds strictly contains only IDs matched by exact expectedFilenames
            $confirmedRunIds = array_values(array_unique($discoveredRunIds));

            // 2. Classify messenger messages unconditionally; collect delete IDs and anomalies
            $messageIdsToDelete = [];
            $rows = $conn->fetchAllAssociative(
                "SELECT id, body, headers FROM messenger_messages WHERE queue_name IN ('offers', 'failed')"
            );

            foreach ($rows as $row) {
                $msgId = (int) $row['id'];
                // Preserve pre-existing messages that existed before setUp()
                if (\in_array($msgId, $this->initialQueueMessageIds, true)) {
                    continue;
                }

                try {
                    $envelope = $serializer->decode([
                        'body' => (string) $row['body'],
                        'headers' => json_decode((string) $row['headers'], true) ?? [],
                    ]);
                    $stamp = $envelope->last(ImportRunStamp::class);
                    if ($stamp !== null) {
                        if (!empty($confirmedRunIds) && \in_array($stamp->getImportRunId(), $confirmedRunIds, true)) {
                            $messageIdsToDelete[] = $msgId;
                        }
                        // Message has ImportRunStamp of another run: foreign message, leave untouched
                    } else {
                        $anomalies[] = sprintf(
                            'Messenger message %d is missing ImportRunStamp.',
                            $msgId
                        );
                    }
                } catch (\Throwable $decodeException) {
                    $anomalies[] = sprintf(
                        'Messenger message %d failed to decode: %s',
                        $msgId,
                        $decodeException->getMessage()
                    );
                }
            }

            // Delete only verified messenger messages belonging to confirmed runs
            if (!empty($messageIdsToDelete)) {
                $conn->executeStatement(
                    'DELETE FROM messenger_messages WHERE id IN (:ids)',
                    ['ids' => $messageIdsToDelete],
                    ['ids' => ArrayParameterType::INTEGER]
                );
            }

            // 3. Delete product variants and products (FK order: child variants first)
            if (!empty($this->createdProductIds)) {
                $conn->executeStatement(
                    'DELETE FROM product_variants WHERE product_id IN (SELECT id FROM products WHERE external_id IN (:extIds))',
                    ['extIds' => $this->createdProductIds],
                    ['extIds' => ArrayParameterType::STRING]
                );
                $conn->executeStatement(
                    'DELETE FROM products WHERE external_id IN (:extIds)',
                    ['extIds' => $this->createdProductIds],
                    ['extIds' => ArrayParameterType::STRING]
                );
            }

            // 4. Delete import_run_outbox and import_runs
            if (!empty($confirmedRunIds)) {
                $conn->executeStatement(
                    'DELETE FROM import_run_outbox WHERE run_id IN (:runIds)',
                    ['runIds' => $confirmedRunIds],
                    ['runIds' => ArrayParameterType::INTEGER]
                );

                $conn->executeStatement(
                    'DELETE FROM import_runs WHERE id IN (:runIds)',
                    ['runIds' => $confirmedRunIds],
                    ['runIds' => ArrayParameterType::INTEGER]
                );
            }

            if (!empty($anomalies)) {
                throw new \RuntimeException(sprintf(
                    "Cleanup anomalies detected:
- %s",
                    implode("
- ", $anomalies)
                ));
            }
        } catch (\Throwable $e) {
            $cleanupException = $e;
        } finally {
            try {
                if (is_dir($this->tempDir)) {
                    array_map('unlink', glob($this->tempDir . '/*.*') ?: []);
                    @rmdir($this->tempDir);
                }
            } catch (\Throwable $e) {
                $fileCleanupException = $e;
            }

            try {
                parent::tearDown();
            } catch (\Throwable $e) {
                $parentException = $e;
            }

            $failures = [];
            if ($cleanupException !== null) {
                $failures[] = sprintf("[Cleanup Exception]: %s
%s", $cleanupException->getMessage(), $cleanupException->getTraceAsString());
            }
            if ($fileCleanupException !== null) {
                $failures[] = sprintf("[File Cleanup Exception]: %s
%s", $fileCleanupException->getMessage(), $fileCleanupException->getTraceAsString());
            }
            if ($parentException !== null) {
                $failures[] = sprintf("[Parent Exception]: %s
%s", $parentException->getMessage(), $parentException->getTraceAsString());
            }

            if (count($failures) > 1) {
                $previous = $cleanupException ?? $fileCleanupException ?? $parentException;
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

            if ($fileCleanupException !== null) {
                throw $fileCleanupException;
            }

            if ($parentException !== null) {
                throw $parentException;
            }
        }
    }

    public function testOffersFullRetryLifecycleWithDelaysAndFailedTransfer(): void
    {
        $conn = $this->entityManager->getConnection();

        // 1. Create failing XML fixture (broken XML) with unique suffix to avoid index conflicts
        $failingXmlPath = $this->tempDir . '/offers_failing_' . $this->testRunSuffix . '.xml';
        file_put_contents($failingXmlPath, '<?xml version="1.0" encoding="UTF-8"?><BrokenXmlStructure>');

        // Dispatch failing offers command
        $failingCommand = new ImportOffersCommand($failingXmlPath);
        $envelope = $this->messageBus->dispatch($failingCommand);

        $stamp = $envelope->last(ImportRunStamp::class);
        $this->assertNotNull($stamp, 'ImportRunStamp must be attached by DeduplicateImportMiddleware');
        $failingRunId = $stamp->getImportRunId();
        $this->createdRunIds[] = $failingRunId;

        // Verify initial state: PENDING, 1 message in offers queue for this runId
        $run = $this->runRepo->find($failingRunId);
        $this->assertNotNull($run);
        $this->assertSame(ImportRun::STATUS_PENDING, $run->getStatus());

        $initialMessages = $this->findQueueMessagesForRun($conn, 'offers', $failingRunId);
        $this->assertCount(1, $initialMessages, 'Exactly 1 message for this runId must be in offers queue');

        $delays = [];
        $attemptTimestamps = [];

        // 2. Perform retries with live backoff delays:
        // Total attempts until failure: 1 initial attempt + 3 retries = 4 executions.
        // During attempts 1, 2, 3: message fails and gets scheduled for redelivery with delay.
        // On attempt 4: retries are exhausted (max_retries: 3), message goes to failed queue and ImportRun becomes FAILED.
        for ($execution = 1; $execution <= 4; ++$execution) {
            $tExec = new \DateTimeImmutable();
            $output = new BufferedOutput();
            $input = new ArrayInput([
                'command' => 'messenger:consume',
                'receivers' => ['async_offers'],
                '--limit' => 1,
                '--time-limit' => 5,
                '--no-interaction' => true,
            ]);

            $this->application->run($input, $output);
            $this->entityManager->clear();
            $currentRun = $this->runRepo->find($failingRunId);
            $this->assertNotNull($currentRun);

            // ImportRunContext must be reset after every attempt
            $this->assertNull($this->context->getCurrentRunId(), "Context must be reset after execution {$execution}");

            if ($execution < 4) {
                // While retries are not exhausted:
                // Run is in PROCESSING, finished_at is null
                $this->assertSame(
                    ImportRun::STATUS_PROCESSING,
                    $currentRun->getStatus(),
                    "ImportRun must remain PROCESSING during retry cycle on execution {$execution}"
                );
                $this->assertNull(
                    $currentRun->getFinishedAt(),
                    "finished_at must be null while retries are in progress on execution {$execution}"
                );

                // Publish outbox message if available
                $this->publisher->publishAvailableMessages(10, $failingRunId);

                // Message must be scheduled in offers queue with a future available_at for this specific runId
                $offersForRun = $this->findQueueMessagesForRun($conn, 'offers', $failingRunId);
                $this->assertNotEmpty($offersForRun, "Message must remain in offers queue during retry cycle on execution {$execution}");
                $targetMessage = $offersForRun[0];

                $availableAt = new \DateTimeImmutable((string) $targetMessage['available_at']);
                $delaySec = max(0, $availableAt->getTimestamp() - $tExec->getTimestamp());
                $delays[] = $delaySec;
                $attemptTimestamps[] = [
                    'execution' => $execution,
                    'executed_at' => $tExec->format('H:i:s'),
                    'scheduled_for' => $availableAt->format('H:i:s'),
                    'delay_seconds' => $delaySec,
                ];

                // Sleep until message is available for consumption
                $remainingSleep = max(0, $availableAt->getTimestamp() - time()) + 1;
                sleep($remainingSleep);
            } else {
                // Execution 4: retries exhausted -> terminal FAILED
                $this->assertSame(
                    ImportRun::STATUS_FAILED,
                    $currentRun->getStatus(),
                    'ImportRun must be FAILED after 3 retries are exhausted'
                );
                $this->assertNotNull($currentRun->getFinishedAt());
                $this->assertNotNull($currentRun->getErrorMessage());
                $this->assertStringContainsString('Offers import failed for file', $currentRun->getErrorMessage());

                $attemptTimestamps[] = [
                    'execution' => 4,
                    'executed_at' => $tExec->format('H:i:s'),
                    'result' => 'TRANSFERRED_TO_FAILED',
                ];
            }
        }

        // 3. Verify queues after exhaustion for this specific runId
        $offersRemaining = $this->findQueueMessagesForRun($conn, 'offers', $failingRunId);
        $this->assertCount(0, $offersRemaining, 'Offers queue must have no messages for failingRunId after retries exhausted');

        $failedMessages = $this->findQueueMessagesForRun($conn, 'failed', $failingRunId);
        $this->assertCount(1, $failedMessages, 'Message must be moved to failed queue for failingRunId');
        $failedEnvelope = $failedMessages[0]['envelope'];
        $failedStamp = $failedEnvelope->last(ImportRunStamp::class);
        $this->assertNotNull($failedStamp);
        $this->assertSame($failingRunId, $failedStamp->getImportRunId(), 'Failed message must retain ImportRunStamp');

        // 4. Verify subsequent successful message in the same process/worker
        $prodExternalId = 'prod-success-' . $this->testRunSuffix;
        $this->createdProductIds[] = $prodExternalId;
        $product = new Product($prodExternalId, 'Success Product ' . $this->testRunSuffix, 'SKU-' . $this->testRunSuffix);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        $offerVariantId = $prodExternalId . '#offer_001';
        $successXmlPath = $this->tempDir . '/offers_success_' . $this->testRunSuffix . '.xml';
        file_put_contents($successXmlPath, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация ВерсияСхемы="2.09" ДатаФормирования="2026-11-02">
  <ПакетПредложений СодержитТовары="true">
    <Ид>offers_success_{$this->testRunSuffix}</Ид>
    <Наименование>Offers Success</Наименование>
    <Предложения>
      <Предложение>
        <Ид>{$offerVariantId}</Ид>
        <Наименование>Success Offer</Наименование>
        <Цены>
          <Цена>
            <ЦенаЗаЕдиницу>990.00</ЦенаЗаЕдиницу>
            <Валюта>RUB</Валюта>
          </Цена>
        </Цены>
        <Количество>10.000</Количество>
      </Предложение>
    </Предложения>
  </ПакетПредложений>
</КоммерческаяИнформация>
XML
        );

        $successCommand = new ImportOffersCommand($successXmlPath);
        $successEnvelope = $this->messageBus->dispatch($successCommand);

        $successStamp = $successEnvelope->last(ImportRunStamp::class);
        $this->assertNotNull($successStamp);
        $successRunId = $successStamp->getImportRunId();
        $this->createdRunIds[] = $successRunId;
        $this->assertNotSame($failingRunId, $successRunId);

        // Process successful message
        $outputSuccess = new BufferedOutput();
        $inputSuccess = new ArrayInput([
            'command' => 'messenger:consume',
            'receivers' => ['async_offers'],
            '--limit' => 1,
            '--time-limit' => 5,
            '--no-interaction' => true,
        ]);
        $this->application->run($inputSuccess, $outputSuccess);

        $this->entityManager->clear();
        $successRun = $this->runRepo->find($successRunId);
        $this->assertNotNull($successRun);
        $this->assertSame(ImportRun::STATUS_SUCCESS, $successRun->getStatus());
        $this->assertSame(1, $successRun->getItemsCount());

        $variant = $this->entityManager->getRepository(ProductVariant::class)->findOneBy(['externalId' => $offerVariantId]);
        $this->assertNotNull($variant);
        $this->assertSame(99000, $variant->getPriceAmount());

        // Verify initial failing run stayed FAILED
        $initialRunAfter = $this->runRepo->find($failingRunId);
        $this->assertSame(ImportRun::STATUS_FAILED, $initialRunAfter->getStatus());

        // Context must be clean
        $this->assertNull($this->context->getCurrentRunId());

        echo sprintf("
[RETRY EXECUTION REPORT]:
%s
", json_encode($attemptTimestamps, JSON_PRETTY_PRINT));
    }

    /**
     * @return list<array{id: int, available_at: string, envelope: Envelope}>
     */
    private function findQueueMessagesForRun(Connection $conn, string $queueName, int $targetRunId): array
    {
        $rows = $conn->fetchAllAssociative(
            'SELECT id, body, headers, available_at FROM messenger_messages WHERE queue_name = :queue ORDER BY id ASC',
            ['queue' => $queueName]
        );

        $matched = [];
        foreach ($rows as $row) {
            try {
                $envelope = $this->serializer->decode([
                    'body' => (string) $row['body'],
                    'headers' => json_decode((string) $row['headers'], true) ?? [],
                ]);
                $stamp = $envelope->last(ImportRunStamp::class);
                if ($stamp !== null && $stamp->getImportRunId() === $targetRunId) {
                    $matched[] = [
                        'id' => (int) $row['id'],
                        'available_at' => (string) $row['available_at'],
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
