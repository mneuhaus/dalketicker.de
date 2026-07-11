<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\AiDeduper;
use App\Ai\AiUnavailableException;
use App\Ai\DuplicateMerger;
use App\Entity\AiDedupDay;
use App\Entity\AiDedupDecision;
use App\Entity\Event;
use App\Entity\Region;
use App\Enum\EventStatus;
use App\Importer\ImportedEvent;
use App\Repository\EventRepository;
use App\Repository\RegionRepository;
use App\Search\EventFilter;
use App\Service\RunLock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Second-pass deduplication: after the import cron, walk upcoming events day by
 * day and let an AI catch the duplicates the key-based dedup missed (date off
 * by one, different wording, semantic duplicates). Conservative, audited,
 * reversible. A per-day fingerprint skips unchanged days to keep it cheap.
 *
 *   bin/console dalketicker:dedup-ai [--days=60] [--from=YYYY-MM-DD] [--dry-run] [--force] [--max-ai-calls=200]
 */
#[AsCommand(
    name: 'dalketicker:dedup-ai',
    description: 'KI-gestützter zweiter Dedup-Pass, tagesweise (nach dem Import)',
)]
final class DedupAiCommand extends Command
{
    private const LOCK_NAME = 'ai-dedup';

    public function __construct(
        private readonly EventRepository $events,
        private readonly RegionRepository $regions,
        private readonly AiDeduper $deduper,
        private readonly DuplicateMerger $merger,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly RunLock $locks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Wie viele Tage ab heute prüfen', '60')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Startdatum YYYY-MM-DD (Standard: heute)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur vorschlagen, nichts ändern')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Fingerprint-Skip ignorieren (alle Tage prüfen)')
            ->addOption('region', null, InputOption::VALUE_REQUIRED, 'Nur diese Region prüfen (z. B. guetersloh)')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Alle bestehenden AI-Merges erst rückgängig machen, dann frisch neu bewerten')
            ->addOption('max-ai-calls', null, InputOption::VALUE_REQUIRED, 'Obergrenze AI-Aufrufe pro Lauf', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->locks->acquire(self::LOCK_NAME)) {
            $io->warning('Lauf läuft bereits – Abbruch.');

            return Command::SUCCESS;
        }

