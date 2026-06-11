<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Region;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Region>
 */
class RegionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Region::class);
    }

    public function findByKey(string $key): ?Region
    {
        return $this->findOneBy(['key' => $key]);
    }

    public function findDefault(): Region
    {
        $region = $this->findByKey('guetersloh') ?? $this->findOneBy([], ['id' => 'ASC']);
        if ($region === null) {
            throw new \RuntimeException('No region configured. Run dalketicker:seed first.');
        }

        return $region;
    }

    public function findByHost(string $host): ?Region
    {
        $host = mb_strtolower(preg_replace('/:\d+$/', '', trim($host)) ?? $host);
        if ($host === '') {
            return null;
        }

        $direct = $this->findOneBy(['canonicalHost' => $host, 'enabled' => true]);
        if ($direct !== null) {
            return $direct;
        }

        foreach ($this->findBy(['enabled' => true]) as $region) {
            if ($region->matchesHost($host)) {
                return $region;
            }
        }

        return null;
    }

    /** @return Region[] */
    public function findEnabled(): array
    {
        return $this->findBy(['enabled' => true], ['siteName' => 'ASC']);
    }
}
