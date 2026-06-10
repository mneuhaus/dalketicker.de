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
    /**
     * In-memory identity map for venues created (but not yet flushed) during the
     * current request. Without it, several events referencing the same brand-new
     * venue would each persist a separate Venue with the same dedup key and
     * violate the unique constraint on flush. Must be cleared per import run
     * (see {@see resetRunMemo()}) — after an EntityManager reset the memoized
     * venues are detached and must not leak into the next run.
     *
     * @var array<string, Venue>
     */
    private array $createdThisRun = [];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Venue::class);
    }

    /** Drop the per-run venue memo (start of an import run / after an EM reset). */
    public function resetRunMemo(): void
    {
        $this->createdThisRun = [];
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
        if (isset($this->createdThisRun[$dedupKey])) {
            return $this->createdThisRun[$dedupKey];
        }

        $venue = $this->findByDedupKey($dedupKey);
        if ($venue === null) {
            $venue = new Venue($name, $city);
            $this->getEntityManager()->persist($venue);
        }

        return $this->createdThisRun[$dedupKey] = $venue;
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
