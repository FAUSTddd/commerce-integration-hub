<?php
declare(strict_types=1);

namespace App\Monitoring\Dto;

readonly class QueueMetricSnapshot
{
    public function __construct(
        public string $queueName,
        public int $pendingCount,
        public int $inFlightCount,
        public int $delayedCount,
        public ?int $oldestMessageAgeSeconds
    ) {}
}
