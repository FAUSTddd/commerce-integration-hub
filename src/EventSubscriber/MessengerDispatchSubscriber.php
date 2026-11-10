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
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

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
            WorkerMessageRetriedEvent::class => 'onWorkerMessageRetried',
            WorkerRunningEvent::class => 'onWorkerRunning',
        ];
    }

    public function onSendMessage(SendMessageToTransportsEvent $event): void
    {
    }

    public function onWorkerMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        // Defensive cleanup: release lingering advisory lock before resetting context
        if ($this->importRunContext->isAdvisoryLockHeld() && $this->importRunContext->getCurrentRunId() !== null) {
            try {
                $released = $this->importRunRepository->releaseSessionAdvisoryLock($this->importRunContext->getCurrentRunId());
                if (!$released) {
                    $this->logger?->warning('Lingering advisory lock release returned false on new message', [
                        'run_id' => $this->importRunContext->getCurrentRunId(),
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('Failed to release lingering advisory lock on new message', ['error' => $e->getMessage()]);
            }
        }
        $this->importRunContext->reset();

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
        $outboxId = $this->importRunContext->getCurrentOutboxId();
        $runId = $this->importRunContext->getCurrentRunId();
        $attempt = $this->importRunContext->getCurrentAttempt();

        if ($outboxId !== null) {
            // Primary path: exact ACK by Outbox primary key
            $this->importRunRepository->markOutboxAckedById($outboxId);
        } elseif ($runId !== null && $attempt !== null) {
            // Isolated legacy fallback: for inflight messages published without OutboxMessageStamp
            $this->importRunRepository->markOutboxAcked($runId, $attempt);
        }

        // NOTE: Handled event fires BEFORE Worker::ack().
        // Mark that handler succeeded so context reflects success
        $this->importRunContext->setHandledSuccessfully(true);
    }

    public function onWorkerMessageRetried(WorkerMessageRetriedEvent $event): void
    {
        $runId = $this->importRunContext->getCurrentRunId();
        $attempt = $this->importRunContext->getCurrentAttempt();
        $token = $this->importRunContext->getCurrentFencingToken();

        if ($runId !== null && $attempt !== null && $token !== null) {
            $envelope = $event->getEnvelope();
            $transportMessageId = $envelope->last(TransportMessageIdStamp::class)?->getId();

            if ($transportMessageId === null) {
                $this->logger?->critical('CAS4A retry transition skipped: TransportMessageIdStamp missing from retried envelope', [
                    'run_id' => $runId,
                    'expected_attempt' => $attempt,
                    'receiver_name' => $event->getReceiverName(),
                ]);
                return;
            }

            $updated = $this->importRunRepository->markRunForRetryCas4A(
                $runId,
                $transportMessageId,
                $attempt,
                $token
            );

            if (!$updated) {
                $this->logger?->critical('CAS4A retry transition precondition failed: ImportRun state altered concurrently or message missing', [
                    'run_id' => $runId,
                    'messenger_message_id' => $transportMessageId,
                    'expected_attempt' => $attempt,
                    'fencing_token' => $token,
                    'receiver_name' => $event->getReceiverName(),
                ]);
            }
        } else {
            $this->logger?->warning('Skipping CAS4A retry transition: context incomplete', [
                'run_id' => $runId,
                'attempt' => $attempt,
                'fencing_token' => $token,
                'receiver_name' => $event->getReceiverName(),
            ]);
        }
    }

    public function onWorkerMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $runId = $event->getEnvelope()->last(ImportRunStamp::class)?->getImportRunId() ?? $this->importRunContext->getCurrentRunId();
        $attempt = $this->importRunContext->getCurrentAttempt();
        $token = $this->importRunContext->getCurrentFencingToken();

        if ($runId === null) {
            return;
        }

        // CAS4B: Retries exhausted or non-retryable
        $exception = $event->getThrowable();
        $errorMsg = $exception->getMessage();
        if ($exception->getPrevious() !== null) {
            $errorMsg .= ' (Previous: ' . $exception->getPrevious()->getMessage() . ')';
        }

        if ($attempt !== null && $token !== null) {
            $this->importRunRepository->markRunExhaustedFailedCas4B($runId, $errorMsg, $attempt, $token);
        } else {
            $this->logger?->warning('Skipping CAS4B exhausted failure transition: attempt or fencing token not confirmed', [
                'run_id' => $runId,
                'attempt' => $attempt,
                'fencing_token' => $token,
                'receiver_name' => $event->getReceiverName(),
            ]);
        }
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        // Fires strictly AFTER Worker::ack() or Worker::reject()
        $runId = $this->importRunContext->getCurrentRunId();

        try {
            if ($runId !== null && $this->importRunContext->isAdvisoryLockHeld()) {
                $released = $this->importRunRepository->releaseSessionAdvisoryLock($runId);
                if (!$released) {
                    $this->logger?->warning('Session advisory lock release returned false in onWorkerRunning', [
                        'run_id' => $runId,
                    ]);
                }
            }
        } finally {
            // Reset execution guard and context for next queue iteration
            $this->guardState?->reset();
            $this->importRunContext->reset();
        }
    }
}
