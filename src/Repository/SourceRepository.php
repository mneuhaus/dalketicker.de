<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Source;
use App\Entity\Region;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Source>
 */
class SourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Source::class);
    }

    public function findByKey(string $key, ?Region $region = null): ?Source
    {
        $criteria = ['key' => $key];
        if ($region !== null) {
            $criteria['region'] = $region;
        }

        return $this->findOneBy($criteria);
    }

    /** @return Source[] */
    public function findAllByKey(string $key): array
    {
        return $this->findBy(['key' => $key], ['name' => 'ASC']);
    }

    /** @return Source[] */
    public function findEnabled(?Region $region = null): array
    {
        $criteria = ['enabled' => true];
        if ($region !== null) {
            $criteria['region'] = $region;
        }

        return $this->findBy($criteria, ['name' => 'ASC']);
    }

    /** @return Source[] */
    public function findForRegion(Region $region): array
    {
        return $this->findBy(['region' => $region], ['name' => 'ASC']);
    }

    /** @return Source[] */
    public function findForAdmin(?Region $region = null): array
    {
        if ($region !== null) {
            return $this->findForRegion($region);
        }

        return $this->findBy([], ['name' => 'ASC']);
    }
}