        try {
            return $this->doExecute($input, $io);
        } finally {
            $this->locks->release(self::LOCK_NAME);
        }
    }

    private function doExecute(InputInterface $input, SymfonyStyle $io): int
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $days = max(1, (int) $input->getOption('days'));
        $budget = max(1, (int) $input->getOption('max-ai-calls'));
        $regions = $this->selectedRegions($input, $io);
        if ($regions === []) {
            return Command::INVALID;
        }

        if (!$this->deduper->isConfigured()) {
            $io->error('OPENROUTER_API_KEY ist nicht gesetzt – AI-Dedup übersprungen.');

            return Command::FAILURE;
        }

        $fromOpt = (string) $input->getOption('from');
        $from = $fromOpt !== ''
            ? (\DateTimeImmutable::createFromFormat('!Y-m-d', $fromOpt, $tz) ?: null)
            : new \DateTimeImmutable('today', $tz);
        if ($from === null) {
            $io->error('Ungültiges --from (erwartet YYYY-MM-DD).');

            return Command::FAILURE;
        }

        $io->title('AI-Dedup'.($dryRun ? ' (dry-run)' : '').' – '.(\count($regions) === 1 ? $regions[0]->getSiteName() : 'alle Regionen'));

        // --reset: undo all active merges and wipe the per-day fingerprints, so
        // the whole window is re-decided from scratch with the current prompt
        // (e.g. after improving the "which one to keep" logic). Implies --force.
        if ($input->getOption('reset') && !$dryRun) {
            $reset = $this->resetRegions($regions);
            $this->em->flush();
            $io->note(sprintf('%d bestehende Merges zurückgesetzt – alles wird neu bewertet.', $reset));
            $force = true;
        }

        $dayRepo = $this->em->getRepository(AiDedupDay::class);
        $vetoedPairs = $this->adminVetoedPairs();
        $aiCalls = 0;
        $merges = 0;
        $reviewed = 0;

        foreach ($regions as $region) {
            $io->section($region->getSiteName());
            for ($i = 0; $i < $days; ++$i) {
                $day = $from->modify('+'.$i.' days');
                $dayKey = $day->format('Y-m-d');
                $dayStart = $day->setTime(0, 0);
                $events = $this->events->findInRange($dayStart, $dayStart->modify('+1 day'), new EventFilter(), $region);
                if (\count($events) < 2) {
                    continue;
                }

                $fpBefore = $this->fingerprint($events);
                /** @var AiDedupDay|null $rec */
                $rec = $dayRepo->find(['regionKey' => $region->getKey(), 'day' => $dayKey]);
                if (!$force && $rec !== null && $rec->getFingerprint() === $fpBefore) {
                    continue; // unchanged since last review
                }
                if (!$this->hasCandidatePair($events)) {
                    $this->storeDay($dayRepo, $rec, $region, $dayKey, $fpBefore);
                    $this->em->flush();
                    continue;
                }
                if ($aiCalls >= $budget) {
                    $io->warning(sprintf('AI-Budget (%d Aufrufe) erreicht – Rest übersprungen.', $budget));
                    break 2;
                }

                ++$aiCalls;
                ++$reviewed;
                try {
                    $proposals = $this->deduper->findDuplicates($day, $events, $region);
                } catch (AiUnavailableException $e) {
                    // Don't store the day's fingerprint — it gets re-reviewed next
                    // run; days processed before this one stay saved.
                    $io->error(sprintf('KI nicht verfügbar – Lauf abgebrochen, Tag %s/%s bleibt ungeprüft: %s', $region->getKey(), $dayKey, $e->getMessage()));

                    return Command::FAILURE;
                }

                /** @var array<int, Event> $byId */
                $byId = [];
                foreach ($events as $e) {
                    $byId[$e->getId()] = $e;
                }

                foreach ($proposals as $p) {
                    $dup = $byId[$p['duplicate']] ?? null;
                    $can = $byId[$p['canonical']] ?? null;
                    // Validate: both on this day, both still Published, canonical not itself a duplicate, no self/chain.
                    if ($dup === null || $can === null || $dup === $can
                        || $dup->getStatus() !== EventStatus::Published
                        || $can->getStatus() !== EventStatus::Published
                        || $can->getDuplicateOf() !== null) {
                        continue;
                    }
                    if (isset($vetoedPairs[$dup->getId().':'.$can->getId()])) {
                        $io->writeln(sprintf(
                            '<comment>%s</comment>  „%s" (#%d) / „%s" (#%d): Admin-Veto (Undo) – übersprungen.',
                            $dayKey,
                            $dup->getTitle(),
                            $dup->getId(),
                            $can->getTitle(),
                            $can->getId(),
                        ));
                        continue;
                    }
                    $io->writeln(sprintf(
                        '<comment>%s</comment>  „%s" (#%d) → behalte „%s" (#%d)  %s',
                        $dayKey,
                        $dup->getTitle(),
                        $dup->getId(),
                        $can->getTitle(),
                        $can->getId(),
                        $p['reason'] !== '' ? '– '.$p['reason'] : '',
                    ));
                    if (!$dryRun) {
                        $this->merger->apply($dup, $can, $dayStart, $this->deduper->getModel(), $p['reason']);
                        ++$merges;
                    }
                }

                if (!$dryRun) {
                    $this->em->flush();
                    // Recompute fingerprint from the post-merge visible set so the next run skips it.
                    $after = $this->events->findInRange($dayStart, $dayStart->modify('+1 day'), new EventFilter(), $region);
                    $this->storeDay($dayRepo, $dayRepo->find(['regionKey' => $region->getKey(), 'day' => $dayKey]), $region, $dayKey, $this->fingerprint($after));
                    $this->em->flush();
                }
            }
        }

        $io->success(sprintf(
            '%s: %d Tage geprüft, %d AI-Aufrufe, %d Merges%s.',
            $dryRun ? 'Dry-run' : 'Fertig',
            $reviewed,
            $aiCalls,
            $merges,
            $dryRun ? ' (nichts geschrieben)' : '',
        ));

        return Command::SUCCESS;
    }

    /**
     * Pairs whose AI merge an admin explicitly undid (in /admin/dedup). Such an
     * undo is a veto in both directions: after the undo the day's fingerprint
     * changes, the model sees the same inputs again and would otherwise
     * silently re-apply the overruled merge on the next run. --reset undos
     * don't set the marker and stay re-decidable.
     *
     * @return array<string, true> keys "dupId:canId" and "canId:dupId"
     */
    private function adminVetoedPairs(): array
    {
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(d.duplicateEvent) AS dup, IDENTITY(d.canonicalEvent) AS can'
            .' FROM '.AiDedupDecision::class.' d WHERE d.undoneByAdminAt IS NOT NULL',
        )->getScalarResult();

        $pairs = [];
        foreach ($rows as $row) {
            if ($row['dup'] === null || $row['can'] === null) {
                continue;
            }
            $pairs[$row['dup'].':'.$row['can']] = true;
            $pairs[$row['can'].':'.$row['dup']] = true;
        }

        return $pairs;
    }

    /** @param Event[] $events */
    private function fingerprint(array $events): string
    {
        $ids = array_map(static fn (Event $e) => $e->getId(), $events);
        sort($ids);

        return sha1(implode(',', $ids));
    }

    /**
     * Cheap pre-filter: decide whether a day is worth an AI call. It only needs
     * to gate cost — the AI is the actual judge — so we cast a fairly wide net
     * and let the model reject non-duplicates. A pair qualifies when titles are
     * equal, one normalized title contains the other (e.g. "Repair-Café" ⊂
     * "Makerspace Repaircafé"), titles are reasonably similar, OR the events
     * share a start time and a venue token (same place, same time, different
     * wording — exactly what the key-dedup misses).
     *
     * @param Event[] $events
     */
    private function hasCandidatePair(array $events): bool
    {
        $n = \count($events);
        $titles = [];
        $times = [];
        $locTokens = [];
        foreach ($events as $idx => $e) {
            $titles[$idx] = ImportedEvent::normalizeTitle($e->getTitle());
            $times[$idx] = $e->isAllDay() ? 'allday' : $e->getStartsAt()->format('H:i');
            $locTokens[$idx] = $this->locationTokens($e->getDisplayLocation() ?? '');
        }

        for ($i = 0; $i < $n; ++$i) {
            for ($j = $i + 1; $j < $n; ++$j) {
                $a = $titles[$i];
                $b = $titles[$j];
                if ($a !== '' && $b !== '') {
                    if ($a === $b) {
                        return true;
                    }
                    $short = \strlen($a) <= \strlen($b) ? $a : $b;
                    $long = $short === $a ? $b : $a;
                    if (\strlen($short) >= 5 && str_contains($long, $short)) {
                        return true;
                    }
                    similar_text($a, $b, $pct);
                    if ($pct >= 60.0) {
                        return true;
                    }
                }
                // Same time + same place, regardless of wording.
                if ($times[$i] === $times[$j] && array_intersect($locTokens[$i], $locTokens[$j]) !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Significant tokens (≥4 chars) of a location string, for cheap venue
     * matching in the pre-filter. "Stadtbibliothek Gütersloh · Gütersloh"
     * → ["stadtbibliothek", "gutersloh"].
     *
     * @return string[]
     */
    private function locationTokens(string $location): array
    {
        $cleaned = preg_replace('/[^a-z0-9äöüß]+/u', ' ', mb_strtolower($location)) ?? '';
        $tokens = array_filter(preg_split('/\s+/', trim($cleaned)) ?: [], static fn (string $t) => mb_strlen($t) >= 4);

        return array_values(array_unique($tokens));
    }

    /**
     * @return Region[]
     */
    private function selectedRegions(InputInterface $input, SymfonyStyle $io): array
    {
        $key = trim((string) $input->getOption('region'));
        if ($key === '') {
            return $this->regions->findEnabled();
        }

        $region = $this->regions->findByKey($key);
        if ($region === null) {
            $io->error(sprintf('Region "%s" nicht gefunden.', $key));

            return [];
        }

        return [$region];
    }

    /** @param Region[] $regions */
    private function resetRegions(array $regions): int
    {
        $keys = array_flip(array_map(static fn (Region $region): string => $region->getKey(), $regions));
        $active = $this->em->getRepository(AiDedupDecision::class)->findBy(['active' => true]);
        $reset = 0;
        foreach ($active as $decision) {
            $event = $decision->getDuplicateEvent() ?? $decision->getCanonicalEvent();
            if ($event !== null && isset($keys[$event->getRegion()->getKey()])) {
                $this->merger->undo($decision);
                ++$reset;
            }
        }
        foreach ($regions as $region) {
            $this->em->createQuery('DELETE FROM '.AiDedupDay::class.' d WHERE d.regionKey = :regionKey')
                ->setParameter('regionKey', $region->getKey())
                ->execute();
        }

        return $reset;
    }

    private function storeDay(object $dayRepo, ?AiDedupDay $rec, Region $region, string $dayKey, string $fp): void
    {
        $now = $this->clock->now();
        if ($rec === null) {
            $this->em->persist(new AiDedupDay($region->getKey(), $dayKey, $fp, $now));
        } else {
            $rec->setFingerprint($fp)->setReviewedAt($now);
        }
    }
}
