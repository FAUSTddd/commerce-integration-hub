<?php

declare(strict_types=1);

namespace App\Command;

use App\Outbox\OutboxPublisherInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'app:cml:publish-outbox',
    description: 'Publishes pending retry messages from import_run_outbox to messenger_messages.'
)]
final class PublishOutboxMessagesCommand extends Command
{
    private bool $shouldStop = false;

    public function __construct(
        private readonly OutboxPublisherInterface $publisher,
        private readonly Connection $connection,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Alias for batch-size (maximum number of messages to publish in a single batch)',
                '50'
            )
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of messages to publish in a single batch',
                '50'
            )
            ->addOption(
                'daemon',
                'd',
                InputOption::VALUE_NONE,
                'Run continuously as a background daemon process'
            )
            ->addOption(
                'interval',
                'i',
                InputOption::VALUE_REQUIRED,
                'Polling interval in seconds between empty batches in daemon mode',
                '5'
            )
            ->addOption(
                'memory-limit',
                'm',
                InputOption::VALUE_REQUIRED,
                'Memory limit threshold (e.g. 128M) causing clean graceful exit when exceeded',
                '128M'
            )
            ->addOption(
                'run-id',
                null,
                InputOption::VALUE_REQUIRED,
                'Target ImportRun ID to selectively publish outbox messages for'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rawRunId = $input->getOption('run-id');
        $runId = null;
        if ($rawRunId !== null) {
            $filteredRunId = filter_var($rawRunId, FILTER_VALIDATE_INT);
            if ($filteredRunId === false || $filteredRunId <= 0) {
                $io->error(sprintf('Option --run-id must be a positive integer, got "%s".', (string) $rawRunId));

                return Command::FAILURE;
            }
            $runId = $filteredRunId;
        }

        $batchSize = (int) $input->getOption('batch-size');
        if ($input->hasParameterOption('--limit') && !$input->hasParameterOption('--batch-size')) {
            $batchSize = (int) $input->getOption('limit');
        }
        $batchSize = max(1, $batchSize);

        $isDaemon = (bool) $input->getOption('daemon');
        if (!$isDaemon) {
            $published = $this->publisher->publishAvailableMessages($batchSize, $runId);
            if ($published > 0) {
                $io->success(sprintf('Successfully published %d outbox message(s).', $published));
            } else {
                $io->note('No available outbox messages to publish.');
            }

            return Command::SUCCESS;
        }

        $interval = max(1, (int) $input->getOption('interval'));
        $memoryLimitBytes = $this->parseMemoryLimitToBytes((string) $input->getOption('memory-limit'));

        $this->registerSignalHandlers($io);

        $io->info(sprintf(
            'Outbox Publisher Daemon started. Batch size: %d, Interval: %ds, Memory limit: %s%s.',
            $batchSize,
            $interval,
            $input->getOption('memory-limit'),
            $runId !== null ? sprintf(', Run ID: %d', $runId) : ''
        ));

        $backoffSeconds = 2;
        $maxBackoff = 30;

        while (!$this->shouldStop) {
            try {
                $published = $this->publisher->publishAvailableMessages($batchSize, $runId);
                $backoffSeconds = 2; // Reset on successful execution

                if ($output->isVerbose() && $published > 0) {
                    $io->comment(sprintf('Published %d outbox message(s).', $published));
                }

                if ($this->shouldStop) {
                    break;
                }

                if ($memoryLimitBytes !== null && memory_get_usage(true) >= $memoryLimitBytes) {
                    $io->warning(sprintf(
                        'Memory limit of %s reached (%d bytes). Exiting cleanly for supervisor restart.',
                        $input->getOption('memory-limit'),
                        memory_get_usage(true)
                    ));
                    break;
                }

                // Zero-Wait only when a full batch was published and more ready records might exist
                if ($published === $batchSize) {
                    continue;
                }

                $this->sleepQuantized($interval);
            } catch (DriverException|Throwable $e) {
                $this->logger?->error(sprintf(
                    'Database error during outbox publish cycle: %s (Code: %s). Reconnecting in %ds...',
                    $e->getMessage(),
                    (string) $e->getCode(),
                    $backoffSeconds
                ));

                if ($output->isVerbose()) {
                    $io->error(sprintf(
                        'Database error: %s. Reconnecting in %ds...',
                        $e->getMessage(),
                        $backoffSeconds
                    ));
                }

                // Close DBAL connection safely to drop dead PDO connection and allow fresh reconnect
                $this->connection->close();

                $this->sleepQuantized($backoffSeconds);
                $backoffSeconds = min($maxBackoff, $backoffSeconds * 2);
            }
        }

        $io->success('Outbox Publisher Daemon stopped gracefully.');

        return Command::SUCCESS;
    }

    private function registerSignalHandlers(SymfonyStyle $io): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        $handler = function (int $signo) use ($io): void {
            $this->shouldStop = true;
            $io->comment(sprintf('Received signal %d. Shutting down gracefully after current batch...', $signo));
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    private function sleepQuantized(int $seconds): void
    {
        for ($i = 0; $i < $seconds; ++$i) {
            if ($this->shouldStop) {
                break;
            }
            sleep(1);
        }
    }

    private function parseMemoryLimitToBytes(string $memoryLimit): ?int
    {
        $memoryLimit = trim($memoryLimit);
        if ($memoryLimit === '' || $memoryLimit === '-1') {
            return null;
        }

        $unit = strtolower(substr($memoryLimit, -1));
        $val = (int) substr($memoryLimit, 0, -1);

        return match ($unit) {
            'g' => $val * 1024 * 1024 * 1024,
            'm' => $val * 1024 * 1024,
            'k' => $val * 1024,
            default => (int) $memoryLimit,
        };
    }
}
