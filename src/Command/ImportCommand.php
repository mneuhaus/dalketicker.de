<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\SourceRepository;
use App\Service\EventImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports events from configured sources.
 *
 *   bin/console dalketicker:import --all
 *   bin/console dalketicker:import stadt_gt wapelbad
 *   bin/console dalketicker:import --all --dry-run
 */
#[AsCommand(
    name: 'dalketicker:import',
    description: 'Aggregiert Events aus den konfigurierten Quellen',
)]
final class ImportCommand extends Command
{
    public function __construct(
        private readonly SourceRepository $sources,
        private readonly EventImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('keys', \Symfony\Component\Console\Input\InputArgument::IS_ARRAY, 'Quellen-Keys (leer = alle aktiven mit --all)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Alle aktiven Quellen importieren')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur anzeigen, nichts schreiben');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $keys = $input->getArgument('keys');

        if ($input->getOption('all')) {
            $sources = $this->sources->findEnabled();
        } elseif ($keys) {
            $sources = [];
            foreach ($keys as $key) {
                $source = $this->sources->findByKey($key);
                if ($source === null) {
                    $io->warning(sprintf('Quelle "%s" nicht gefunden – übersprungen.', $key));
                    continue;
                }
                $sources[] = $source;
            }
        } else {
            $io->error('Gib Quellen-Keys an oder nutze --all.');

            return Command::INVALID;
        }

        if (!$sources) {
            $io->warning('Keine Quellen zum Importieren.');

            return Command::SUCCESS;
        }

        $io->title($dryRun ? 'Dalketicker Import (DRY RUN)' : 'Dalketicker Import');
        $hadFatal = false;

        foreach ($sources as $source) {
            $io->section($source->getName().' ['.$source->getKey().']');
            $report = $this->importer->import($source, $dryRun);
            $hadFatal = $hadFatal || $report->fatal !== null;
            $report->fatal !== null ? $io->error($report->summary()) : $io->success($report->summary());
        }

        return $hadFatal ? Command::FAILURE : Command::SUCCESS;
    }
}
