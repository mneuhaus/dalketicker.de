<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Venue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Venue>
 */
class VenueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Venue::class);
    }

    public function findByDedupKey(string $dedupKey): ?Venue
    {
        return $this->findOneBy(['dedupKey' => $dedupKey]);
    }

    /**
     * Resolve (or lazily create) a venue from a name + city. Returns null when
     * the name is empty so callers can fall back to a free-text location.
     */
    public function findOrCreate(?string $name, ?string $city): ?Venue
    {
        $name = trim((string) $name);
        $city = trim((string) ($city ?: 'Kreis Gütersloh'));
        if ($name === '') {
            return null;
        }

        $dedupKey = Venue::buildDedupKey($name, $city);
        $venue = $this->findByDedupKey($dedupKey);
        if ($venue === null) {
            $venue = new Venue($name, $city);
            $this->getEntityManager()->persist($venue);
        }

        return $venue;
    }

    /** @return Venue[] */
    public function findAllOrderedByName(): array
    {
        return $this->createQueryBuilder('v')
            ->orderBy('v.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
