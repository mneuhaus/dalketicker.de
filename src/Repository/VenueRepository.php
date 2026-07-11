<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Region;
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

    public function findByDedupKey(string $dedupKey, Region $region): ?Venue
    {
        return $this->findOneBy(['dedupKey' => $dedupKey, 'region' => $region]);
    }

    /**
     * Resolve (or lazily create) a venue from a name + city. Returns null when
     * the name is empty so callers can fall back to a free-text location.
     */
    public function findOrCreate(?string $name, ?string $city, Region $region): ?Venue
    {
        // Cap to the column lengths (name 200, city 120) before the dedup key
        // is built, so key and stored values stay consistent and an overlong
        // scraped LOCATION string can't fail the whole batch flush.
        $name = mb_substr(trim((string) $name), 0, 200);
        $city = mb_substr(trim((string) ($city ?: $region->getDefaultCity())), 0, 120);
        if ($name === '') {
            return null;
        }

        $dedupKey = Venue::buildDedupKey($name, $city);
        $memoKey = $region->getKey().'|'.$dedupKey;
        if (isset($this->createdThisRun[$memoKey])) {
            return $this->createdThisRun[$memoKey];
        }

        $venue = $this->findByDedupKey($dedupKey, $region);
        if ($venue === null) {
            // Two parallel imports (--parallel-scope=source) can race on the
            // same brand-new venue; ON CONFLICT lets the loser adopt the
            // winner's committed row instead of failing its batch flush later.
            $this->getEntityManager()->getConnection()->executeStatement(
                'INSERT INTO venue (name, city, region_id, dedup_key) VALUES (:name, :city, :region, :key) ON CONFLICT (region_id, dedup_key) DO NOTHING',
                ['name' => $name, 'city' => $city, 'region' => $region->getId(), 'key' => $dedupKey],
            );
            $venue = $this->findByDedupKey($dedupKey, $region);
        }
        if ($venue === null) {
            // Re-select found nothing (should not happen) — fall back to a
            // plain persist so the import still proceeds.
            $venue = new Venue($name, $city, $region);
            $this->getEntityManager()->persist($venue);
        }

        return $this->createdThisRun[$memoKey] = $venue;
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
