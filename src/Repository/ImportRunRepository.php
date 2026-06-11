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
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.source', 's')->addSelect('s');
        if ($region !== null) {
            $qb->andWhere('s.region = :region')->setParameter('region', $region);
        }

        /** @var ImportRun[] $runs */
        $runs = $qb
            ->orderBy('r.startedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($runs as $run) {
            $sid = $run->getSource()->getId();
            if ($sid !== null && !isset($latest[$sid])) {
                $latest[$sid] = $run;
            }
        }

        return $latest;
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
