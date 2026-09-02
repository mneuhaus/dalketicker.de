<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ImportRun;
use App\Entity\Region;
use App\Entity\Source;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportRun>
 */
class ImportRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportRun::class);
    }

    /** @return ImportRun[] */
    public function findRecent(int $limit = 100, ?Region $region = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.source', 's')->addSelect('s');
        if ($region !== null) {
            $qb->andWhere('s.region = :region')->setParameter('region', $region);
        }

        return $qb
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The latest run per source, keyed by source id — for the overview table.
     *
     * @return array<int, ImportRun>
     */
    public function findLatestPerSource(?Region $region = null): array
    {
        // Only the newest row per source leaves the database. Nothing prunes
        // import_run except {@see deleteOlderThan}, and ~800 rows a day arrive
        // — hydrating them all just to keep the first per source does not age well.
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.source', 's')->addSelect('s')
            ->andWhere('r.startedAt = (SELECT MAX(r2.startedAt) FROM '.ImportRun::class.' r2 WHERE r2.source = r.source)');
        if ($region !== null) {
            $qb->andWhere('s.region = :region')->setParameter('region', $region);
        }

        /** @var ImportRun[] $runs */
        $runs = $qb->orderBy('r.id', 'DESC')->getQuery()->getResult();

        $latest = [];
        foreach ($runs as $run) {
            $sid = $run->getSource()->getId();
            if ($sid !== null && !isset($latest[$sid])) {
                $latest[$sid] = $run; // ties on startedAt: the newest id wins
            }
        }

        return $latest;
    }

    /**
     * When each source last had a successful (ok/partial) run — for the
     * "no successful run for over a week" warning. One aggregate query.
     *
     * @param list<int> $sourceIds
     *
     * @return array<int, \DateTimeImmutable>
     */
    public function lastSuccessPerSource(array $sourceIds): array
    {
        if ($sourceIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.source) AS sid, MAX(r.startedAt) AS lastAt')
            ->andWhere('r.source IN (:sources)')
            ->andWhere('r.status IN (:ok)')
            ->setParameter('sources', $sourceIds)
            ->setParameter('ok', [ImportRun::STATUS_OK, ImportRun::STATUS_PARTIAL])
            ->groupBy('r.source')
            ->getQuery()
            ->getArrayResult();

        $last = [];
        foreach ($rows as $row) {
            $at = $row['lastAt'];
            $last[(int) $row['sid']] = $at instanceof \DateTimeImmutable ? $at : new \DateTimeImmutable((string) $at);
        }

        return $last;
    }

    /**
     * The "seen" counts of the newest finished runs per source (newest first,
     * at most $perSource each), restricted to the last $days days — for the
     * "the last three runs came back empty" warning. With imports every
     * three hours that window holds plenty of runs per source.
     *
     * @param list<int> $sourceIds
     *
     * @return array<int, list<int>>
     */
    public function recentSeenCounts(array $sourceIds, int $perSource = 3, int $days = 3): array
    {
        if ($sourceIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.source) AS sid, r.seen AS seen')
            ->andWhere('r.source IN (:sources)')
            ->andWhere('r.status != :running')
            ->andWhere('r.startedAt >= :since')
            ->setParameter('sources', $sourceIds)
            ->setParameter('running', ImportRun::STATUS_RUNNING)
            ->setParameter('since', new \DateTimeImmutable(sprintf('-%d days', $days)))
            ->orderBy('r.startedAt', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $seen = [];
        foreach ($rows as $row) {
            $sid = (int) $row['sid'];
            if (\count($seen[$sid] ?? []) < $perSource) {
                $seen[$sid][] = (int) $row['seen'];
            }
        }

        return $seen;
    }

    /** Drop run records older than the cutoff; returns the number of rows removed. */
    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->andWhere('r.startedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }

    /** @return ImportRun[] */
    public function findForSource(Source $source, int $limit = 50): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.source = :source')
            ->setParameter('source', $source)
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
