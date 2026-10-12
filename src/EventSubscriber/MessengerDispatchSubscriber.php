<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\ImportRun;
use App\Repository\ImportRunRepository;
use FaustDDD\Symfony1cImport\Application\Command\ImportCatalogCommand;
use FaustDDD\Symfony1cImport\Application\Command\ImportOffersCommand;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;

/**
 * Ensures an ImportRun record with status PENDING is persisted before the message hits the async queue.
 */
final class MessengerDispatchSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ImportRunRepository $importRunRepository,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            SendMessageToTransportsEvent::class => 'onSendMessage',
        ];
    }

    public function onSendMessage(SendMessageToTransportsEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();

        $type = null;
        $filePath = null;

        if ($message instanceof ImportCatalogCommand) {
            $type = 'catalog';
            $filePath = $message->filePath;
        } elseif ($message instanceof ImportOffersCommand) {
            $type = 'offers';
            $filePath = $message->filePath;
        }

        if ($type === null || $filePath === null) {
            return;
        }

        $filename = basename($filePath);
        $fileHash = is_file($filePath) ? (string) sha1_file($filePath) : 'pending_hash';

        // Check if there is already an active (PENDING/PROCESSING) run
        $existing = $this->importRunRepository->findActiveRun($type, $filename);
        if ($existing !== null) {
            return;
        }

        $run = new ImportRun($type, $filename, $fileHash);
        $this->importRunRepository->save($run);
    }
}
