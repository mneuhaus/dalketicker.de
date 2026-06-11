<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Region;
use App\Entity\Source;
use App\Repository\SourceRepository;
use App\Repository\RegionRepository;
use App\Service\EventImporter;
use App\Service\RunLock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Imports events from configured sources.
 *
 *   bin/console dalketicker:import --all --region=guetersloh
 *   bin/console dalketicker:import stadt_gt wapelbad
 *   bin/console dalketicker:import paderborn/stadt_pb
 *   bin/console dalketicker:import --all-regions --workers=4
 */
#[AsCommand(
    name: 'dalketicker:import',
    description: 'Aggregiert Events aus den konfigurierten Quellen',
)]
final class ImportCommand extends Command
{
    public function __construct(
        private readonly SourceRepository $sources,
        private readonly RegionRepository $regions,
        private readonly EventImporter $importer,
        private readonly RunLock $locks,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('keys', InputArgument::IS_ARRAY, 'Quellen-Keys (leer = alle aktiven mit --all)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Alle aktiven Quellen importieren')
            ->addOption('all-regions', null, InputOption::VALUE_NONE, 'Alle aktiven Quellen aus allen Regionen importieren')
            ->addOption('region', null, InputOption::VALUE_REQUIRED, 'Region-Key (z. B. guetersloh, paderborn)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur anzeigen, nichts schreiben')
            ->addOption('workers', null, InputOption::VALUE_REQUIRED, 'Quellen parallel importieren (1-8 Worker)', '1')
            ->addOption('parallel-scope', null, InputOption::VALUE_REQUIRED, 'region = eine Quelle je Region gleichzeitig, source = jede Quelle parallel', 'region');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        return $this->runImports($input, $io);
    }

    private function runImports(InputInterface $input, SymfonyStyle $io): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $keys = $input->getArgument('keys');
        $all = (bool) $input->getOption('all');
        $allRegions = (bool) $input->getOption('all-regions');
        $region = null;
        $regionKey = trim((string) $input->getOption('region'));
        if ($regionKey !== '') {
            $region = $this->regions->findByKey($regionKey);
            if ($region === null) {
                $io->error(sprintf('Region "%s" nicht gefunden.', $regionKey));

                return Command::INVALID;
            }
        }

        if ($all && $allRegions) {
            $io->error('Nutze entweder --all oder --all-regions, nicht beides.');

            return Command::INVALID;
        }
        if ($allRegions && $region !== null) {
            $io->error('Nutze --all-regions ohne --region.');

            return Command::INVALID;
        }
        if ($all && $region === null) {
            $region = $this->regions->findDefault();
        }

