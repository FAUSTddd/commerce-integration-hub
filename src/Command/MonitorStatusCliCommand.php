<?php

declare(strict_types=1);

namespace App\Command;

use App\Monitoring\Sanitizer\MonitoringDataSanitizer;
use App\Monitoring\Service\SystemHealthMonitorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'monitor:status',
    description: 'Displays the operational status and health metrics of Commerce Integration Hub.',
    aliases: ['app:monitoring:status']
)]
final class MonitorStatusCliCommand extends Command
{
    public const EXIT_HEALTHY_OR_DEGRADED = 0;
    public const EXIT_COMMAND_ERROR = 1;
    public const EXIT_CRITICAL = 2;

    public function __construct(
        private readonly SystemHealthMonitorService $monitorService,
        private readonly MonitoringDataSanitizer $sanitizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Output format: txt or json',
                'txt'
            )
            ->addOption(
                'window-hours',
                null,
                InputOption::VALUE_REQUIRED,
                'Time window in hours for ImportRun metrics aggregation',
                '24'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');
        if (!in_array($format, ['txt', 'json'], true)) {
            $output->writeln(sprintf('<error>Invalid format "%s". Supported formats: txt, json.</error>', $format));
            return self::EXIT_COMMAND_ERROR;
        }

        try {
            $overview = $this->monitorService->getSystemOverview();
        } catch (Throwable $e) {
            $sanitizedError = $this->sanitizer->sanitizeError($e->getMessage()) ?? 'Unknown internal error';
            if ($format === 'json') {
                $output->writeln(json_encode([
                    'status' => 'CRITICAL',
                    'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'anomalies' => ['Internal monitoring error: ' . $sanitizedError],
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            } else {
                $io = new SymfonyStyle($input, $output);
                $io->error('CRITICAL: Internal monitoring failure: ' . $sanitizedError);
            }
            return self::EXIT_CRITICAL;
        }

        // Sanitize anomalies before displaying
        $sanitizedAnomalies = array_map(
            fn(string $anomaly): string => $this->sanitizer->sanitizeError($anomaly) ?? $anomaly,
            $overview->anomalies
        );

        if ($format === 'json') {
            $this->renderJson($output, $overview, $sanitizedAnomalies);
        } else {
            $this->renderText(new SymfonyStyle($input, $output), $overview, $sanitizedAnomalies);
        }

        return match ($overview->status) {
            'CRITICAL' => self::EXIT_CRITICAL,
            default => self::EXIT_HEALTHY_OR_DEGRADED,
        };
    }

    /**
     * @param string[] $sanitizedAnomalies
     */
    private function renderJson(OutputInterface $output, \App\Monitoring\Dto\SystemHealthOverview $overview, array $sanitizedAnomalies): void
    {
        $activeRunsData = [];
        foreach ($overview->activeRuns as $run) {
            $activeRunsData[] = [
                'id' => $run->id,
                'type' => $run->type,
                'status' => $run->status,
                'attempt' => $run->attempt,
                'duration_seconds' => $run->durationSeconds,
                'is_stale' => $run->isStale,
                'filename' => $run->filenameClean,
            ];
        }

        $queuesData = [];
        $failedCount = 0;
        foreach ($overview->queueMetrics as $queue) {
            if ($queue->queueName === 'failed') {
                $failedCount = $queue->pendingCount;
                continue;
            }
            $queuesData[] = [
                'queue_name' => $queue->queueName,
                'pending' => $queue->pendingCount,
                'in_flight' => $queue->inFlightCount,
                'delayed' => $queue->delayedCount,
                'oldest_message_age_seconds' => $queue->oldestMessageAgeSeconds,
            ];
        }

        $outboxData = [
            'unpublished_count' => $overview->outboxMetrics?->unpublishedCount ?? 0,
            'deferred_retries' => $overview->outboxMetrics?->deferredRetryCount ?? 0,
            'oldest_unpublished_age_seconds' => $overview->outboxMetrics?->oldestUnpublishedAgeSeconds,
            'last_published_at' => $overview->outboxMetrics?->lastPublishedAt?->format(\DateTimeInterface::ATOM),
        ];

        $payload = [
            'status' => $overview->status,
            'timestamp' => $overview->timestamp->format(\DateTimeInterface::ATOM),
            'import_runs' => [
                'window_hours' => 24,
                'summary' => [
                    'active_count' => count($overview->activeRuns),
                ],
                'active_runs' => $activeRunsData,
            ],
            'queues' => $queuesData,
            'failed_transport' => [
                'count' => $failedCount,
            ],
            'outbox' => $outboxData,
            'anomalies' => $sanitizedAnomalies,
        ];

        $output->writeln(json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /**
     * @param string[] $sanitizedAnomalies
     */
    private function renderText(SymfonyStyle $io, \App\Monitoring\Dto\SystemHealthOverview $overview, array $sanitizedAnomalies): void
    {
        $statusPrefix = match ($overview->status) {
            'HEALTHY' => '[OK]',
            'DEGRADED' => '[WARNING]',
            'CRITICAL' => '[CRITICAL]',
            default => '[UNKNOWN]',
        };

        $io->writeln(sprintf(
            '%s Commerce Integration Hub: %s (%s)',
            $statusPrefix,
            $overview->status,
            $overview->timestamp->format('Y-m-d H:i:s T')
        ));
        $io->newLine();

        $io->writeln('Import Runs:');
        if (empty($overview->activeRuns)) {
            $io->writeln('  Active: none');
        } else {
            $io->writeln('  Active:');
            foreach ($overview->activeRuns as $run) {
                $staleLabel = $run->isStale ? '[STALE]' : '[OK]';
                $io->writeln(sprintf(
                    '    - #%d [%s] %s (%ds, attempt %d) file: %s %s',
                    $run->id,
                    $run->type,
                    $run->status,
                    $run->durationSeconds,
                    $run->attempt,
                    $run->filenameClean,
                    $staleLabel
                ));
            }
        }
        $io->newLine();

        $io->writeln('Queues (Doctrine Messenger):');
        $failedCount = 0;
        foreach ($overview->queueMetrics as $queue) {
            if ($queue->queueName === 'failed') {
                $failedCount = $queue->pendingCount;
                continue;
            }
            $io->writeln(sprintf(
                '  - %s: pending=%d, in_flight=%d, delayed=%d, oldest=%s',
                $queue->queueName,
                $queue->pendingCount,
                $queue->inFlightCount,
                $queue->delayedCount,
                $queue->oldestMessageAgeSeconds !== null ? $queue->oldestMessageAgeSeconds . 's' : 'none'
            ));
        }
        $io->writeln(sprintf('  - failed_transport: %d messages', $failedCount));
        $io->newLine();

        $io->writeln('Outbox:');
        if ($overview->outboxMetrics !== null) {
            $io->writeln(sprintf(
                '  - Unpublished: %d (deferred retry: %d)',
                $overview->outboxMetrics->unpublishedCount,
                $overview->outboxMetrics->deferredRetryCount
            ));
            $lastPub = $overview->outboxMetrics->lastPublishedAt?->format('Y-m-d H:i:s T') ?? 'never';
            $io->writeln(sprintf('  - Last published: %s', $lastPub));
        } else {
            $io->writeln('  - Outbox metrics unavailable');
        }

        if (!empty($sanitizedAnomalies)) {
            $io->newLine();
            $io->writeln('Detected Anomalies:');
            foreach ($sanitizedAnomalies as $anomaly) {
                $io->writeln(sprintf('  - %s', $anomaly));
            }
        }
    }
}
