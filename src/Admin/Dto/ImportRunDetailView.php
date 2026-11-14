<?php

declare(strict_types=1);

namespace App\Admin\Dto;

use DateTimeImmutable;

final readonly class ImportRunDetailView
{
    /**
     * @param array<int, OutboxEventItemView> $outboxEvents
     */
    public function __construct(
        public int $id,
        public string $type,
        public string $filename,
        public string $fileHash,
        public string $status,
        public int $attempt,
        public ?string $fencingToken,
        public int $itemsCount,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public ?DateTimeImmutable $retryScheduledAt,
        public bool $hasDeferredRetry,
        public ?string $sanitizedError,
        public array $outboxEvents = [],
    ) {
    }
}
