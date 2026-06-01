<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Event;
use App\Enum\EventStatus;
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur anzeigen, nichts ändern');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Berlin'));

        // Upcoming, key-deduplicatable events (published or key-duplicate).
        // We group by dedup key — events sharing an exact key are definitely the
        // same event, so key-based dedup is authoritative and may override a
        // same-key AI merge. Fuzzy AI merges use *different* keys, so they land
        // in single-member groups below and stay untouched.
        /** @var Event[] $events */
        $events = $this->em->createQueryBuilder()
            ->select('e', 's')
            ->from(Event::class, 'e')
            ->join('e.source', 's')
            ->where('e.status IN (:st)')
            ->andWhere('COALESCE(e.endsAt, e.startsAt) >= :now')
            ->setParameter('st', [EventStatus::Published, EventStatus::Duplicate])
            ->setParameter('now', $now)
            ->getQuery()->getResult();

        /** @var array<string, Event[]> $groups */
        $groups = [];
        foreach ($events as $e) {
            $groups[$e->getDedupKey()][] = $e;
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
            if (!$dryRun) {
                foreach (\array_slice($group, 1) as $loser) {
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
        $io->success(sprintf('%s: %d Gruppen, %d Änderungen%s.', $dryRun ? 'Dry-run' : 'Fertig', \count(array_filter($groups, static fn ($g) => \count($g) >= 2)), $flips, $dryRun ? ' (nichts geschrieben)' : ''));

        return Command::SUCCESS;
    }

    /** Winner sort: primary before aggregator, then richer (image, longer text), then oldest. */
    private function compare(Event $a, Event $b): int
    {
        return [$this->tier($a), $this->richness($a) * -1, $a->getId()]
            <=> [$this->tier($b), $this->richness($b) * -1, $b->getId()];
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
