<?php
declare(strict_types=1);

namespace App\Monitoring\Dto;

readonly class SystemHealthOverview
{
    /**
     * @param ImportRunSnapshot[] $activeRuns
     * @param QueueMetricSnapshot[] $queueMetrics
     * @param string[] $anomalies
     */
    public function __construct(
        public string $status, // HEALTHY | DEGRADED | CRITICAL
        public \DateTimeImmutable $timestamp,
        public array $activeRuns,
        public array $queueMetrics,
        public ?OutboxMetricSnapshot $outboxMetrics,
        public array $anomalies
    ) {}
}
