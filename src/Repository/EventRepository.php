<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Enum\EventStatus;
use App\Search\EventFilter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /**
     * Visible upcoming events, ordered chronologically. "Upcoming" means the
     * event has not finished yet: its end (or start, if no end) is >= now.
     *
     * @return Event[]
     */
    public function findUpcoming(EventFilter $filter, int $limit = 50, int $offset = 0): array
    {
        $qb = $this->visibleQueryBuilder($filter);
        $this->applyUpcomingWindow($qb, $filter);

        // Sort by an "effective" start: events already running (started before
        // today but not yet ended, e.g. exhibitions) sort as if they start
        // today, so long-runners don't dominate the top with old start dates.
        $todayStart = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin')));

        return $qb
            ->addSelect('CASE WHEN e.startsAt < :todayStart THEN :todayStart ELSE e.startsAt END AS HIDDEN effStart')
            ->setParameter('todayStart', $todayStart)
            ->orderBy('effStart', 'ASC')
            ->addOrderBy('e.startsAt', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function countUpcoming(EventFilter $filter): int
    {
        $qb = $this->visibleQueryBuilder($filter);
        $this->applyUpcomingWindow($qb, $filter);

        return (int) $qb->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * Visible events overlapping the half-open range [$start, $end), ordered
     * chronologically. Multi-day events that straddle the bounds are included.
     *
     * @return Event[]
     */
    public function findInRange(\DateTimeImmutable $start, \DateTimeImmutable $end, EventFilter $filter): array
    {
        return $this->visibleQueryBuilder($filter)
            ->andWhere('e.startsAt < :end')
            ->andWhere('COALESCE(e.endsAt, e.startsAt) >= :start')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('e.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Visible events overlapping a given calendar month.
     *
     * @return Event[]
     */
    public function findForMonth(int $year, int $month, EventFilter $filter): array
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $start = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);

        return $this->findInRange($start, $start->modify('first day of next month'), $filter);
    }

    public function findVisible(int $id): ?Event
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.id = :id')
            ->andWhere('e.status = :published')
            ->setParameter('id', $id)
            ->setParameter('published', EventStatus::Published)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Distinct cities present on visible events, for the location filter. */
    public function findUsedCities(): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('DISTINCT v.city AS city')
            ->join('e.venue', 'v')
            ->andWhere('e.status = :published')
            ->setParameter('published', EventStatus::Published)
            ->orderBy('v.city', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_values(array_filter(array_column($rows, 'city')));
    }

    /**
     * Lookup an existing event for upsert, first by (source, externalId), then
     * by the cross-source dedup key.
     */
    public function findForUpsert(int $sourceId, ?string $externalId, string $dedupKey): ?Event
    {
        if ($externalId !== null && $externalId !== '') {
            $bySource = $this->findOneBy(['source' => $sourceId, 'externalId' => $externalId]);
            if ($bySource !== null) {
                return $bySource;
            }
        }

        return $this->findOneBy(['source' => $sourceId, 'dedupKey' => $dedupKey]);
    }

    /**
     * Find a visible event from a *different* source with the same dedup key —
     * used to flag cross-source duplicates.
     */
    public function findCrossSourceDuplicate(string $dedupKey, int $excludeSourceId): ?Event
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.dedupKey = :key')
            ->andWhere('e.source != :source')
            ->andWhere('e.status = :published')
            ->setParameter('key', $dedupKey)
            ->setParameter('source', $excludeSourceId)
            ->setParameter('published', EventStatus::Published)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function visibleQueryBuilder(EventFilter $filter): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.venue', 'v')->addSelect('v')
            ->leftJoin('e.category', 'c')->addSelect('c')
            ->leftJoin('e.source', 's')->addSelect('s')
            ->andWhere('e.status = :published')
            ->setParameter('published', EventStatus::Published);

        if ($filter->q !== null) {
            $qb->andWhere('LOWER(e.title) LIKE :q OR LOWER(e.description) LIKE :q OR LOWER(v.name) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower($filter->q).'%');
        }

        if ($filter->categorySlugs !== []) {
            $qb->andWhere('c.slug IN (:categorySlugs)')->setParameter('categorySlugs', $filter->categorySlugs);
        }

        if ($filter->city !== null) {
            $qb->andWhere('v.city = :city')->setParameter('city', $filter->city);
        }

        if ($filter->onlySaved) {
            if ($filter->savedIds === []) {
                $qb->andWhere('1 = 0'); // "meine Events" active but nothing saved
            } else {
                $qb->andWhere('e.id IN (:savedIds)')->setParameter('savedIds', $filter->savedIds);
            }
        }

        return $qb;
    }

    private function applyUpcomingWindow(QueryBuilder $qb, EventFilter $filter): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $now = new \DateTimeImmutable('now', $tz);

        // Default lower bound: things that haven't ended yet. An explicit
        // "von" filter overrides the floor; "Meine Events" drops it entirely so
        // saved past events still show up.
        if ($filter->from !== null) {
            $qb->andWhere('e.startsAt >= :from')->setParameter('from', $filter->from);
        } elseif (!$filter->onlySaved) {
            $qb->andWhere('COALESCE(e.endsAt, e.startsAt) >= :now')->setParameter('now', $now);
        }

        if ($filter->to !== null) {
            $qb->andWhere('e.startsAt < :to')->setParameter('to', $filter->to->modify('+1 day'));
        }
    }
}
