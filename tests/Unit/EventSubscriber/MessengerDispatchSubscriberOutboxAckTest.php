<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\MessengerDispatchSubscriber;
use App\Messenger\ImportRunContext;
use App\Messenger\ImportRunStamp;
use App\Messenger\OutboxMessageStamp;
use App\Repository\ImportRunRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Worker;

final class MessengerDispatchSubscriberOutboxAckTest extends TestCase
{
    public function testOnWorkerRunningAcksByOutboxIdWhenStampIsPresent(): void
    {
        $context = new ImportRunContext();
        $repository = $this->createMock(ImportRunRepository::class);

        $subscriber = new MessengerDispatchSubscriber(
            importRunContext: $context,
            importRunRepository: $repository
        );

        $envelope = (new Envelope(new \stdClass()))
            ->with(new ImportRunStamp(10))
            ->with(new OutboxMessageStamp(42));

        $receivedEvent = new WorkerMessageReceivedEvent($envelope, 'offers');
        $subscriber->onWorkerMessageReceived($receivedEvent);

        $this->assertSame(10, $context->getCurrentRunId());
        $this->assertSame(42, $context->getCurrentOutboxId());

        $context->setHandledSuccessfully(true);

        $repository->expects($this->once())
            ->method('markOutboxAckedById')
            ->with(42);

        $repository->expects($this->never())
            ->method('markOutboxAcked');

        $worker = $this->createMock(Worker::class);
        $runningEvent = new WorkerRunningEvent($worker, false);
        $subscriber->onWorkerRunning($runningEvent);

        // Verify context is thoroughly reset after worker loop iteration
        $this->assertNull($context->getCurrentRunId());
        $this->assertNull($context->getCurrentOutboxId());
        $this->assertFalse($context->isHandledSuccessfully());
    }

    public function testOnWorkerRunningUsesLegacyFallbackWhenOutboxStampIsMissing(): void
    {
        $context = new ImportRunContext();
        $repository = $this->createMock(ImportRunRepository::class);

        $subscriber = new MessengerDispatchSubscriber(
            importRunContext: $context,
            importRunRepository: $repository
        );

        $envelope = (new Envelope(new \stdClass()))
            ->with(new ImportRunStamp(10));

        $receivedEvent = new WorkerMessageReceivedEvent($envelope, 'offers');
        $subscriber->onWorkerMessageReceived($receivedEvent);

        $this->assertSame(10, $context->getCurrentRunId());
        $this->assertNull($context->getCurrentOutboxId());

        $context->setCurrentAttempt(1);
        $context->setHandledSuccessfully(true);

        $repository->expects($this->never())
            ->method('markOutboxAckedById');

        $repository->expects($this->once())
            ->method('markOutboxAcked')
            ->with(10, 1);

        $worker = $this->createMock(Worker::class);
        $runningEvent = new WorkerRunningEvent($worker, false);
        $subscriber->onWorkerRunning($runningEvent);

        $this->assertNull($context->getCurrentRunId());
        $this->assertNull($context->getCurrentAttempt());
        $this->assertFalse($context->isHandledSuccessfully());
    }

    public function testOnWorkerRunningDoesNotAckWhenNotHandledSuccessfully(): void
    {
        $context = new ImportRunContext();
        $repository = $this->createMock(ImportRunRepository::class);

        $subscriber = new MessengerDispatchSubscriber(
            importRunContext: $context,
            importRunRepository: $repository
        );

        $envelope = (new Envelope(new \stdClass()))
            ->with(new ImportRunStamp(10))
            ->with(new OutboxMessageStamp(42));

        $receivedEvent = new WorkerMessageReceivedEvent($envelope, 'offers');
        $subscriber->onWorkerMessageReceived($receivedEvent);

        // Not marked as handled successfully (e.g. failure/rejection)
        $this->assertFalse($context->isHandledSuccessfully());

        $repository->expects($this->never())->method('markOutboxAckedById');
        $repository->expects($this->never())->method('markOutboxAcked');

        $worker = $this->createMock(Worker::class);
        $runningEvent = new WorkerRunningEvent($worker, false);
        $subscriber->onWorkerRunning($runningEvent);

        // Context still properly reset
        $this->assertNull($context->getCurrentOutboxId());
    }

    public function testOutboxMessageStampPreservedAcrossEnvelopeWithStampsForRetry(): void
    {
        $envelope = (new Envelope(new \stdClass()))
            ->with(new ImportRunStamp(10))
            ->with(new OutboxMessageStamp(42));

        // Simulate Symfony Messenger adding RedeliveryStamp during retry
        $redeliveryStamp = new \Symfony\Component\Messenger\Stamp\RedeliveryStamp(1);
        $retriedEnvelope = $envelope->with($redeliveryStamp);

        $this->assertNotNull($retriedEnvelope->last(OutboxMessageStamp::class));
        $this->assertSame(42, $retriedEnvelope->last(OutboxMessageStamp::class)->getOutboxId());
        $this->assertSame(1, $retriedEnvelope->last(\Symfony\Component\Messenger\Stamp\RedeliveryStamp::class)->getRetryCount());
    }
}
