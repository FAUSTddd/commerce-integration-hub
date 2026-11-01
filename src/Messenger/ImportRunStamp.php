<?php

declare(strict_types=1);

namespace App\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Persists the linked ImportRun ID across Messenger transports, retries, and failure recovery.
 */
final class ImportRunStamp implements StampInterface
{
    public function __construct(
        private readonly int $importRunId,
    ) {}

    public function getImportRunId(): int
    {
        return $this->importRunId;
    }
}
