<?php

declare(strict_types=1);

namespace App\Admin\Dto;

use DateTimeImmutable;

final readonly class QueuesOverviewViewModel
{
    /**
     * @param array<int, array{name: string, size: int, oldest_message_age_seconds: int, status: string}> $queues
     * @param array{
     *     unpublished_count: int,
     *     max_lag_seconds: int,
     *     stale_defers_count: int,
     *     recent_unpublished: OutboxRecentItemView[]
     * } $outbox
     */
    public function __construct(
        public array $queues,
        public array $outbox,
        public bool $workerStarvationDetected,
    ) {
    }
}
