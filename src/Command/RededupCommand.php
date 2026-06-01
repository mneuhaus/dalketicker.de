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

        // Events the AI deduper manages must not be re-published by this pass.
        $aiDup = array_flip(array_map('intval', $this->db->fetchFirstColumn(
            'SELECT duplicate_event_id FROM ai_dedup_decision WHERE active = true'
        )));

        // Upcoming, key-deduplicatable events (published or key-duplicate).
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
            if (isset($aiDup[$e->getId()])) {
                continue; // leave AI-managed duplicates alone
            }
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
