<?php

declare(strict_types=1);

namespace App\Admin\Dto;

use DateTimeImmutable;

final readonly class OutboxRecentItemView
{
    public function __construct(
        public int $id,
        public int $runId,
        public int $attempt,
        public string $kind,
        public string $queueName,
        public DateTimeImmutable $availableAt,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
