<?php

declare(strict_types=1);

namespace App\Outbox;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class OutboxPublisher implements OutboxPublisherInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?\Symfony\Component\Messenger\Transport\Serialization\SerializerInterface $serializer = null,
    ) {}

    /**
     * Publishes pending outbox messages whose available_at <= NOW().
     * Uses SELECT ... FOR UPDATE SKIP LOCKED to prevent duplicate processing by parallel publishers.
     * Moves messages into messenger_messages and marks published_at = NOW() in the same transaction.
     *
     * @param int $limit Maximum number of messages to process in a single transaction
     * @param int|null $forRunId Optional target run ID filter for strict isolated execution
     * @return int Number of messages successfully published
     */
    public function publishAvailableMessages(int $limit = 50, ?int $forRunId = null): int
    {
        return (int) $this->connection->transactional(function () use ($limit, $forRunId): int {
            // 1. Lock available outbox rows
            $runIdCondition = $forRunId !== null ? '  AND run_id = :forRunId' : '';
            $sqlSelect = <<<SQL
                SELECT id, run_id, attempt, kind, queue_name, headers, payload, available_at
                FROM import_run_outbox
                WHERE published_at IS NULL
                  AND available_at <= NOW()
                {$runIdCondition}
                ORDER BY available_at ASC
                LIMIT :limit
                FOR UPDATE SKIP LOCKED
            SQL;

            $params = ['limit' => $limit];
            $types = ['limit' => \Doctrine\DBAL\ParameterType::INTEGER];
            if ($forRunId !== null) {
                $params['forRunId'] = $forRunId;
                $types['forRunId'] = \Doctrine\DBAL\ParameterType::INTEGER;
            }

            $rows = $this->connection->fetchAllAssociative($sqlSelect, $params, $types);
            if (empty($rows)) {
                return 0;
            }

            $count = 0;
            $activeSerializer = $this->serializer ?? new \Symfony\Component\Messenger\Transport\Serialization\PhpSerializer();

            foreach ($rows as $row) {
                $outboxId = (int) $row['id'];
                $runId = (int) $row['run_id'];
                $queueName = (string) $row['queue_name'];

                // Decode payload from DBAL representation
                $rawPayload = $row['payload'];
                $payloadData = is_string($rawPayload) ? json_decode($rawPayload, true) : $rawPayload;

                $body = null;
                if (is_array($payloadData)) {
                    // Standard contract: JSONB object with "body" key containing exact serialized Envelope
                    if (isset($payloadData['body']) && is_string($payloadData['body'])) {
                        $body = $payloadData['body'];
                    } elseif (isset($payloadData['payload']) && is_string($payloadData['payload'])) {
                        // Legacy test fixture format
                        $body = $payloadData['payload'];
                    } elseif (isset($payloadData['serialized']) && is_string($payloadData['serialized'])) {
                        // Explicitly check and reject raw serialized command payload
                        $this->logger?->error(sprintf(
                            'Outbox message id %d payload contains raw serialized command instead of Messenger Envelope. Skipping.',
                            $outboxId
                        ));
                        continue;
                    }
                } elseif (is_string($payloadData) && $payloadData !== '') {
                    $body = $payloadData;
                }

                // Explicit validation: reject invalid or legacy serialized($command) payloads without publishing
                if ($body === null || $body === '') {
                    $this->logger?->error(sprintf('Outbox message id %d has invalid or unparsable payload format. Skipping.', $outboxId));
                    continue;
                }

                // Decode headers from DBAL representation
                $rawHeaders = $row['headers'];
                $headersData = is_string($rawHeaders) ? json_decode($rawHeaders, true) : $rawHeaders;
                $headers = json_encode(is_array($headersData) ? $headersData : [], JSON_THROW_ON_ERROR);

                // Ensure body is compatible with Symfony Messenger Envelope deserialization,
                // and attach OutboxMessageStamp so worker can perform exact ACK by primary key ID.
                try {
                    $decodedEnvelope = $activeSerializer->decode(['body' => $body, 'headers' => is_array($headersData) ? $headersData : []]);
                    $stampedEnvelope = $decodedEnvelope->with(new \App\Messenger\OutboxMessageStamp($outboxId));
                    $reEncoded = $activeSerializer->encode($stampedEnvelope);
                    $body = $reEncoded['body'];
                    if (isset($reEncoded['headers'])) {
                        $encodedHeaders = is_array($headersData) ? $headersData : [];
                        $headersData = array_merge($encodedHeaders, $reEncoded['headers']);
                        $headers = json_encode($headersData, JSON_THROW_ON_ERROR);
                    }
                } catch (\Throwable $e) {
                    $this->logger?->error(sprintf(
                        'Outbox message id %d payload is incompatible with Messenger Envelope (e.g. raw serialized command or corrupted data): %s. Skipping.',
                        $outboxId,
                        $e->getMessage()
                    ));
                    continue;
                }

                // Acquire transaction-level advisory lock non-blockingly to synchronize with CAS5 recovery and active workers.
                // If held by worker or recovery, skip row in this transaction; row lock will be freed on COMMIT.
                $acquired = (bool) $this->connection->fetchOne('SELECT pg_try_advisory_xact_lock(:id)', ['id' => $runId]);
                if (!$acquired) {
                    $this->logger?->debug(sprintf('Advisory lock for run_id %d busy. Skipping outbox id %d for next cycle.', $runId, $outboxId));
                    continue;
                }

                // 2. Insert into messenger_messages
                $sqlInsertMessage = <<<SQL
                    INSERT INTO messenger_messages (
                        body,
                        headers,
                        queue_name,
                        created_at,
                        available_at
                    ) VALUES (
                        :body,
                        :headers,
                        :queueName,
                        NOW(),
                        NOW()
                    )
                SQL;

                $this->connection->executeStatement($sqlInsertMessage, [
                    'body' => $body,
                    'headers' => $headers,
                    'queueName' => $queueName,
                ]);

                // 3. Mark outbox entry as published
                $sqlUpdateOutbox = <<<SQL
                    UPDATE import_run_outbox
                    SET published_at = NOW()
                    WHERE id = :id
                SQL;

                $this->connection->executeStatement($sqlUpdateOutbox, ['id' => $outboxId]);
                $count++;
            }

            $this->logger?->info(sprintf('Outbox publisher transferred %d messages to messenger_messages', $count));

            return $count;
        });
    }
}
