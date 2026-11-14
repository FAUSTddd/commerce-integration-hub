<?php

declare(strict_types=1);

namespace App\Admin\Dto;

use DateTimeImmutable;

final readonly class ImportRunItemView
{
    public function __construct(
        public int $id,
        public string $type,
        public string $filename,
        public string $status,
        public int $attempt,
        public int $itemsCount,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public bool $hasError,
    ) {
    }
}
