<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\ImportRun;
use App\Messenger\ImportRunContext;
use App\Messenger\ImportRunStamp;
use App\Messenger\OutboxMessageStamp;
use App\Repository\ImportRunRepository;
use FaustDDD\Symfony1cImport\Application\Command\ImportCatalogCommand;
use FaustDDD\Symfony1cImport\Application\Command\ImportOffersCommand;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Manages ImportRun persistence and its binding to Messenger Envelopes across transports and retries.
 */
final class MessengerDispatchSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ImportRunRepository $importRunRepository,
        private readonly ImportRunContext $importRunContext,
        private readonly ?\App\Doctrine\ExecutionGuard\ExecutionGuardState $guardState = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            SendMessageToTransportsEvent::class => 'onSendMessage',
            WorkerMessageReceivedEvent::class => 'onWorkerMessageReceived',
            WorkerMessageHandledEvent::class => 'onWorkerMessageHandled',
            WorkerMessageFailedEvent::class => ['onWorkerMessageFailed', 50],
            WorkerRunningEvent::class => 'onWorkerRunning',
        ];
    }

    public function onSendMessage(SendMessageToTransportsEvent $event): void
    {
    }

    public function onWorkerMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $stamp = $event->getEnvelope()->last(ImportRunStamp::class);
        $this->importRunContext->setCurrentRunId($stamp?->getImportRunId());

        $outboxStamp = $event->getEnvelope()->last(OutboxMessageStamp::class);
        $this->importRunContext->setCurrentOutboxId($outboxStamp?->getOutboxId());

        // Start fresh execution epoch in guard state
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $epoch = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
        $this->guardState?->startEpoch($epoch);
    }

    public function onWorkerMessageHandled(WorkerMessageHandledEvent $event): void
    {
        // NOTE: Handled event fires BEFORE Worker::ack().
        // Mark that handler succeeded so onWorkerRunning knows to mark outbox as acked
        $this->importRunContext->setHandledSuccessfully(true);

        if (!$this->importRunContext->isAdvisoryLockHeld()) {
            $this->importRunContext->reset();
        }
    }

    public function onWorkerMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $stamp = $event->getEnvelope()->last(ImportRunStamp::class);
        $runId = $stamp?->getImportRunId() ?? $this->importRunContext->getCurrentRunId();

        if ($runId === null) {
            $this->importRunContext->reset();
            return;
        }

        $attempt = $this->importRunContext->getCurrentAttempt() ?? 1;
        $token = $this->importRunContext->getCurrentFencingToken();

        if ($event->willRetry()) {
            // CAS4A: Calculate delay seconds
            $envelope = $event->getEnvelope();
            $delayStamp = $envelope->last(DelayStamp::class);
            $delaySeconds = $delayStamp !== null ? (int) ceil($delayStamp->getDelay() / 1000) : 5;

            // Mark for retry
            $updated = $this->importRunRepository->markRunForRetryCas4A(
                $runId,
                $delaySeconds,
                $attempt,
                $token
            );

            if (!$updated) {
                $this->logger?->critical('CAS4A retry transition precondition failed: ImportRun state altered concurrently', [
                    'run_id' => $runId,
                    'expected_attempt' => $attempt,
                    'fencing_token' => $token,
                    'receiver_name' => $event->getReceiverName(),
                ]);
            }
        } else {
            // CAS4B: Retries exhausted or non-retryable
            $exception = $event->getThrowable();
            $errorMsg = $exception->getMessage();
            if ($exception->getPrevious() !== null) {
                $errorMsg .= ' (Previous: ' . $exception->getPrevious()->getMessage() . ')';
            }
            $this->importRunRepository->markRunExhaustedFailedCas4B($runId, $errorMsg, $attempt, $token);
        }

        if (!$this->importRunContext->isAdvisoryLockHeld()) {
            $this->importRunContext->reset();
        }
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        // Fires strictly AFTER Worker::ack() or Worker::reject()
        $runId = $this->importRunContext->getCurrentRunId();
        $outboxId = $this->importRunContext->getCurrentOutboxId();
        $attempt = $this->importRunContext->getCurrentAttempt();

        // If message was handled and ACKed successfully, mark outbox entry as acked
        if ($this->importRunContext->isHandledSuccessfully()) {
            if ($outboxId !== null) {
                // Primary path: exact ACK by Outbox primary key
                $this->importRunRepository->markOutboxAckedById($outboxId);
            } elseif ($runId !== null && $attempt !== null) {
                // Isolated legacy fallback: for inflight messages published without OutboxMessageStamp
                $this->importRunRepository->markOutboxAcked($runId, $attempt);
            }
        }

        if ($runId !== null && $this->importRunContext->isAdvisoryLockHeld()) {
            $this->importRunRepository->releaseSessionAdvisoryLock($runId);
        }

        // Reset execution guard and context for next queue iteration
        $this->guardState?->reset();
        $this->importRunContext->reset();
    }
}
