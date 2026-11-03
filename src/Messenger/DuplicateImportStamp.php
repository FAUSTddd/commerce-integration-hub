<?php

declare(strict_types=1);

namespace App\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Marks an Envelope that was skipped during dispatch because an active (PENDING or PROCESSING)
 * ImportRun for the same file type and filename already exists.
 */
final readonly class DuplicateImportStamp implements StampInterface
{
    public function __construct(
        public string $type,
        public string $filename,
        public ?int $existingRunId = null,
    ) {}
}
