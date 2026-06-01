<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Entity\Source;
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
        $this->applySeriesCollapse($qb, $filter);

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
        $this->applySeriesCollapse($qb, $filter);

        return (int) $qb->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * Series-label data for the upcoming list: for each recurring entry (same
     * title + venue, ≥2 upcoming occurrences) the total count and the last date,
     * so the (single, collapsed) card can show "täglich · bis 26. Juni" instead
     * of appearing once per day. Keyed by "<title>|<venueId>".
     *
     * @return array<string, array{count: int, last: \DateTimeImmutable, daily: bool}>
     */
    public function seriesLabelInfo(EventFilter $filter): array
    {
        if ($filter->onlySaved) {
            return [];
        }

        $qb = $this->visibleQueryBuilder($filter);
        $qb->andWhere('COALESCE(e.endsAt, e.startsAt) >= :seriesFloor')
            ->setParameter('seriesFloor', $this->seriesFloor($filter));
        if ($filter->to !== null) {
            $qb->andWhere('e.startsAt < :seriesTo')->setParameter('seriesTo', $filter->to->modify('+1 day'));
        }

        $rows = $qb
            ->select('e.title AS title', 'IDENTITY(e.venue) AS venueId', 'COUNT(e.id) AS cnt', 'MAX(COALESCE(e.endsAt, e.startsAt)) AS lastAt', 'MIN(e.startsAt) AS firstAt')
            ->groupBy('e.title')->addGroupBy('e.venue')
            ->having('COUNT(e.id) > 1')
            ->getQuery()
            ->getResult();

        $info = [];
        foreach ($rows as $row) {
            $first = $this->toDate($row['firstAt']);
            $last = $this->toDate($row['lastAt']);
            $count = (int) $row['cnt'];
            $spanDays = $first->diff($last)->days + 1;
            $info[$row['title'].'|'.($row['venueId'] ?? '')] = [
                'count' => $count,
                'last' => $last,
                // "Daily" when the occurrences cover (almost) every day of the run.
                'daily' => $count >= $spanDays * 0.8,
            ];
        }

        return $info;
    }

    private function toDate(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        return new \DateTimeImmutable((string) $value, new \DateTimeZone('Europe/Berlin'));
    }

    private function seriesFloor(EventFilter $filter): \DateTimeImmutable
    {
        return $filter->from ?? new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'));
    }

    /**
     * Collapse recurring series (same title + venue) to a single card: keep only
     * the soonest still-upcoming occurrence. Skipped for "Meine Events" (there the
     * user picked specific occurrences). {@see seriesLabelInfo} supplies the label.
     */
    private function applySeriesCollapse(QueryBuilder $qb, EventFilter $filter): void
    {
        if ($filter->onlySaved) {
            return;
        }

        // Keep only the soonest still-upcoming occurrence of each series
        // (same title + venue). Title/venue/course/city are series-invariant; a
        // category filter is applied on the outer row, so the representative is
        // still chosen among the visible occurrences.
        $qb->andWhere(
            'e.startsAt = ('
            .'SELECT MIN(e2.startsAt) FROM '.Event::class.' e2 '
            .'WHERE e2.title = e.title '
            .'AND (IDENTITY(e2.venue) = IDENTITY(e.venue) OR (e2.venue IS NULL AND e.venue IS NULL)) '
            .'AND e2.status = :published '
            .'AND COALESCE(e2.endsAt, e2.startsAt) >= :seriesFloor'
            .')'
        )->setParameter('seriesFloor', $this->seriesFloor($filter));
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

    /**
     * Events chronologically before and after $current within the same filtered,
     * visible set — for the "davor/danach" sidebar on the detail page.
     *
     * @return array{before: Event[], after: Event[]}
     */
    public function findAround(EventFilter $filter, Event $current, int $limit = 8): array
    {
        $after = $this->visibleQueryBuilder($filter)
            ->andWhere('(e.startsAt > :start OR (e.startsAt = :start AND e.id > :id))')
            ->andWhere('e.id != :id')
            ->setParameter('start', $current->getStartsAt())
            ->setParameter('id', $current->getId())
            ->orderBy('e.startsAt', 'ASC')->addOrderBy('e.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        $before = $this->visibleQueryBuilder($filter)
            ->andWhere('(e.startsAt < :start OR (e.startsAt = :start AND e.id < :id))')
            ->andWhere('e.id != :id')
            ->setParameter('start', $current->getStartsAt())
            ->setParameter('id', $current->getId())
            ->orderBy('e.startsAt', 'DESC')->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        return ['before' => array_reverse($before), 'after' => $after];
    }

    /**
     * Visible, still-upcoming events for the subscribable .ics feed. Honours the
     * non-temporal filters (search/category/course/city) via the shared builder
     * but ignores the period/von/bis filter — a calendar subscription always
     * spans "from now into the future" (capped at one year + a hard row limit so
     * the feed stays bounded).
     *
     * @return Event[]
     */
    public function findForFeed(EventFilter $filter, int $limit = 2000): array
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $now = new \DateTimeImmutable('now', $tz);

        return $this->visibleQueryBuilder($filter)
            ->andWhere('COALESCE(e.endsAt, e.startsAt) >= :now')
            ->andWhere('e.startsAt < :until')
            ->setParameter('now', $now)
            ->setParameter('until', $now->modify('+1 year'))
            ->orderBy('e.startsAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Upcoming published events the categorizer hasn't looked at yet, ordered by
     * date (soonest first) — so a run works through the imminent events before
     * the far-future ones. The command decides per event whether it's a "fill"
     * (no real category) or an "opinion" (already categorized).
     *
     * @return Event[]
     */
    public function findUncheckedUpcoming(int $limit = 100000, int $shards = 1, int $shard = 0): array
    {
        $qb = $this->visibleQueryBuilder(new EventFilter())
            ->andWhere('COALESCE(e.endsAt, e.startsAt) >= :now')
            ->andWhere('e.id NOT IN (SELECT IDENTITY(aicd.event) FROM '.\App\Entity\AiCategoryDecision::class.' aicd)')
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))
            ->orderBy('e.startsAt', 'ASC')
            ->setMaxResults($limit);
        $this->applyShard($qb, $shards, $shard);

        return $qb->getQuery()->getResult();
    }

    /** Split work across parallel runs by event id (disjoint sets, no races). */
    private function applyShard(QueryBuilder $qb, int $shards, int $shard): void
    {
        if ($shards > 1) {
            $qb->andWhere('MOD(e.id, :shards) = :shard')
                ->setParameter('shards', $shards)
                ->setParameter('shard', $shard);
        }
    }

    /**
     * Upcoming published events that still need an AI teaser: have an original
     * description but no summary yet. Date-ordered (soonest first).
     *
     * @return Event[]
     */
    public function findWithoutSummary(int $limit = 100000, int $shards = 1, int $shard = 0): array
    {
        $qb = $this->visibleQueryBuilder(new EventFilter())
            ->andWhere('COALESCE(e.endsAt, e.startsAt) >= :now')
            ->andWhere('e.summary IS NULL')
            ->andWhere("e.description IS NOT NULL AND e.description != ''")
            // Never summarize foreign aggregator text (legal safeguard).
            ->andWhere('s.factsOnly = false')
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))
            ->orderBy('e.startsAt', 'ASC')
            ->setMaxResults($limit);
        $this->applyShard($qb, $shards, $shard);

        return $qb->getQuery()->getResult();
    }

    /** Events that already have an AI teaser (for admin progress). */
    public function countWithSummary(): int
    {
        return (int) $this->visibleQueryBuilder(new EventFilter())
            ->select('COUNT(e.id)')
            ->andWhere('COALESCE(e.endsAt, e.startsAt) >= :now')
            ->andWhere('e.summary IS NOT NULL')
            ->andWhere('s.factsOnly = false')
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))
            ->getQuery()->getSingleScalarResult();
    }

    /** Events eligible for a teaser (own source, upcoming, has description). */
    public function countSummaryEligible(): int
    {
        return (int) $this->visibleQueryBuilder(new EventFilter())
            ->select('COUNT(e.id)')
            ->andWhere('COALESCE(e.endsAt, e.startsAt) >= :now')
            ->andWhere("e.description IS NOT NULL AND e.description != ''")
            ->andWhere('s.factsOnly = false')
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))
            ->getQuery()->getSingleScalarResult();
    }

    /** Upcoming published events the categorizer hasn't looked at yet (no decision). */
    public function countWithoutCategoryDecision(): int
    {
        return (int) $this->visibleQueryBuilder(new EventFilter())
            ->select('COUNT(e.id)')
            ->andWhere('COALESCE(e.endsAt, e.startsAt) >= :now')
            ->andWhere('e.id NOT IN (SELECT IDENTITY(aicd.event) FROM '.\App\Entity\AiCategoryDecision::class.' aicd)')
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))
            ->getQuery()
            ->getSingleScalarResult();
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

    /**
     * Distinct cities present on visible events, for the location filter.
     * "Kreis Gütersloh" is the kreis-wide bucket, not a town — it's excluded
     * here and represented by the "all" option (which the UI labels accordingly).
     */
    public function findUsedCities(): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('DISTINCT v.city AS city')
            ->join('e.venue', 'v')
            ->andWhere('e.status = :published')
            ->andWhere('v.city != :kreis')
            ->setParameter('published', EventStatus::Published)
            ->setParameter('kreis', 'Kreis Gütersloh')
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

    /**
     * Events belonging to a source, newest-relevant first, with venue, category
     * and the dedup target (+ its source) eager-loaded — for the admin source
     * detail page. Includes every status so duplicates/hidden are visible too.
     *
     * @return Event[]
     */
    public function findBySource(Source $source, int $limit = 300): array
    {
        // e.categories is to-many → lazy-loaded for display, not fetch-joined
        // (a join + addSelect would multiply rows under the limit).
        return $this->createQueryBuilder('e')
            ->leftJoin('e.venue', 'v')->addSelect('v')
            ->leftJoin('e.duplicateOf', 'd')->addSelect('d')
            ->leftJoin('d.source', 'ds')->addSelect('ds')
            ->andWhere('e.source = :source')
            ->setParameter('source', $source)
            ->orderBy('e.startsAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Event counts for a source, keyed by {@see EventStatus} value, plus a
     * 'total' and an 'upcoming' (visible, not yet ended) bucket.
     *
     * @return array<string, int>
     */
    public function statusCountsForSource(Source $source): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('e.status AS status, COUNT(e.id) AS cnt')
            ->andWhere('e.source = :source')
            ->setParameter('source', $source)
            ->groupBy('e.status')
            ->getQuery()
            ->getResult();

        $counts = ['total' => 0];
        foreach ($rows as $row) {
            $value = $row['status'] instanceof EventStatus ? $row['status']->value : (string) $row['status'];
            $counts[$value] = (int) $row['cnt'];
            $counts['total'] += (int) $row['cnt'];
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'));
        $counts['upcoming'] = (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.source = :source')
            ->andWhere('e.status = :published')
            ->andWhere('COALESCE(e.endsAt, e.startsAt) >= :now')
            ->setParameter('source', $source)
            ->setParameter('published', EventStatus::Published)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();

        return $counts;
    }

    /**
     * How many events from *other* sources were demoted as duplicates of an
     * event from this source — i.e. cases where this source "won" the dedup.
     */
    public function countWonDuplicates(Source $source): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->join('e.duplicateOf', 'd')
            ->andWhere('d.source = :source')
            ->andWhere('e.source != :source')
            ->setParameter('source', $source)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function visibleQueryBuilder(EventFilter $filter): QueryBuilder
    {
        // Categories are a to-many relation, so they are NOT fetch-joined here
        // (that would multiply rows and break LIMIT/COUNT); they lazy-load for
        // display, and the category filter runs as a subquery on event ids.
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.venue', 'v')->addSelect('v')
            ->leftJoin('e.source', 's')->addSelect('s')
            ->andWhere('e.status = :published')
            ->setParameter('published', EventStatus::Published);

        if ($filter->q !== null) {
            // Match title, description, venue name, venue city AND the source
            // name/origin — so "Bambi" finds every film from "Bambi & Löwenherz
            // Kino", "Filmwerk" the Filmwerk programme, etc.
            $qb->andWhere('LOWER(e.title) LIKE :q OR LOWER(e.description) LIKE :q OR LOWER(v.name) LIKE :q OR LOWER(v.city) LIKE :q OR LOWER(s.name) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower($filter->q).'%');
        }

        if ($filter->categorySlugs !== []) {
            $sub = $this->createQueryBuilder('ec')
                ->select('ec.id')
                ->join('ec.categories', 'cc')
                ->where('cc.slug IN (:categorySlugs)');
            $qb->andWhere($qb->expr()->in('e.id', $sub->getDQL()))
                ->setParameter('categorySlugs', $filter->categorySlugs);
        }

        if ($filter->city !== null) {
            $qb->andWhere('v.city = :city')->setParameter('city', $filter->city);
        }

        if ($filter->course === 'only') {
            $qb->andWhere('e.isCourse = true');
        } elseif ($filter->course === 'hide') {
            $qb->andWhere('e.isCourse = false');
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

        // Lower bound on OVERLAP, not on start: an event counts as inside the
        // window if it hasn't ended before it begins. So a multi-day event that
        // starts before the window (e.g. a Thu–Sun market) still shows up under
        // "Wochenende". "Von" overrides the floor; "Meine Events" drops it so
        // saved past events still show up.
        $floor = $filter->from ?? (!$filter->onlySaved ? $now : null);
        if ($floor !== null) {
            $qb->andWhere('COALESCE(e.endsAt, e.startsAt) >= :floor')->setParameter('floor', $floor);
        }

        if ($filter->to !== null) {
            $qb->andWhere('e.startsAt < :to')->setParameter('to', $filter->to->modify('+1 day'));
        }
    }
}
