<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\AiCategorizer;
use App\Ai\AiUnavailableException;
use App\Ai\CategoryApplier;
use App\Entity\AiCategoryDecision;
use App\Entity\Event;
use App\Entity\Region;
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
 * AI categorization pass (run after import/dedup).
 *  - "fill":    events without a real category get AI categories applied directly.
 *  - "opinion": events that already have a category get a second opinion; when
 *               it contradicts the importer's pick, the AI wins (applied, undoable
 *               in /admin), otherwise the event is just marked as checked.
 * Every looked-at event gets an {@see \App\Entity\AiCategoryDecision} (auditable,
 * reversible, and used to skip already-processed events on re-runs). Applied
 * categories survive re-imports ({@see \App\Service\EventImporter}).
 * Dry-run by default; pass --apply to write.
 */
#[AsCommand(
    name: 'dalketicker:categorize-ai',
    description: 'KI-Kategorisierung: Lücken füllen + Zweitmeinung (Widersprüche zur Admin-Prüfung)',
)]
final class CategorizeAiCommand extends Command
{
    private const LOCK_NAME = 'ai-categorize';

    public function __construct(
        private readonly EventRepository $events,
        private readonly RegionRepository $regions,
        private readonly AiCategorizer $categorizer,
        private readonly CategoryApplier $applier,
        private readonly EntityManagerInterface $em,
        private readonly RunLock $locks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Änderungen wirklich speichern (sonst Dry-Run)')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'fill | opinion | all', 'all')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max. Events pro Pass (0 = alle)', '0')
            ->addOption('region', null, InputOption::VALUE_REQUIRED, 'Nur diese Region verarbeiten (z. B. guetersloh)')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Events pro KI-Aufruf', '25')
            ->addOption('max-calls', null, InputOption::VALUE_REQUIRED, 'Max. KI-Aufrufe insgesamt (0 = unbegrenzt)', '0')
            ->addOption('shards', null, InputOption::VALUE_REQUIRED, 'Gesamtzahl paralleler Läufe (für Sharding)', '1')
            ->addOption('shard', null, InputOption::VALUE_REQUIRED, 'Index dieses Laufs (0..shards-1)', '0')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'KI-Kategorie-Entscheidungen zurücksetzen (mit --region nur diese Region; für sauberen Neulauf nach Prompt-Änderung)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Shards work on disjoint id sets (MOD(id, shards)), so each shard gets
        // its own lock and only guards against a duplicate of itself. The plain
        // single run keeps the bare name the admin UI checks via isLocked().
        $shards = max(1, (int) $input->getOption('shards'));
        $shard = max(0, (int) $input->getOption('shard'));
        if ($shard >= $shards) {
            // MOD(id, shards) never equals such a shard — the run would
            // silently process nothing.
            $io->error(sprintf('--shard muss kleiner als --shards sein (%d >= %d).', $shard, $shards));

            return Command::INVALID;
        }
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
        $region = null;
        $regionKey = trim((string) $input->getOption('region'));
        if ($regionKey !== '') {
            $region = $this->regions->findByKey($regionKey);
            if ($region === null) {
                $io->error(sprintf('Region "%s" nicht gefunden.', $regionKey));

                return Command::INVALID;
            }
        }

        $apply = (bool) $input->getOption('apply');
        if ($input->getOption('reset')) {
            // Same guard as dedup-ai --reset: a reset writes, so it needs the
            // explicit --apply like every other writing pass of this command.
            if (!$apply) {
                $io->error('--reset schreibt in die Datenbank und braucht --apply.');

                return Command::INVALID;
            }

            return $this->reset($io, $region);
        }

        $mode = (string) $input->getOption('mode');
        if (!\in_array($mode, ['fill', 'opinion', 'all'], true)) {
            $io->error(sprintf('Unbekannter --mode "%s" (erwartet fill, opinion oder all).', $mode));

            return Command::INVALID;
        }
        $limit = max(0, (int) $input->getOption('limit'));
        $batch = max(1, (int) $input->getOption('batch'));
        $maxCalls = max(0, (int) $input->getOption('max-calls'));

        $io->title(sprintf('KI-Kategorisierung %s%s – Modell: %s', $apply ? '(APPLY)' : '(Dry-Run)', $region !== null ? ' '.$region->getSiteName() : '', $this->categorizer->getModel()));
        if (!$this->categorizer->isConfigured()) {
            $io->error('OPENROUTER_API_KEY ist nicht gesetzt.');

            return Command::FAILURE;
        }

        $calls = 0;
        $stats = ['fill' => 0, 'suggestions' => 0, 'agreed' => 0, 'skipped' => 0, 'pinned' => 0];

        // One date-ordered pass: soonest events first, then further into the
        // future. Each event is a "fill" (no real category) or an "opinion"
        // (already categorized) — decided per event, not in separate phases.
        $events = $this->events->findUncheckedUpcoming($limit > 0 ? $limit : 100000, $shards, $shard, $region);
        if ($mode !== 'all') {
            // A restricted run leaves the other kind unchecked for later — so
            // drop it BEFORE the AI call, or every such run pays for events it
            // then throws away.
            $events = array_values(array_filter(
                $events,
                fn (Event $event): bool => $mode === ($this->hasRealCategory($event) ? 'opinion' : 'fill'),
            ));
        }
        $io->writeln(sprintf('%d ungeprüfte Events (nach Datum) …', \count($events)));

