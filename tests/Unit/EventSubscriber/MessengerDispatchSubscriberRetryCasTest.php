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
use Symfony\Component\Messenger\Stamp\DelayStamp;

final class MessengerDispatchSubscriberRetryCasTest extends TestCase
{
    public function testCas4aSuccessDoesNotLogCriticalWarning(): void
    {
        $repo = $this->createMock(ImportRunRepository::class);
        $context = new ImportRunContext();
        $logger = $this->createMock(LoggerInterface::class);

        $runId = 123;
        $attempt = 2;
        $token = 'a1b2c3d4-0000-4000-8000-0123456789ab';
        $receiverName = 'async_offers';

        $context->setCurrentRunId($runId);
        $context->setCurrentAttempt($attempt);
        $context->setCurrentFencingToken($token);

        $repo->expects($this->once())
            ->method('markRunForRetryCas4A')
            ->with($runId, 5, $attempt, $token)
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
            ->with(new DelayStamp(5000));

        $event = new WorkerMessageFailedEvent($envelope, $receiverName, new \RuntimeException('Temporary failure'));
        $event->setForRetry();

        $subscriber->onWorkerMessageFailed($event);
    }

    public function testCas4aFailureLogsStructuredCriticalMessageWithoutSensitiveData(): void
    {
        $repo = $this->createMock(ImportRunRepository::class);
        $context = new ImportRunContext();
        $logger = $this->createMock(LoggerInterface::class);

        $runId = 456;
        $attempt = 3;
        $token = 'e5f6a7b8-1111-4222-8333-abcdefabcdef';
        $receiverName = 'async_offers';

        $context->setCurrentRunId($runId);
        $context->setCurrentAttempt($attempt);
        $context->setCurrentFencingToken($token);

        $repo->expects($this->once())
            ->method('markRunForRetryCas4A')
            ->with($runId, 10, $attempt, $token)
            ->willReturn(false);

        $logger->expects($this->once())
            ->method('critical')
            ->with(
                'CAS4A retry transition precondition failed: ImportRun state altered concurrently',
                [
                    'run_id' => $runId,
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
            ->with(new DelayStamp(10000));

        $event = new WorkerMessageFailedEvent($envelope, $receiverName, new \RuntimeException('Network timeout'));
        $event->setForRetry();

        $subscriber->onWorkerMessageFailed($event);
    }
}
