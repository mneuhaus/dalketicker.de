<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\AiSummarizer;
use App\Ai\AiUnavailableException;
use App\Entity\Event;
use App\Repository\EventRepository;
use App\Repository\RegionRepository;
use App\Service\RunLock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * AI teaser pass: derives a 1–2 sentence summary from each event's original
 * description (for the list view), date-ordered (soonest first). Only events
 * with a description and no summary yet are processed. Dry-run by default.
 */
#[AsCommand(
    name: 'dalketicker:summarize-ai',
    description: 'KI-Kurzvorschau (1–2 Sätze) je Event aus dem Originaltext',
)]
final class SummarizeAiCommand extends Command
{
    private const LOCK_NAME = 'ai-summarize';

    public function __construct(
        private readonly EventRepository $events,
        private readonly RegionRepository $regions,
        private readonly AiSummarizer $summarizer,
        private readonly EntityManagerInterface $em,
        private readonly RunLock $locks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Zusammenfassungen speichern (sonst Dry-Run)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max. Events (0 = alle)', '0')
            ->addOption('region', null, InputOption::VALUE_REQUIRED, 'Nur diese Region verarbeiten (z. B. guetersloh)')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Events pro KI-Aufruf', '20')
            ->addOption('max-calls', null, InputOption::VALUE_REQUIRED, 'Max. KI-Aufrufe (0 = unbegrenzt)', '0')
            ->addOption('shards', null, InputOption::VALUE_REQUIRED, 'Gesamtzahl paralleler Läufe', '1')
            ->addOption('shard', null, InputOption::VALUE_REQUIRED, 'Index dieses Laufs (0..shards-1)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Shards work on disjoint id sets (MOD(id, shards)), so each shard gets
        // its own lock and only guards against a duplicate of itself.
        $shards = max(1, (int) $input->getOption('shards'));
        $shard = max(0, (int) $input->getOption('shard'));
        $lock = $shards > 1 ? sprintf('%s-%d-%d', self::LOCK_NAME, $shards, $shard) : self::LOCK_NAME;

        if (!$this->locks->acquire($lock)) {
            $io->warning('Lauf läuft bereits – Abbruch.');

            return Command::SUCCESS;
        }

        try {
            return $this->doExecute($input, $io, $shards, $shard);
        } finally {
            $this->locks->release($lock);
        }
    }

    private function doExecute(InputInterface $input, SymfonyStyle $io, int $shards, int $shard): int
    {
        $apply = (bool) $input->getOption('apply');
        $limit = max(0, (int) $input->getOption('limit'));
        $batch = max(1, (int) $input->getOption('batch'));
        $maxCalls = max(0, (int) $input->getOption('max-calls'));
        $region = null;
        $regionKey = trim((string) $input->getOption('region'));
        if ($regionKey !== '') {
            $region = $this->regions->findByKey($regionKey);
            if ($region === null) {
                $io->error(sprintf('Region "%s" nicht gefunden.', $regionKey));

                return Command::INVALID;
            }
        }

        $io->title(sprintf('KI-Kurzvorschau %s%s – Modell: %s', $apply ? '(APPLY)' : '(Dry-Run)', $region !== null ? ' '.$region->getSiteName() : '', $this->summarizer->getModel()));
        if (!$this->summarizer->isConfigured()) {
            $io->error('OPENROUTER_API_KEY ist nicht gesetzt.');

            return Command::FAILURE;
        }

        $events = $this->events->findWithoutSummary($limit > 0 ? $limit : 100000, $shards, $shard, $region);
        if ($events === []) {
            $io->success('Keine Events ohne Vorschau.');

            return Command::SUCCESS;
        }
        $io->writeln(sprintf('%d Events ohne Vorschau (nach Datum) …', \count($events)));

        $calls = 0;
        $done = 0;
        foreach ($this->regionChunks($events, $batch) as $chunk) {
            if ($maxCalls > 0 && $calls >= $maxCalls) {
                $io->warning('Budget für KI-Aufrufe erreicht – Stopp.');
                break;
            }
            ++$calls;
            try {
                $summaries = $this->summarizer->summarize($chunk)['summaries'];
            } catch (AiUnavailableException $e) {
                // Don't mark this batch (no empty summaries) — it gets retried
                // next run; batches flushed before this one stay saved.
                $io->error(sprintf('KI nicht verfügbar – Lauf abgebrochen, Batch bleibt unbearbeitet: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            foreach ($chunk as $event) {
                $summary = $summaries[$event->getId()] ?? null;
                if ($summary === null) {
                    // No summary from the AI → mark as checked with an empty
                    // string (counts as done, won't be retried; the card simply
                    // falls back to the description teaser). Avoids a 99% stall.
                    if ($apply) {
                        $event->setSummary('');
                    }
                    continue;
                }
                ++$done;
                if ($apply) {
                    $event->setSummary($summary);
                } elseif ($io->isVerbose()) {
                    $io->writeln(sprintf('  #%d "%s" → %s', $event->getId(), mb_strimwidth($event->getTitle(), 0, 30, '…'), $summary));
                }
            }

            if ($apply) {
                $this->em->flush();
            }
        }

        $io->success(sprintf('%s | KI-Aufrufe: %d · Vorschauen: %d', $apply ? 'Gespeichert' : 'Dry-Run', $calls, $done));

        return Command::SUCCESS;
    }

    /**
     * @param Event[] $events
     * @return iterable<list<Event>>
     */
    private function regionChunks(array $events, int $batch): iterable
    {
        $byRegion = [];
        foreach ($events as $event) {
            $byRegion[$event->getRegion()->getKey()][] = $event;
        }
        foreach ($byRegion as $regionEvents) {
            foreach (array_chunk($regionEvents, $batch) as $chunk) {
                yield $chunk;
            }
        }
    }
}