        foreach ($this->regionChunks($events, $batch) as $chunk) {
            if ($maxCalls > 0 && $calls >= $maxCalls) {
                $io->warning('Budget für KI-Aufrufe erreicht – Stopp.');
                break;
            }
            ++$calls;
            try {
                $proposals = $this->categorizer->classify($chunk)['proposals'];
            } catch (AiUnavailableException $e) {
                // Don't mark this batch as checked — it gets retried next run;
                // batches flushed before this one stay saved.
                $io->error(sprintf('KI nicht verfügbar – Lauf abgebrochen, Batch bleibt ungeprüft: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            foreach ($chunk as $event) {
                $uncategorized = !$this->hasRealCategory($event);
                $proposal = $proposals[$event->getId()] ?? null;
                if ($proposal === null) {
                    ++$stats['skipped'];
                    // Mark as checked (no usable proposal) so it doesn't stay
                    // "unchecked" forever and stall the progress at 99%.
                    if ($apply) {
                        $this->applier->recordSkipped($event, $this->categorizer->getModel());
                    }
                    continue;
                }
                $this->handle($io, $event, $proposal, $uncategorized, $apply, $stats);
            }

            if ($apply) {
                $this->em->flush();
            }
        }

        $io->newLine();
        $io->success(sprintf(
            '%s | KI-Aufrufe: %d · gefüllt: %d · überschrieben: %d · bestätigt: %d · ohne Vorschlag: %d · gepinnt: %d',
            $apply ? 'Gespeichert' : 'Dry-Run (nichts gespeichert)',
            $calls, $stats['fill'], $stats['suggestions'], $stats['agreed'], $stats['skipped'], $stats['pinned'],
        ));

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

    /** Restore pre-AI categories (undo applied decisions) and drop the decisions — all regions or just one. */
    private function reset(SymfonyStyle $io, ?Region $region): int
    {
        $qb = $this->em->getRepository(AiCategoryDecision::class)->createQueryBuilder('d');
        if ($region !== null) {
            $qb->join('d.event', 'e')->andWhere('e.region = :region')->setParameter('region', $region);
        }
        /** @var list<AiCategoryDecision> $decisions */
        $decisions = $qb->getQuery()->getResult();
        foreach ($decisions as $decision) {
            $this->applier->undo($decision); // restores previousSlugs for applied ones
            $this->em->remove($decision);
        }
        $this->em->flush();
        $io->success(sprintf(
            '%d Entscheidungen zurückgesetzt%s – nächster Lauf kategorisiert neu.',
            \count($decisions),
            $region !== null ? ' ('.$region->getSiteName().')' : '',
        ));

        return Command::SUCCESS;
    }

    /** A real category = anything other than the "sonstiges" catch-all. */
    private function hasRealCategory(Event $event): bool
    {
        foreach ($event->getCategories() as $category) {
            if ($category->getSlug() !== 'sonstiges') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{slugs: list<string>, reason: string} $proposal
     * @param array<string,int>                          $stats
     */
    private function handle(SymfonyStyle $io, Event $event, array $proposal, bool $uncategorized, bool $apply, array &$stats): void
    {
        $model = $this->categorizer->getModel();
        $current = [];
        foreach ($event->getCategories() as $c) {
            $current[] = $c->getSlug();
        }

        // Admin pinned the categories: never overwrite them. Still record the
        // decision (as dismissed, via the applier) so the event counts as
        // checked and the run doesn't revisit it forever.
        if ($event->isFieldLocked('categories')) {
            ++$stats['pinned'];
            if ($apply) {
                $this->applier->apply($event, $proposal['slugs'], $uncategorized ? 'fill' : 'opinion', $model, $proposal['reason']);
            } elseif ($io->isVerbose()) {
                $io->writeln(sprintf('  pin   #%d "%s" – übersprungen: Kategorien gepinnt', $event->getId(), $event->getTitle()));
            }

            return;
        }

        if ($uncategorized) {
            ++$stats['fill'];
            if ($apply) {
                $this->applier->apply($event, $proposal['slugs'], 'fill', $model, $proposal['reason']);
            } elseif ($io->isVerbose()) {
                $io->writeln(sprintf('  fill  #%d "%s" → %s', $event->getId(), $event->getTitle(), implode(',', $proposal['slugs'])));
            }

            return;
        }

        // Opinion pass: when the AI's primary pick differs from the event's
        // current categories, apply it directly (AI wins) — recorded so it can
        // be undone. When it matches, just mark the event as checked.
        $contradiction = !\in_array($proposal['slugs'][0], $current, true);
        if ($contradiction) {
            ++$stats['suggestions'];
            if ($apply) {
                $this->applier->apply($event, $proposal['slugs'], 'opinion', $model, $proposal['reason']);
            } elseif ($io->isVerbose()) {
                $io->writeln(sprintf('  over  #%d "%s": %s → KI %s', $event->getId(), $event->getTitle(), implode(',', $current), implode(',', $proposal['slugs'])));
            }
        } else {
            ++$stats['agreed'];
            if ($apply) {
                $this->applier->recordAgreed($event, $model);
            }
        }
    }
}
