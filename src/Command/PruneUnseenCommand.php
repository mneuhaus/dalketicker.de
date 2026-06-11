<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Event;
use App\Entity\ImportRun;
use App\Enum\EventStatus;
use App\Enum\SourceType;
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
 * Data lifecycle: hide upcoming events their source stopped listing. When an
 * event's lastSeenAt is older than N days although its (enabled) source had a
 * successful import in that window, the source dropped it — most likely
 * cancelled — so it must not stay on the public listing. Sources without a
 * recent successful run are skipped entirely: a broken importer must not wipe
 * its events. Manual sources and manually added events ("manual:" external id,
 * {@see AddEventCommand}) are never touched.
 *
 * Hidden events keep a prunedAt marker; once the source lists the event again,
 * the importer republishes it ({@see \App\Service\EventImporter}) — the prune
 * is not a one-way street.
 *
 * Dry-run by default; --apply writes. Meant to run after the import cron.
 */
#[AsCommand(name: 'dalketicker:prune-unseen', description: 'Anstehende Events verstecken, die ihre Quelle nicht mehr listet (Dry-Run ohne --apply)')]
final class PruneUnseenCommand extends Command
{
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
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Änderungen wirklich schreiben (Standard: Dry-Run)')
            ->addOption('region', null, InputOption::VALUE_REQUIRED, 'Nur diese Region verarbeiten (z. B. guetersloh)')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Nicht-mehr-gesehen-Schwelle in Tagen', '7');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $region = null;
        $regionKey = trim((string) $input->getOption('region'));
        if ($regionKey !== '') {
            $region = $this->regions->findByKey($regionKey);
            if ($region === null) {
                $io->error(sprintf('Region "%s" nicht gefunden.', $regionKey));

                return Command::INVALID;
            }
        }
        $days = (int) $input->getOption('days');
        if ($days < 1) {
            $io->error('--days muss mindestens 1 sein.');

            return Command::INVALID;
        }

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Berlin'));
        $cutoff = $now->modify(sprintf('-%d days', $days));

        // Only sources that demonstrably imported fine within the window may
        // lose events — a broken or paused source keeps its data until it
        // works again (its events' lastSeenAt being stale proves nothing then).
        $healthySql = 'SELECT DISTINCT r.source_id FROM import_run r INNER JOIN source s ON s.id = r.source_id WHERE r.status = :ok AND r.dry_run = false AND r.started_at >= :cutoff';
        $healthyParams = ['ok' => ImportRun::STATUS_OK, 'cutoff' => $cutoff->format('Y-m-d H:i:s')];
        if ($region !== null) {
            $healthySql .= ' AND s.region_id = :region';
            $healthyParams['region'] = $region->getId();
        }
        $healthySourceIds = array_map(intval(...), $this->db->fetchFirstColumn($healthySql, $healthyParams));
        if ($healthySourceIds === []) {
            $io->success(sprintf('Keine Quelle mit erfolgreichem Import in den letzten %d Tagen — nichts zu tun.', $days));

            return Command::SUCCESS;
        }

        $qb = $this->em->createQueryBuilder()
            ->select('e', 's')
            ->from(Event::class, 'e')
            ->join('e.source', 's')
            ->where('e.status = :published')
            ->andWhere('COALESCE(e.endsAt, e.startsAt) >= :now')
            ->andWhere('e.lastSeenAt < :cutoff')
            ->andWhere('s.enabled = true')
            ->andWhere('s.id IN (:healthy)')
            ->andWhere('s.type != :manualType')
            ->andWhere('(e.externalId IS NULL OR e.externalId NOT LIKE :manualPrefix)')
            ->setParameter('published', EventStatus::Published)
            ->setParameter('now', $now)
            ->setParameter('cutoff', $cutoff)
            ->setParameter('healthy', $healthySourceIds)
            ->setParameter('manualType', SourceType::Manual)
            ->setParameter('manualPrefix', 'manual:%')
            ->orderBy('e.startsAt', 'ASC');
        if ($region !== null) {
            $qb->andWhere('e.region = :region')->setParameter('region', $region);
        }
        /** @var Event[] $stale */
        $stale = $qb->getQuery()->getResult();

        foreach ($stale as $event) {
            $io->writeln(sprintf(
                '  <comment>%s</comment> %s (#%d, %s, zuletzt gesehen %s)',
                $event->getStartsAt()->format('d.m.Y'),
                $event->getTitle(),
                $event->getId(),
                $event->getSource()->getKey(),
                $event->getLastSeenAt()->format('d.m.Y'),
            ));
            if ($apply) {
                $event->setStatus(EventStatus::Hidden)->setPrunedAt($now);
            }
        }

        if ($apply) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%s: %d anstehende Events seit mehr als %d Tagen nicht mehr in ihrer Quelle gesehen%s.',
            $apply ? 'Fertig' : 'Dry-Run',
            \count($stale),
            $days,
            $apply ? ' → versteckt' : ' (nichts geändert, --apply zum Anwenden)',
        ));

        return Command::SUCCESS;
    }
}
