<?php

declare(strict_types=1);

namespace App\Tests\Unit\Outbox;

use App\Messenger\ImportRunStamp;
use App\Outbox\OutboxPublisher;
use Doctrine\DBAL\Connection;
use FaustDDD\Symfony1cImport\Application\Command\ImportCatalogCommand;
use FaustDDD\Symfony1cImport\Application\Command\ImportOffersCommand;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SigningSerializer;

final class OutboxPublisherPayloadCompatibilityTest extends TestCase
{
    private PhpSerializer $phpSerializer;
    private SigningSerializer $signingSerializer;

    protected function setUp(): void
    {
        $this->phpSerializer = new PhpSerializer();
        $this->signingSerializer = new SigningSerializer(
            $this->phpSerializer,
            'test-secret-key-1234567890123456',
            [\Symfony\Component\Console\Messenger\RunCommandMessage::class]
        );
    }

    public function testByteExactBodyAndHeadersTransferredToMessenger(): void
    {
        $command = new ImportOffersCommand('/tmp/test_offers.xml');
        $envelope = (new Envelope($command))->with(new ImportRunStamp(101));

        $encoded = $this->signingSerializer->encode($envelope);

        $outboxRow = [
            'id' => 1,
            'run_id' => 101,
            'queue_name' => 'offers',
            'headers' => json_encode($encoded['headers'] ?? []),
            'payload' => json_encode(['body' => $encoded['body']]),
            'available_at' => '2026-11-05 10:00:00',
        ];

        $dbConnection = $this->createMock(Connection::class);
        $dbConnection->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                return $callback();
            });

        $dbConnection->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([$outboxRow]);

        $dbConnection->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT pg_try_advisory_xact_lock(:id)', ['id' => 101])
            ->willReturn(true);

        $executedStatements = [];
        $dbConnection->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$executedStatements) {
                $executedStatements[] = ['sql' => $sql, 'params' => $params];
                return 1;
            });

        $publisher = new OutboxPublisher($dbConnection);
        $publishedCount = $publisher->publishAvailableMessages(10);

        $this->assertSame(1, $publishedCount);
        $this->assertCount(2, $executedStatements);

        // 1. Verify INSERT into messenger_messages
        $insertParams = $executedStatements[0]['params'];
        $this->assertSame('offers', $insertParams['queueName']);
        $this->assertSame(json_encode($encoded['headers'] ?? []), $insertParams['headers'], 'Headers must be correctly transferred');

        // 2. Verify decode round-trip using SigningSerializer
        $headersArray = json_decode($insertParams['headers'], true);
        $decodedEnvelope = $this->signingSerializer->decode([
            'body' => $insertParams['body'],
            'headers' => $headersArray,
        ]);

        $this->assertInstanceOf(ImportOffersCommand::class, $decodedEnvelope->getMessage());
        $this->assertSame('/tmp/test_offers.xml', $decodedEnvelope->getMessage()->filePath);
        $stamp = $decodedEnvelope->last(ImportRunStamp::class);
        $this->assertNotNull($stamp);
        $this->assertSame(101, $stamp->getImportRunId());

        $outboxStamp = $decodedEnvelope->last(\App\Messenger\OutboxMessageStamp::class);
        $this->assertNotNull($outboxStamp, 'OutboxPublisher must enrich Envelope with OutboxMessageStamp');
        $this->assertSame(1, $outboxStamp->getOutboxId());

        // 3. Verify UPDATE outbox statement
        $updateParams = $executedStatements[1]['params'];
        $this->assertSame(1, $updateParams['id']);
    }

    public function testDecodeRoundTripWithSignedMessageAndStamps(): void
    {
        $signingSerializer = new SigningSerializer(
            $this->phpSerializer,
            'test-secret-key-1234567890123456',
            [ImportCatalogCommand::class] // Catalog commands configured for signing
        );

        $command = new ImportCatalogCommand('/tmp/signed_catalog.xml');
        $envelope = (new Envelope($command))->with(new ImportRunStamp(202));

        $encoded = $signingSerializer->encode($envelope);
        $this->assertArrayHasKey('Body-Sign', $encoded['headers']);
        $this->assertArrayHasKey('Sign-Algo', $encoded['headers']);

        $outboxRow = [
            'id' => 2,
            'run_id' => 202,
            'queue_name' => 'catalog',
            'headers' => json_encode($encoded['headers']),
            'payload' => json_encode(['body' => $encoded['body']]),
            'available_at' => '2026-11-05 10:00:00',
        ];

        $dbConnection = $this->createMock(Connection::class);
        $dbConnection->method('transactional')->willReturnCallback(fn(callable $cb) => $cb());
        $dbConnection->method('fetchAllAssociative')->willReturn([$outboxRow]);
        $dbConnection->method('fetchOne')->willReturn(true);

        $capturedParams = [];
        $dbConnection->method('executeStatement')->willReturnCallback(function (string $sql, array $params) use (&$capturedParams) {
            $capturedParams[] = $params;
            return 1;
        });

        $publisher = new OutboxPublisher($dbConnection, serializer: $signingSerializer);
        $count = $publisher->publishAvailableMessages(10);

        $this->assertSame(1, $count);
        $publishedBody = $capturedParams[0]['body'];
        $publishedHeaders = json_decode($capturedParams[0]['headers'], true);

        // Decode must succeed with HMAC verification intact
        $decoded = $signingSerializer->decode([
            'body' => $publishedBody,
            'headers' => $publishedHeaders,
        ]);

        $this->assertInstanceOf(ImportCatalogCommand::class, $decoded->getMessage());
        $this->assertSame(202, $decoded->last(ImportRunStamp::class)?->getImportRunId());
        $this->assertSame(2, $decoded->last(\App\Messenger\OutboxMessageStamp::class)?->getOutboxId());
    }

    public function testRejectsRawSerializedCommandWithoutEnvelope(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('raw serialized command instead of Messenger Envelope'));

        $command = new ImportOffersCommand('/tmp/raw_cmd.xml');
        // Raw serialized command instead of Envelope
        $rawSerializedCommand = serialize($command);

        $outboxRow = [
            'id' => 3,
            'run_id' => 303,
            'queue_name' => 'offers',
            'headers' => json_encode(['type' => $command::class]),
            'payload' => json_encode(['serialized' => $rawSerializedCommand]),
            'available_at' => '2026-11-05 10:00:00',
        ];

        $dbConnection = $this->createMock(Connection::class);
        $dbConnection->method('transactional')->willReturnCallback(fn(callable $cb) => $cb());
        $dbConnection->method('fetchAllAssociative')->willReturn([$outboxRow]);

        // Neither INSERT into messenger_messages nor UPDATE outbox should be called!
        $dbConnection->expects($this->never())->method('executeStatement');

        $publisher = new OutboxPublisher($dbConnection, $logger);
        $publishedCount = $publisher->publishAvailableMessages(10);

        $this->assertSame(0, $publishedCount, 'Must skip incompatible payload without publishing or marking published');
    }

    public function testRejectsCorruptedJsonPayload(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('invalid or unparsable payload format'));

        $outboxRow = [
            'id' => 4,
            'run_id' => 404,
            'queue_name' => 'catalog',
            'headers' => '{}',
            'payload' => '', // empty corrupted payload
            'available_at' => '2026-11-05 10:00:00',
        ];

        $dbConnection = $this->createMock(Connection::class);
        $dbConnection->method('transactional')->willReturnCallback(fn(callable $cb) => $cb());
        $dbConnection->method('fetchAllAssociative')->willReturn([$outboxRow]);
        $dbConnection->expects($this->never())->method('executeStatement');

        $publisher = new OutboxPublisher($dbConnection, $logger);
        $publishedCount = $publisher->publishAvailableMessages(10);

        $this->assertSame(0, $publishedCount);
    }

    public function testRejectsUnknownPayloadObjectFormat(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('invalid or unparsable payload format'));

        $outboxRow = [
            'id' => 5,
            'run_id' => 505,
            'queue_name' => 'catalog',
            'headers' => '{}',
            'payload' => json_encode(['unknown_key' => 12345]),
            'available_at' => '2026-11-05 10:00:00',
        ];

        $dbConnection = $this->createMock(Connection::class);
        $dbConnection->method('transactional')->willReturnCallback(fn(callable $cb) => $cb());
        $dbConnection->method('fetchAllAssociative')->willReturn([$outboxRow]);
        $dbConnection->expects($this->never())->method('executeStatement');

        $publisher = new OutboxPublisher($dbConnection, $logger);
        $publishedCount = $publisher->publishAvailableMessages(10);

        $this->assertSame(0, $publishedCount);
    }
}
