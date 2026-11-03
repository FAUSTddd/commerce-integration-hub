<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\ImportRun;
use App\Messenger\ImportRunContext;
use App\Messenger\ImportRunStamp;
use App\Repository\ImportRunRepository;
use FaustDDD\Symfony1cImport\Application\Command\ImportCatalogCommand;
use FaustDDD\Symfony1cImport\Application\Command\ImportOffersCommand;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * Manages ImportRun persistence and its binding to Messenger Envelopes across transports and retries.
 */
final class MessengerDispatchSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ImportRunRepository $importRunRepository,
        private readonly ImportRunContext $importRunContext,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            SendMessageToTransportsEvent::class => 'onSendMessage',
            WorkerMessageReceivedEvent::class => 'onWorkerMessageReceived',
            WorkerMessageHandledEvent::class => 'onWorkerMessageHandled',
            WorkerMessageFailedEvent::class => 'onWorkerMessageFailed',
        ];
    }

    public function onSendMessage(SendMessageToTransportsEvent $event): void
    {
        // ImportRun allocation, deduplication, and envelope stamp decoration
        // are handled centrally and authoritatively by DeduplicateImportMiddleware.
        // No-op to avoid implicit or ambiguous run association.
    }

    public function onWorkerMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $stamp = $event->getEnvelope()->last(ImportRunStamp::class);
        $this->importRunContext->setCurrentRunId($stamp?->getImportRunId());
    }

    public function onWorkerMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $this->importRunContext->reset();
    }

    public function onWorkerMessageFailed(WorkerMessageFailedEvent $event): void
    {
        // When Messenger determines the message will NOT be retried (exhausted retries or non-retryable)
        if (!$event->willRetry()) {
            $stamp = $event->getEnvelope()->last(ImportRunStamp::class);
            $runId = $stamp?->getImportRunId() ?? $this->importRunContext->getCurrentRunId();

            if ($runId !== null) {
                $run = $this->importRunRepository->find($runId);
                if ($run !== null && $run->getStatus() !== ImportRun::STATUS_SUCCESS) {
                    $run->setStatus(ImportRun::STATUS_FAILED);
                    $run->setFinishedAt(new \DateTimeImmutable());
                    $exception = $event->getThrowable();
                    $errorMsg = $exception->getMessage();
                    if ($exception->getPrevious() !== null) {
                        $errorMsg .= ' (Previous: ' . $exception->getPrevious()->getMessage() . ')';
                    }
                    $run->setErrorMessage($errorMsg);
                    $this->importRunRepository->save($run);
                }
            }
        }

        $this->importRunContext->reset();
    }
}
