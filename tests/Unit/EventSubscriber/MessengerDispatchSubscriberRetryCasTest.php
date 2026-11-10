<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\MessengerDispatchSubscriber;
use App\Messenger\ImportRunContext;
use App\Messenger\ImportRunStamp;
use App\Repository\ImportRunRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

final class MessengerDispatchSubscriberRetryCasTest extends TestCase
{
    public function testCas4aSuccessOnWorkerMessageRetried(): void
    {
        $repo = $this->createMock(ImportRunRepository::class);
        $context = new ImportRunContext();
        $logger = $this->createMock(LoggerInterface::class);

        $runId = 123;
        $attempt = 2;
        $token = 'a1b2c3d4-0000-4000-8000-0123456789ab';
        $receiverName = 'async_offers';
        $transportMessageId = 789;

        $context->setCurrentRunId($runId);
        $context->setCurrentAttempt($attempt);
        $context->setCurrentFencingToken($token);

        $repo->expects($this->once())
            ->method('markRunForRetryCas4A')
            ->with($runId, $transportMessageId, $attempt, $token)
            ->willReturn(true);

        $logger->expects($this->never())
            ->method('critical');

        $subscriber = new MessengerDispatchSubscriber(
            $repo,
            $context,
            null,
            $logger
        );

        $message = new \stdClass();
        $envelope = (new Envelope($message))
            ->with(new ImportRunStamp($runId))
            ->with(new TransportMessageIdStamp($transportMessageId));

        $event = new WorkerMessageRetriedEvent($envelope, $receiverName);

        $subscriber->onWorkerMessageRetried($event);
    }

    public function testCas4aFailureLogsStructuredCriticalWhenRepoReturnsFalse(): void
    {
        $repo = $this->createMock(ImportRunRepository::class);
        $context = new ImportRunContext();
        $logger = $this->createMock(LoggerInterface::class);

        $runId = 456;
        $attempt = 3;
        $token = 'e5f6a7b8-1111-4222-8333-abcdefabcdef';
        $receiverName = 'async_offers';
        $transportMessageId = 999;

        $context->setCurrentRunId($runId);
        $context->setCurrentAttempt($attempt);
        $context->setCurrentFencingToken($token);

        $repo->expects($this->once())
            ->method('markRunForRetryCas4A')
            ->with($runId, $transportMessageId, $attempt, $token)
            ->willReturn(false);

        $logger->expects($this->once())
            ->method('critical')
            ->with(
                'CAS4A retry transition precondition failed: ImportRun state altered concurrently or message missing',
                [
                    'run_id' => $runId,
                    'messenger_message_id' => $transportMessageId,
                    'expected_attempt' => $attempt,
                    'fencing_token' => $token,
                    'receiver_name' => $receiverName,
                ]
            );

        $subscriber = new MessengerDispatchSubscriber(
            $repo,
            $context,
            null,
            $logger
        );

        $message = new \stdClass();
        $envelope = (new Envelope($message))
            ->with(new ImportRunStamp($runId))
            ->with(new TransportMessageIdStamp($transportMessageId));

        $event = new WorkerMessageRetriedEvent($envelope, $receiverName);

        $subscriber->onWorkerMessageRetried($event);
    }

    public function testCas4aSkipsAndLogsWhenTransportMessageIdStampMissing(): void
    {
        $repo = $this->createMock(ImportRunRepository::class);
        $context = new ImportRunContext();
        $logger = $this->createMock(LoggerInterface::class);

        $runId = 789;
        $attempt = 1;
        $token = 'f1e2d3c4-9999-4000-8000-112233445566';
        $receiverName = 'async_offers';

        $context->setCurrentRunId($runId);
        $context->setCurrentAttempt($attempt);
        $context->setCurrentFencingToken($token);

        $repo->expects($this->never())
            ->method('markRunForRetryCas4A');

        $logger->expects($this->once())
            ->method('critical')
            ->with(
                'CAS4A retry transition skipped: TransportMessageIdStamp missing from retried envelope',
                [
                    'run_id' => $runId,
                    'expected_attempt' => $attempt,
                    'receiver_name' => $receiverName,
                ]
            );

        $subscriber = new MessengerDispatchSubscriber(
            $repo,
            $context,
            null,
            $logger
        );

        $message = new \stdClass();
        $envelope = (new Envelope($message))
            ->with(new ImportRunStamp($runId));

        $event = new WorkerMessageRetriedEvent($envelope, $receiverName);

        $subscriber->onWorkerMessageRetried($event);
    }

    public function testCas4bTriggeredOnWorkerMessageFailedWhenNotRetrying(): void
    {
        $repo = $this->createMock(ImportRunRepository::class);
        $context = new ImportRunContext();
        $logger = $this->createMock(LoggerInterface::class);

        $runId = 101;
        $attempt = 3;
        $token = 'c1c2c3c4-0000-4000-8000-000000000001';
        $receiverName = 'async_offers';

        $context->setCurrentRunId($runId);
        $context->setCurrentAttempt($attempt);
        $context->setCurrentFencingToken($token);

        $repo->expects($this->once())
            ->method('markRunExhaustedFailedCas4B')
            ->with($runId, 'Permanent failure', $attempt, $token);

        $subscriber = new MessengerDispatchSubscriber(
            $repo,
            $context,
            null,
            $logger
        );

        $message = new \stdClass();
        $envelope = (new Envelope($message))
            ->with(new ImportRunStamp($runId));

        $event = new WorkerMessageFailedEvent($envelope, $receiverName, new \RuntimeException('Permanent failure'));

        $subscriber->onWorkerMessageFailed($event);
    }
}
