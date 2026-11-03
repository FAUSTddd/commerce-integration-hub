<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ImportRunRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:cml:cleanup-files',
    description: 'Clean up temporary CommerceML import files older than a given TTL while protecting active tasks',
)]
final class CleanupImportFilesCliCommand extends Command
{
    public const int MIN_TTL_HOURS = 1;
    private const int DEFAULT_TTL_HOURS = 24;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly ImportRunRepository $importRunRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('ttl-hours', null, InputOption::VALUE_REQUIRED, 'File age threshold in hours to qualify for cleanup', (string) self::DEFAULT_TTL_HOURS)
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Target directory path relative to project root or absolute (defaults to var/1c_import and var/import)', null)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate cleanup without actually deleting files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $ttlHours = (int) $input->getOption('ttl-hours');
        if ($ttlHours < self::MIN_TTL_HOURS) {
            $io->error(sprintf('TTL hours option must be at least %d hour(s) for operational safety.', self::MIN_TTL_HOURS));
            return Command::INVALID;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        /** @var string|null $customDir */
        $customDir = $input->getOption('dir');

        $targetDirs = [];
        if ($customDir !== null) {
            $resolved = str_starts_with($customDir, '/') ? $customDir : $this->projectDir . '/' . ltrim($customDir, '/');
            $targetDirs[] = $resolved;
        } else {
            $targetDirs[] = $this->projectDir . '/var/1c_import';
            $targetDirs[] = $this->projectDir . '/var/import';
        }

        $activeFilenames = array_flip($this->importRunRepository->findActiveFilenames());
        $now = time();
        $cutoffTimestamp = $now - ($ttlHours * 3600);

        $deletedCount = 0;
        $skippedActiveCount = 0;
        $skippedTooYoungCount = 0;
        $errorCount = 0;

        $io->title('CommerceML Temporary Files Cleanup');
        $io->note(sprintf('Cutoff threshold: files older than %d hours (%s)', $ttlHours, date('Y-m-d H:i:s', $cutoffTimestamp)));
        if ($dryRun) {
            $io->warning('DRY RUN mode enabled. No files will be deleted.');
        }

        foreach ($targetDirs as $dir) {
            if (!is_dir($dir)) {
                $io->text(sprintf('Directory "%s" does not exist, skipping.', $dir));
                continue;
            }

            $realBaseDir = realpath($dir);
            if ($realBaseDir === false) {
                $io->warning(sprintf('Cannot resolve path for directory "%s".', $dir));
                continue;
            }

            try {
                $iterator = new \DirectoryIterator($realBaseDir);
            } catch (\Throwable $e) {
                $io->error(sprintf('Failed to open directory "%s": %s', $dir, $e->getMessage()));
                $errorCount++;
                continue;
            }

            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isDot()) {
                    continue;
                }

                // Never follow or delete external directory symlinks
                if ($fileInfo->isLink()) {
                    $io->text(sprintf('Skipping symlink "%s".', $fileInfo->getFilename()));
                    continue;
                }

                if (!$fileInfo->isFile()) {
                    continue;
                }

                $filename = $fileInfo->getFilename();
                $filePath = $fileInfo->getPathname();

                // Safety guard: ensure the file is strictly inside the target base directory
                $realFilePath = realpath($filePath);
                if ($realFilePath === false || !str_starts_with($realFilePath, $realBaseDir . DIRECTORY_SEPARATOR)) {
                    $io->warning(sprintf('File "%s" resolves outside target directory, skipping.', $filename));
                    continue;
                }

                // 1. Check if the file is currently bound to an active (PENDING/PROCESSING) ImportRun
                if (isset($activeFilenames[$filename])) {
                    $io->text(sprintf('Preserving "%s": currently used in an active ImportRun.', $filename));
                    $skippedActiveCount++;
                    continue;
                }

                // 2. Check file age against TTL
                $mtime = $fileInfo->getMTime();
                if ($mtime >= $cutoffTimestamp) {
                    $skippedTooYoungCount++;
                    continue;
                }

                // 3. Delete or simulate deletion
                if ($dryRun) {
                    $io->text(sprintf('[DRY-RUN] Would delete "%s" (age: %0.1f hours).', $filename, ($now - $mtime) / 3600));
                    $deletedCount++;
                } else {
                    $success = @unlink($realFilePath);
                    if ($success) {
                        $io->text(sprintf('Deleted "%s" (age: %0.1f hours).', $filename, ($now - $mtime) / 3600));
                        $deletedCount++;
                    } else {
                        $io->error(sprintf('Failed to delete file "%s" (permission denied or I/O error).', $realFilePath));
                        $errorCount++;
                    }
                }
            }
        }

        $io->section('Summary');
        $io->listing([
            sprintf('Files deleted%s: %d', $dryRun ? ' (simulated)' : '', $deletedCount),
            sprintf('Active import files protected: %d', $skippedActiveCount),
            sprintf('Younger files retained: %d', $skippedTooYoungCount),
            sprintf('Errors encountered: %d', $errorCount),
        ]);

        return $errorCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
