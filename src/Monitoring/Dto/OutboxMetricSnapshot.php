<?php
declare(strict_types=1);

namespace App\Monitoring\Dto;

readonly class OutboxMetricSnapshot
{
    public function __construct(
        public int $unpublishedCount,
        public int $deferredRetryCount,
        public ?int $oldestUnpublishedAgeSeconds,
        public ?\DateTimeImmutable $lastPublishedAt
    ) {}
}
