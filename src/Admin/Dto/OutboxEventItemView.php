<?php

declare(strict_types=1);

namespace App\Admin\Dto;

use DateTimeImmutable;

final readonly class OutboxEventItemView
{
    public function __construct(
        public int $id,
        public int $attempt,
        public string $kind,
        public string $idempotencyKey,
        public string $queueName,
        public DateTimeImmutable $availableAt,
        public ?DateTimeImmutable $publishedAt,
        public ?DateTimeImmutable $ackedAt,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
