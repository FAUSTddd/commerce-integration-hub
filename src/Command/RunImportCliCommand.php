<?php

declare(strict_types=1);

namespace App\Command;

use FaustDDD\Symfony1cImport\Application\Command\ImportCatalogCommand;
use FaustDDD\Symfony1cImport\Application\Command\ImportOffersCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:cml:import',
    description: 'Import CommerceML catalog or offers XML file into the database',
)]
final class RunImportCliCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the XML file (import.xml or offers.xml)')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Type of import: "catalog" or "offers" (auto-detected if omitted)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $filePath */
        $filePath = (string) $input->getArgument('file');

        if (!is_file($filePath) || !is_readable($filePath)) {
            $io->error(sprintf('File not found or not readable: "%s"', $filePath));
            return Command::FAILURE;
        }

        /** @var string|null $type */
        $type = $input->getOption('type');
        if ($type === null) {
            $type = $this->detectType($filePath);
        }

        $io->title(sprintf('Starting CML Import [%s]', strtoupper($type)));
        $io->note(sprintf('Processing file: %s', realpath($filePath)));

        $startTime = microtime(true);

        try {
            if ($type === 'catalog') {
                $this->messageBus->dispatch(new ImportCatalogCommand($filePath));
            } elseif ($type === 'offers') {
                $this->messageBus->dispatch(new ImportOffersCommand($filePath));
            } else {
                $io->error(sprintf('Unknown import type "%s". Use "catalog" or "offers".', $type));
                return Command::INVALID;
            }
        } catch (\Throwable $e) {
            $io->error(sprintf('Import failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        $duration = round(microtime(true) - $startTime, 2);
        $io->success(sprintf('Import completed successfully in %s seconds.', $duration));

        return Command::SUCCESS;
    }

    private function detectType(string $filePath): string
    {
        $basename = strtolower(basename($filePath));
        if (str_contains($basename, 'offer') || str_contains($basename, 'предложен')) {
            return 'offers';
        }

        return 'catalog';
    }
}
