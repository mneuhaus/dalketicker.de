<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\SourceRepository;
use App\Service\EventImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('keys', InputArgument::IS_ARRAY, 'Quellen-Keys (leer = alle aktiven mit --all)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Alle aktiven Quellen importieren')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur anzeigen, nichts schreiben');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $lock = $this->acquireLock();
        if ($lock === false) {
            $io->warning('Ein anderer Import läuft bereits (var/run/import.lock) – Abbruch.');

            return Command::FAILURE;
        }
        if ($lock === null) {
            // I/O failure, not a proven concurrent run — better to import
            // unguarded than to silently never import again.
            $io->warning('Lock-Verzeichnis nicht beschreibbar (var/run) – Import läuft ohne Lock weiter.');
        }

        try {
            return $this->runImports($input, $io);
        } finally {
            if ($lock !== null) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function runImports(InputInterface $input, SymfonyStyle $io): int
    {
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
        $failed = [];

        foreach ($sources as $source) {
            // Reload by id: a fatal failure in a previous source may have reset
            // the EntityManager, detaching the entities loaded upfront.
            $id = $source->getId();
            $source = ($id !== null ? $this->sources->find($id) : null) ?? $source;

            $io->section($source->getName().' ['.$source->getKey().']');
            $report = $this->importer->import($source, $dryRun);
            if ($report->fatal !== null) {
                $failed[] = $report->summary();
                $io->error($report->summary());
            } else {
                $io->success($report->summary());
            }
        }

        if ($failed !== []) {
            $io->error(sprintf('%d Quelle(n) fehlgeschlagen:', \count($failed)));
            $io->listing($failed);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Guard against parallel runs (e.g. an overlapping cron) via an exclusive,
     * non-blocking flock on var/run/import.lock.
     *
     * @return resource|false|null the held lock handle; false when another run
     *                             owns the lock; null on I/O failure (lock dir
     *                             not writable — no concurrent run proven)
     */
    private function acquireLock()
    {
        $dir = $this->projectDir.'/var/run';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        $handle = @fopen($dir.'/import.lock', 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        return $handle;
    }
}
