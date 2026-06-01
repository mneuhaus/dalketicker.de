<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\AiDeduper;
use App\Ai\DuplicateMerger;
use App\Entity\AiDedupDay;
use App\Entity\Event;
use App\Enum\EventStatus;
use App\Importer\ImportedEvent;
use App\Repository\EventRepository;
use App\Search\EventFilter;
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
    public function __construct(
        private readonly EventRepository $events,
        private readonly AiDeduper $deduper,
        private readonly DuplicateMerger $merger,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
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
            ->addOption('max-ai-calls', null, InputOption::VALUE_REQUIRED, 'Obergrenze AI-Aufrufe pro Lauf', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tz = new \DateTimeZone('Europe/Berlin');
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $days = max(1, (int) $input->getOption('days'));
        $budget = max(1, (int) $input->getOption('max-ai-calls'));

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

        $io->title('AI-Dedup'.($dryRun ? ' (dry-run)' : ''));
        $dayRepo = $this->em->getRepository(AiDedupDay::class);
        $aiCalls = 0;
        $merges = 0;
        $reviewed = 0;

        for ($i = 0; $i < $days; ++$i) {
            $day = $from->modify('+'.$i.' days');
            $dayKey = $day->format('Y-m-d');
            $dayStart = $day->setTime(0, 0);
            $events = $this->events->findInRange($dayStart, $dayStart->modify('+1 day'), new EventFilter());
            if (\count($events) < 2) {
                continue;
            }

            $fpBefore = $this->fingerprint($events);
            /** @var AiDedupDay|null $rec */
            $rec = $dayRepo->find($dayKey);
            if (!$force && $rec !== null && $rec->getFingerprint() === $fpBefore) {
                continue; // unchanged since last review
            }
            if (!$this->hasCandidatePair($events)) {
                $this->storeDay($dayRepo, $rec, $dayKey, $fpBefore);
                $this->em->flush();
                continue;
            }
            if ($aiCalls >= $budget) {
                $io->warning(sprintf('AI-Budget (%d Aufrufe) erreicht – Rest übersprungen.', $budget));
                break;
            }

            ++$aiCalls;
            ++$reviewed;
            $proposals = $this->deduper->findDuplicates($day, $events);

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
                $after = $this->events->findInRange($dayStart, $dayStart->modify('+1 day'), new EventFilter());
                $this->storeDay($dayRepo, $dayRepo->find($dayKey), $dayKey, $this->fingerprint($after));
                $this->em->flush();
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

    /** @param Event[] $events */
    private function fingerprint(array $events): string
    {
        $ids = array_map(static fn (Event $e) => $e->getId(), $events);
        sort($ids);

        return sha1(implode(',', $ids));
    }

    /**
     * Cheap pre-filter: only worth an AI call if at least two events have
     * similar normalized titles (catches the cases the key-dedup missed).
     *
     * @param Event[] $events
     */
    private function hasCandidatePair(array $events): bool
    {
        $titles = array_map(static fn (Event $e) => ImportedEvent::normalizeTitle($e->getTitle()), $events);
        $n = \count($titles);
        for ($i = 0; $i < $n; ++$i) {
            for ($j = $i + 1; $j < $n; ++$j) {
                $a = $titles[$i];
                $b = $titles[$j];
                if ($a === '' || $b === '') {
                    continue;
                }
                if ($a === $b) {
                    return true;
                }
                similar_text($a, $b, $pct);
                if ($pct >= 72.0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function storeDay(object $dayRepo, ?AiDedupDay $rec, string $dayKey, string $fp): void
    {
        $now = $this->clock->now();
        if ($rec === null) {
            $this->em->persist(new AiDedupDay($dayKey, $fp, $now));
        } else {
            $rec->setFingerprint($fp)->setReviewedAt($now);
        }
    }
}
