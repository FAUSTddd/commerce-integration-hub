<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Entity\ImportRun;
use App\Repository\ImportRunRepository;
use FaustDDD\Symfony1cImport\Application\Command\ImportCatalogCommand;
use FaustDDD\Symfony1cImport\Application\Command\ImportOffersCommand;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Throwable;

/**
 * Ensures strict dispatch-level deduplication for CommerceML catalog and offers imports.
 *
 * Prevents multiple active (PENDING/PROCESSING) ImportRun entities and duplicate
 * messages in transport queues when concurrent HTTP/CLI requests are dispatched.
 */
final class DeduplicateImportMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ImportRunRepository $importRunRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // 1. If an ImportRunStamp is already attached (e.g. worker retries or explicit binding), bypass deduplication
        if ($envelope->last(ImportRunStamp::class) !== null) {
            return $stack->next()->handle($envelope, $stack);
        }

        $message = $envelope->getMessage();
        $type = null;
        $filePath = null;

        if ($message instanceof ImportCatalogCommand) {
            $type = 'catalog';
            $filePath = $message->filePath;
        } elseif ($message instanceof ImportOffersCommand) {
            $type = 'offers';
            $filePath = $message->filePath;
        }

        // If not a CommerceML import command, pass transparently to next middleware
        if ($type === null || $filePath === null) {
            return $stack->next()->handle($envelope, $stack);
        }

        $filename = basename($filePath);
        $fileHash = is_file($filePath) ? (string) sha1_file($filePath) : 'pending_hash';

        // 2. Attempt atomic creation of active run via DBAL ON CONFLICT
        $runId = $this->importRunRepository->tryCreateActiveRun($type, $filename, $fileHash);

        // 3. Short-circuit if an active run already exists
        if ($runId === null) {
            $activeRun = $this->importRunRepository->findActiveRun($type, $filename);
            $existingId = $activeRun?->getId();

            $this->logger->info('Duplicate import dispatch skipped: active run already exists', [
                'type' => $type,
                'filename' => $filename,
                'existing_run_id' => $existingId,
            ]);

            // Return envelope with DuplicateImportStamp without passing down to SendMessageMiddleware
            return $envelope->with(new DuplicateImportStamp($type, $filename, $existingId));
        }

        // 4. Attach the allocated ImportRunStamp and proceed down the middleware stack
        $envelopeWithStamp = $envelope->with(new ImportRunStamp($runId));

        try {
            return $stack->next()->handle($envelopeWithStamp, $stack);
        } catch (Throwable $e) {
            // 5. Compensation: verify if message physically reached transport before failing the run
            if (!$this->importRunRepository->hasMessageForRunId($runId)) {
                $this->importRunRepository->compensateFailedEnqueue(
                    $runId,
                    'Failed to enqueue message: ' . mb_substr($e->getMessage(), 0, 1000)
                );
            } else {
                $this->logger->critical(
                    'Transport error occurred after message was committed to queue. ImportRun preserved in PENDING state.',
                    [
                        'run_id' => $runId,
                        'exception' => $e->getMessage(),
                    ]
                );
            }

            throw $e;
        }
    }
}
