<?php
declare(strict_types=1);

namespace App\Monitoring\Dto;

readonly class ImportRunSnapshot
{
    public function __construct(
        public int $id,
        public string $type,
        public string $status,
        public int $attempt,
        public int $durationSeconds,
        public bool $isStale,
        public string $filenameClean,
        public ?string $lastErrorSanitized
    ) {}
}
