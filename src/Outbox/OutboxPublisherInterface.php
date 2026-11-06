<?php

declare(strict_types=1);

namespace App\Outbox;

interface OutboxPublisherInterface
{
    /**
     * Publishes pending outbox messages whose available_at <= NOW().
     *
     * @param int $limit Maximum number of messages to process in a single transaction
     * @param int|null $forRunId Optional target run ID filter for strict isolated execution
     * @return int Number of messages successfully published
     */
    public function publishAvailableMessages(int $limit = 50, ?int $forRunId = null): int;
}
