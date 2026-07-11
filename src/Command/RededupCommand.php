<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Event;
use App\Enum\EventStatus;
use App\Repository\EventRepository;
use App\Repository\RegionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Key-based cross-source dedup with source-tier preference: within each dedup
 * key, the version from a PRIMARY source (a venue/organizer/city) wins over an
 * aggregator (Erfolgskreis, veranstaltungen-gt, Radio GT, marktcom, flowl, NW),
 * so the public listing shows the richer original and the aggregator copy is
 * demoted. Idempotent; meant to run right after the import cron.
 *
 * Leaves AI-dedup decisions (fuzzy, different keys) untouched.
 */
#[AsCommand(name: 'dalketicker:rededup', description: 'Cross-Source-Dedup mit Primärquellen-Vorrang (nach dem Import)')]
final class RededupCommand extends Command
{
    /** Importer keys that are third-party aggregators (lower priority). */
    private const AGGREGATORS = ['erfolgskreis_gt', 'auf_schluer', 'radio_gt', 'marktcom', 'flowl', 'nw'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly RegionRepository $regions,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur anzeigen, nichts ändern')
            ->addOption('region', null, InputOption::VALUE_REQUIRED, 'Nur diese Region verarbeiten (z. B. guetersloh)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $region = null;
        $regionKey = trim((string) $input->getOption('region'));
        if ($regionKey !== '') {
            $region = $this->regions->findByKey($regionKey);
            if ($region === null) {
                $io->error(sprintf('Region "%s" nicht gefunden.', $regionKey));

                return Command::INVALID;
            }
        }
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Berlin'));

        // Upcoming, key-deduplicatable events (published or key-duplicate).
        // We group by dedup key — events sharing an exact key are definitely the
        // same event, so key-based dedup is authoritative and may override a
        // same-key AI merge. Fuzzy AI merges use *different* keys, so they land
        // in single-member groups below and stay untouched.
        $qb = $this->em->createQueryBuilder()
            ->select('e', 's')
            ->from(Event::class, 'e')
            ->join('e.source', 's')
            ->where('e.status IN (:st)')
            // Same relevance window as the website: the nightly 04:45 run must
            // still cover the started day's all-day events, or their duplicate
            // copies would stay visible for the whole day.
            ->andWhere(EventRepository::stillRelevantDql('e', 'now'))
            ->setParameter('st', [EventStatus::Published, EventStatus::Duplicate]);
        EventRepository::setRelevanceFloor($qb, 'now', $now);
        if ($region !== null) {
            $qb->andWhere('e.region = :region')->setParameter('region', $region);
        }
        /** @var Event[] $events */
        $events = $qb->getQuery()->getResult();

        /** @var array<string, Event[]> $groups */
        $groups = [];
        foreach ($events as $e) {
            $groups[$this->groupKey($e)][] = $e;
        }

        $flips = 0;
        $promoted = [];
        foreach ($groups as $group) {
            if (\count($group) < 2) {
                continue;
            }
            usort($group, $this->compare(...));
            $winner = $group[0];

            // The primary winner borrows image/text/organizer it lacks from the
            // richer (often aggregator) copies, so we keep the primary source as
            // canonical without losing the flyer image. Re-applied every run, so
            // it survives the import overwriting the primary's empty fields.
            // Never borrow from facts-only or disabled sources — their creative
            // content (text/images) must not surface on a public event (legal
            // safeguard; disabling a source is the opt-out switch).
            if (!$dryRun) {
                foreach (\array_slice($group, 1) as $loser) {
                    if ($loser->getSource()->isFactsOnly() || !$loser->getSource()->isEnabled()) {
                        continue;
                    }
                    if ($winner->getImageUrl() === null && $loser->getImageUrl() !== null) {
                        $winner->setImageUrl($loser->getImageUrl());
                    }
                    if (($winner->getDescription() === null || $winner->getDescription() === '') && (string) $loser->getDescription() !== '') {
                        $winner->setDescription($loser->getDescription());
                    }
                    if ($winner->getOrganizer() === null && (string) $loser->getOrganizer() !== '') {
                        $winner->setOrganizer($loser->getOrganizer());
                    }
                }
            }

            foreach ($group as $i => $e) {
                $shouldBeDuplicate = $i !== 0;
                $isDuplicate = $e->getStatus() === EventStatus::Duplicate;

                if (!$shouldBeDuplicate && $isDuplicate) {
                    // promote to canonical
                    if (!$dryRun) {
                        $e->setStatus(EventStatus::Published)->setDuplicateOf(null);
                    }
                    ++$flips;
                    $promoted[] = sprintf('%s ← %s (#%d)', $e->getSource()->getImporter(), $winner->getTitle(), $e->getId());
                } elseif ($shouldBeDuplicate && (!$isDuplicate || $e->getDuplicateOf() !== $winner)) {
                    if (!$dryRun) {
                        $e->setStatus(EventStatus::Duplicate)->setDuplicateOf($winner);
                    }
                    ++$flips;
                }
            }
        }

        $rescued = $this->rescueStrandedDuplicates($events, $groups, $dryRun, $promoted);
        $flips += $rescued;

        if (!$dryRun) {
            $this->em->flush();
            // Retire AI-dedup decisions whose canonical we just demoted — the
            // key-based primary decision supersedes them (and keeps the AI pass
            // from flip-flopping the pair next run).
            $this->db->executeStatement(
                "UPDATE ai_dedup_decision SET active = false WHERE active = true AND canonical_event_id IN (SELECT id FROM event WHERE status = 'duplicate')"
            );
        }

        foreach (\array_slice($promoted, 0, 15) as $line) {
            $io->writeln('  <info>promoted</info> '.$line);
        }
        $io->success(sprintf('%s: %d Gruppen, %d Änderungen, davon %d gestrandete Duplikate%s.', $dryRun ? 'Dry-run' : 'Fertig', \count(array_filter($groups, static fn ($g) => \count($g) >= 2)), $flips, $rescued, $dryRun ? ' (nichts geschrieben)' : ''));

        return Command::SUCCESS;
    }

    private function groupKey(Event $event): string
    {
        return $event->getRegion()->getKey().'|'.$event->getDedupKey();
    }

    /**
     * Rescue pass: a key-based demotion is otherwise a one-way street. Promote
     * (or re-hang) duplicates whose canonical vanished (deleted → FK SET NULL),
     * whose canonical is itself a duplicate (chain), or whose key no longer has
     * a published counterpart (event moved to another day). A canonical that an
     * admin set to Hidden (no prunedAt) stays untouched — that hiding was
     * deliberate, and so are fuzzy AI merges (different keys, backed by an
     * AiDedupDecision). A canonical hidden by prune-unseen (prunedAt set) is
     * automation, so its duplicate gets promoted instead.
     *
     * @param Event[]               $events   upcoming published/duplicate events (as loaded above)
     * @param array<string, Event[]> $groups  the same events grouped by dedup key
     * @param list<string>          $promoted log lines, appended to
     *
     * @return int number of changes
     */
    private function rescueStrandedDuplicates(array $events, array $groups, bool $dryRun, array &$promoted): int
    {
        $changes = 0;
        $aiDupIds = null; // lazily fetched: events demoted by an active AI merge

        foreach ($events as $e) {
            if ($e->getStatus() !== EventStatus::Duplicate) {
                continue;
            }
            // Keys with >= 2 members are governed by the key pass above.
            if (\count($groups[$this->groupKey($e)] ?? []) >= 2) {
                continue;
            }

            $target = $e->getDuplicateOf();

            if ($target === null) {
                // Canonical was deleted (FK SET NULL) — the event is orphaned.
                $changes += $this->promote($e, $dryRun, $promoted, 'verwaist');
                continue;
            }

            if ($target->getStatus() === EventStatus::Duplicate) {
                // Chain: re-hang onto the chain's end, or promote when the
                // chain dangles (ends in null or a cycle).
                $end = $this->chainEnd($target);
                if ($end === null) {
                    $changes += $this->promote($e, $dryRun, $promoted, 'Kette ohne Ziel');
                } elseif ($end->getStatus() === EventStatus::Published) {
                    if (!$end->getSource()->isEnabled()) {
                        // Canonical is off the site (source disabled) — the
                        // duplicate would be publicly invisible behind it.
                        $changes += $this->promote($e, $dryRun, $promoted, 'Kette endet in deaktivierter Quelle');
                        continue;
                    }
                    if (!$dryRun) {
                        $e->setDuplicateOf($end);
                        $this->repointAiDecision($e, $end);
                    }
                    ++$changes;
                } elseif ($end->getStatus() === EventStatus::Hidden && $end->getPrunedAt() !== null) {
                    // prune-unseen hid the chain's end — not an admin call.
                    $changes += $this->promote($e, $dryRun, $promoted, 'Kette endet in gepruntem Canonical');
                }
                // Chain ending in admin-Hidden (no prunedAt): deliberate, leave it.
                continue;
            }

            if ($target->getStatus() === EventStatus::Hidden) {
                // Hidden by prune-unseen (prunedAt set) is automation, not an
                // admin decision — the duplicate may resurface. A manual
                // Hidden (no prunedAt) stays untouched.
                if ($target->getPrunedAt() !== null) {
                    $changes += $this->promote($e, $dryRun, $promoted, 'Canonical geprunt');
                }
                continue;
            }

            if (!$target->getSource()->isEnabled()) {
                // Canonical is Published but its source was disabled: the
                // canonical is hidden by the enabled filter, the duplicate by
                // its status — the event would be publicly gone.
                $changes += $this->promote($e, $dryRun, $promoted, 'Canonical-Quelle deaktiviert');
                continue;
            }

            if ($target->getDedupKey() === $e->getDedupKey()) {
                continue; // keys still match (target just isn't in the upcoming set) — link is valid
            }

            // Canonical is published, but the keys no longer match (single-member
            // group): either the event moved to a new day or this is a fuzzy AI
            // merge. Only promote when no AI decision backs the link.
            $aiDupIds ??= array_flip(array_map(intval(...), $this->db->fetchFirstColumn(
                'SELECT duplicate_event_id FROM ai_dedup_decision WHERE active = true AND duplicate_event_id IS NOT NULL',
            )));
            if (!isset($aiDupIds[(int) $e->getId()])) {
                $changes += $this->promote($e, $dryRun, $promoted, 'Key ohne Published-Pendant');
            }
        }

        return $changes;
    }

    /**
     * Keep an active AI merge backing $duplicate pointing at the live canonical
     * after a re-hang — otherwise the blanket retire in execute() deactivates
     * the decision (its old canonical is now a duplicate) and the next run
     * promotes + re-merges the pair (flip-flop with a day of visible duplicate).
     */
    private function repointAiDecision(Event $duplicate, Event $canonical): void
    {
        $this->db->executeStatement(
            'UPDATE ai_dedup_decision SET canonical_event_id = :canonical WHERE active = true AND duplicate_event_id = :duplicate',
            ['canonical' => $canonical->getId(), 'duplicate' => $duplicate->getId()],
        );
    }

    /** @param list<string> $promoted log lines, appended to */
    private function promote(Event $e, bool $dryRun, array &$promoted, string $reason): int
    {
        if (!$dryRun) {
            $e->setStatus(EventStatus::Published)->setDuplicateOf(null);
        }
        $promoted[] = sprintf('%s (#%d, %s) — %s', $e->getTitle(), $e->getId(), $e->getSource()->getImporter(), $reason);

        return 1;
    }

    /**
     * Follow a duplicate chain to its first non-duplicate event; null when the
     * chain dangles (SET-NULL hole) or cycles.
     */
    private function chainEnd(Event $start): ?Event
    {
        $seen = [];
        $cur = $start;
        while ($cur !== null && $cur->getStatus() === EventStatus::Duplicate) {
            $id = $cur->getId();
            if ($id === null || isset($seen[$id])) {
                return null; // cycle
            }
            $seen[$id] = true;
            $cur = $cur->getDuplicateOf();
        }

        return $cur;
    }

    /**
     * Winner sort: enabled source before disabled (a disabled source's events
     * are off the site and must not act as canonical), then primary before
     * aggregator, then richer (image, longer text), then oldest.
     */
    private function compare(Event $a, Event $b): int
    {
        return [$a->getSource()->isEnabled() ? 0 : 1, $this->tier($a), $this->richness($a) * -1, $a->getId()]
            <=> [$b->getSource()->isEnabled() ? 0 : 1, $this->tier($b), $this->richness($b) * -1, $b->getId()];
    }

    private function tier(Event $e): int
    {
        return \in_array($e->getSource()->getImporter(), self::AGGREGATORS, true) ? 1 : 0;
    }

    private function richness(Event $e): int
    {
        return ($e->getImageUrl() !== null ? 100000 : 0) + mb_strlen((string) $e->getDescription());
    }
}
