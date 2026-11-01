<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ImportRun;
use App\Repository\ImportRunRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cml:recover-stale-runs',
    description: 'Manual administrative tool to inspect and mark abandoned PROCESSING imports as FAILED.'
)]
final class RecoverStaleRunsCliCommand extends Command
{
    public function __construct(
        private readonly ImportRunRepository $importRunRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'stale-hours',
                null,
                InputOption::VALUE_REQUIRED,
                'Threshold in hours after which a PROCESSING run is considered stale',
                '2'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force marking stale runs as FAILED without interactive confirmation'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Inspect candidates without modifying database'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $staleHours = (int) $input->getOption('stale-hours');
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        if ($staleHours <= 0) {
            $io->error('The --stale-hours option must be a positive integer.');
            return Command::FAILURE;
        }

        $threshold = new \DateTimeImmutable(sprintf('-%d hours', $staleHours));

        $candidateRuns = $this->importRunRepository->findStaleProcessingRuns($threshold);

        if (empty($candidateRuns)) {
            $io->success(sprintf('No PROCESSING runs found older than %d hours.', $staleHours));
            return Command::SUCCESS;
        }

        $io->note(sprintf('Evaluating %d candidate PROCESSING run(s) older than %d hours...', count($candidateRuns), $staleHours));

        $staleRuns = [];
        $protectedCount = 0;
        foreach ($candidateRuns as $run) {
            // Check if there is a message within the transport worker redelivery window (< 1h)
            if ($this->importRunRepository->isRunActivelyHandledByWorker($run)) {
                $io->writeln(sprintf(
                    ' [PROTECTED] Run ID #%d (%s, file: %s) is within Doctrine worker redelivery window (< 1h). Skipping premature recovery.',
                    $run->getId() ?? 0,
                    $run->getType(),
                    $run->getFilename()
                ));
                $protectedCount++;
                continue;
            }

            $staleRuns[] = $run;
        }

        if (empty($staleRuns)) {
            $io->success('No unhandled stale runs found (all candidates are protected within worker redelivery window).');
            return Command::SUCCESS;
        }

        $io->warning([
            'OPERATIONAL LIMITATION: Recovering an import run cannot guarantee the underlying worker process was terminated.',
            'A message outside the redelivery window or without an active worker indicates abandonment, but if a process is frozen,',
            'marking it FAILED without terminating the worker may cause split-brain state upon subsequent queue restarts.',
            'Ensure background worker processes are inspected/stopped before forcing recovery.'
        ]);

        foreach ($staleRuns as $run) {
            $startedAt = $run->getStartedAt()?->format('Y-m-d H:i:s') ?? 'unknown';
            $io->writeln(sprintf(
                ' - Run ID #%d (%s, file: %s, started: %s)',
                $run->getId() ?? 0,
                $run->getType(),
                $run->getFilename(),
                $startedAt
            ));
        }

        if ($dryRun) {
            $io->note(sprintf('[DRY-RUN] Found %d candidate run(s) eligible for recovery. No database records modified.', count($staleRuns)));
            return Command::SUCCESS;
        }

        if (!$force && $input->isInteractive()) {
            if (!$io->confirm(sprintf('Do you want to mark these %d candidate run(s) as FAILED?', count($staleRuns)), false)) {
                $io->note('Operation cancelled by operator. No records modified.');
                return Command::SUCCESS;
            }
        } elseif (!$force) {
            $io->error('Non-interactive execution requires the --force (-f) flag to apply status changes.');
            return Command::FAILURE;
        }

        $count = 0;
        foreach ($staleRuns as $run) {
            $run->setStatus(ImportRun::STATUS_FAILED);
            $run->setFinishedAt(new \DateTimeImmutable());
            $run->setErrorMessage(sprintf(
                'Administrative recovery: stuck in PROCESSING for more than %d hour(s) with no active worker handling message within redelivery window.',
                $staleHours
            ));
            $this->importRunRepository->save($run);
            $count++;
        }

        $io->success(sprintf('Successfully marked %d stale run(s) as FAILED.', $count));
        return Command::SUCCESS;
    }
}
