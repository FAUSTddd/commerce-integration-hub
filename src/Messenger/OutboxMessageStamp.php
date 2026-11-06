<?php

declare(strict_types=1);

namespace App\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Persists the linked Outbox primary key ID across Messenger transports and worker retries.
 */
final class OutboxMessageStamp implements StampInterface
{
    public function __construct(
        private readonly int $outboxId,
    ) {}

    public function getOutboxId(): int
    {
        return $this->outboxId;
    }
}