        if ($all || $allRegions) {
            $sources = $this->sources->findEnabled($allRegions ? null : $region);
        } elseif ($keys) {
            $sources = [];
            foreach ($keys as $key) {
                $source = $this->resolveSource((string) $key, $region, $io);
                if ($source === null) {
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

        $scope = $region !== null ? ' '.$region->getSiteName() : ($input->getOption('all-regions') ? ' alle Regionen' : '');
        $workers = $this->workerCount($input);
        $parallelScope = $this->parallelScope($input);
        if ($parallelScope === null) {
            $io->error('parallel-scope muss "region" oder "source" sein.');

            return Command::INVALID;
        }
        if ($workers > 1 && \count($sources) > 1) {
            return $this->runParallel($sources, $dryRun, $workers, $parallelScope, $scope, $io);
        }

        $io->title(($dryRun ? 'Ticker-Import (DRY RUN)' : 'Ticker-Import').$scope);
        $failed = [];

        foreach ($sources as $source) {
            // Reload by id: a fatal failure in a previous source may have reset
            // the EntityManager, detaching the entities loaded upfront.
            $id = $source->getId();
            $source = ($id !== null ? $this->sources->find($id) : null) ?? $source;

            $failure = $this->importOne($source, $dryRun, $io);
            if ($failure !== null) {
                $failed[] = $failure;
            }
        }

        if ($failed !== []) {
            $io->error(sprintf('%d Quelle(n) fehlgeschlagen:', \count($failed)));
            $io->listing($failed);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function importOne(Source $source, bool $dryRun, SymfonyStyle $io): ?string
    {
        $label = $source->getRegion()->getKey().'/'.$source->getKey();
        $lockName = $this->sourceLockName($source);

        if (!$this->locks->acquire($lockName)) {
            $io->warning(sprintf('%s läuft bereits – übersprungen.', $label));

            return null;
        }

        try {
            $io->section($source->getName().' ['.$label.']');
            $report = $this->importer->import($source, $dryRun);
            if ($report->fatal !== null) {
                $io->error($report->summary());

                return $report->summary();
            }

            $io->success($report->summary());

            return null;
        } finally {
            $this->locks->release($lockName);
        }
    }

    /**
     * @param list<Source> $sources
     */
    private function runParallel(array $sources, bool $dryRun, int $workers, string $parallelScope, string $scope, SymfonyStyle $io): int
    {
        $scopeLabel = $parallelScope === 'region' ? 'regionensicher' : 'quellenweise';
        $io->title(sprintf('%s%s – %d Worker, %s', $dryRun ? 'Ticker-Import (DRY RUN)' : 'Ticker-Import', $scope, $workers, $scopeLabel));
        $queue = $sources;
        $running = [];
        $activeRegions = [];
        $failed = [];

        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && \count($running) < $workers) {
                $nextIndex = $this->nextQueuedSourceIndex($queue, $activeRegions, $parallelScope);
                if ($nextIndex === null) {
                    break;
                }
                $source = $queue[$nextIndex];
                array_splice($queue, $nextIndex, 1);

                $process = $this->sourceProcess($source, $dryRun);
                $process->start();
                $activeRegions[$source->getRegion()->getKey()] = true;
                $running[] = [$source, $process];
                $io->writeln(sprintf('Gestartet: %s/%s', $source->getRegion()->getKey(), $source->getKey()));
            }

            foreach ($running as $index => [$source, $process]) {
                if ($process->isRunning()) {
                    continue;
                }

                unset($running[$index]);
                $label = $source->getRegion()->getKey().'/'.$source->getKey();
                unset($activeRegions[$source->getRegion()->getKey()]);
                $io->section($label);
                $out = trim($process->getOutput());
                if ($out !== '') {
                    $io->writeln($out);
                }
                $err = trim($process->getErrorOutput());
                if ($err !== '') {
                    $io->writeln($err);
                }
                if (!$process->isSuccessful()) {
                    $failed[] = sprintf('%s (Exit %d)', $label, $process->getExitCode());
                }
            }
            $running = array_values($running);

            if ($running !== []) {
                usleep(100000);
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
     * @param list<Source>        $queue
     * @param array<string, true> $activeRegions
     */
    private function nextQueuedSourceIndex(array $queue, array $activeRegions, string $parallelScope): ?int
    {
        foreach ($queue as $index => $source) {
            if ($parallelScope === 'source' || !isset($activeRegions[$source->getRegion()->getKey()])) {
                return $index;
            }
        }

        return null;
    }

    private function sourceProcess(Source $source, bool $dryRun): Process
    {
        $args = [
            \PHP_BINARY,
            'bin/console',
            'dalketicker:import',
            $source->getRegion()->getKey().'/'.$source->getKey(),
        ];
        if ($dryRun) {
            $args[] = '--dry-run';
        }

        $process = new Process($args, $this->projectDir);
        $process->setTimeout(null);

        return $process;
    }

    private function resolveSource(string $key, ?Region $region, SymfonyStyle $io): ?Source
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        if (str_contains($key, '/')) {
            [$regionKey, $sourceKey] = explode('/', $key, 2);
            $sourceRegion = $this->regions->findByKey(trim($regionKey));
            if ($sourceRegion === null) {
                $io->warning(sprintf('Region "%s" nicht gefunden – Quelle "%s" übersprungen.', $regionKey, $key));

                return null;
            }

            $source = $this->sources->findByKey(trim($sourceKey), $sourceRegion);
            if ($source === null) {
                $io->warning(sprintf('Quelle "%s" in %s nicht gefunden – übersprungen.', $sourceKey, $sourceRegion->getKey()));
            }

            return $source;
        }

        if ($region !== null) {
            $source = $this->sources->findByKey($key, $region);
            if ($source === null) {
                $io->warning(sprintf('Quelle "%s" in %s nicht gefunden – übersprungen.', $key, $region->getKey()));
            }

            return $source;
        }

        $matches = $this->sources->findAllByKey($key);
        if (\count($matches) > 1) {
            $io->warning(sprintf('Quelle "%s" existiert in mehreren Regionen – nutze --region=... oder region/%s.', $key, $key));

            return null;
        }
        if ($matches === []) {
            $io->warning(sprintf('Quelle "%s" nicht gefunden – übersprungen.', $key));

            return null;
        }

        return $matches[0];
    }

    private function workerCount(InputInterface $input): int
    {
        return max(1, min(8, (int) $input->getOption('workers')));
    }

    private function parallelScope(InputInterface $input): ?string
    {
        $scope = trim((string) $input->getOption('parallel-scope'));

        return \in_array($scope, ['region', 'source'], true) ? $scope : null;
    }

    private function sourceLockName(Source $source): string
    {
        return sprintf('import-source-%s-%s', $source->getRegion()->getKey(), $source->getKey());
    }
}
