<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use App\Repository\CategoryRepository;
use App\Repository\EventRepository;
use App\Search\EventFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EventController extends AbstractController
{
    private const PER_PAGE = 40;

    public function __construct(
        private readonly EventRepository $events,
        private readonly CategoryRepository $categories,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Primary view: upcoming events grouped by day. */
    #[Route('/', name: 'event_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = EventFilter::fromRequest($request);
        $page = max(1, $request->query->getInt('seite', 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $events = $this->events->findUpcoming($filter, self::PER_PAGE, $offset);
        $total = $this->events->countUpcoming($filter);

        return $this->render('event/index.html.twig', [
            'groups' => $this->groupByDay($events),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'filter' => $filter,
            'view' => 'list',
        ] + $this->filterData());
    }

    /** Month grid view. */
    #[Route('/kalender/{year}/{month}', name: 'event_month', requirements: ['year' => '\d{4}', 'month' => '\d{1,2}'], methods: ['GET'])]
    public function month(Request $request, ?int $year = null, ?int $month = null): Response
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $now = $this->clock->now()->setTimezone($tz);
        $year ??= (int) $now->format('Y');
        $month ??= (int) $now->format('n');
        if ($month < 1 || $month > 12) {
            return $this->redirectToRoute('event_month', ['year' => (int) $now->format('Y'), 'month' => (int) $now->format('n')]);
        }

        $filter = EventFilter::fromRequest($request);
        $events = $this->events->findForMonth($year, $month, $filter);

        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $tz);
        $prev = $first->modify('-1 month');
        $next = $first->modify('+1 month');

        return $this->render('event/month.html.twig', [
            'weeks' => $this->buildMonthGrid($first, $events),
            'groups' => $this->groupByDay($events, $first),
            'monthDate' => $first,
            'prev' => $prev,
            'next' => $next,
            'today' => $now->setTime(0, 0),
            'filter' => $filter,
            'view' => 'month',
        ] + $this->filterData());
    }

    /** Week view: a single ISO week, navigable week by week. */
    #[Route('/woche/{year}/{week}', name: 'event_week', requirements: ['year' => '\d{4}', 'week' => '\d{1,2}'], methods: ['GET'])]
    public function week(Request $request, ?int $year = null, ?int $week = null): Response
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $now = $this->clock->now()->setTimezone($tz);
        $year ??= (int) $now->format('o'); // ISO year
        $week ??= (int) $now->format('W'); // ISO week
        if ($week < 1 || $week > 53) {
            return $this->redirectToRoute('event_week', ['year' => (int) $now->format('o'), 'week' => (int) $now->format('W')]);
        }

        $monday = $now->setISODate($year, $week)->setTime(0, 0);
        $nextMonday = $monday->modify('+7 days');

        $filter = EventFilter::fromRequest($request);
        $events = $this->events->findInRange($monday, $nextMonday, $filter);
        $prev = $monday->modify('-7 days');
        $next = $nextMonday;

        return $this->render('event/week.html.twig', [
            'days' => $this->buildWeekDays($monday, $events),
            'monday' => $monday,
            'sunday' => $monday->modify('+6 days'),
            'prevWeek' => ['year' => (int) $prev->format('o'), 'week' => (int) $prev->format('W')],
            'nextWeek' => ['year' => (int) $next->format('o'), 'week' => (int) $next->format('W')],
            'today' => $now->setTime(0, 0),
            'filter' => $filter,
            'view' => 'week',
        ] + $this->filterData());
    }

    #[Route('/event/{id}-{slug}', name: 'event_show', requirements: ['id' => '\d+', 'slug' => '[^/]*'], methods: ['GET'])]
    public function show(int $id, string $slug): Response
    {
        $event = $this->events->findVisible($id);
        if ($event === null) {
            throw $this->createNotFoundException('Event nicht gefunden.');
        }

        // Canonicalize the slug in the URL.
        if ($slug !== $event->getSlug()) {
            return $this->redirectToRoute('event_show', ['id' => $id, 'slug' => $event->getSlug()], Response::HTTP_MOVED_PERMANENTLY);
        }

        return $this->render('event/show.html.twig', ['event' => $event]);
    }

    /**
     * Group a chronologically-ordered list of events into day buckets.
     * When $clampFrom is given, events that started earlier are bucketed under
     * that day (so a multi-day event running into the month shows on day 1).
     *
     * @param Event[] $events
     * @return array<int, array{date: \DateTimeImmutable, events: Event[]}>
     */
    private function groupByDay(array $events, ?\DateTimeImmutable $clampFrom = null): array
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $floor = $clampFrom?->setTimezone($tz)->setTime(0, 0);
        $groups = [];
        foreach ($events as $event) {
            $start = $event->getStartsAt()->setTimezone($tz)->setTime(0, 0);
            if ($floor !== null && $start < $floor) {
                $start = $floor;
            }
            $day = $start->format('Y-m-d');
            $groups[$day]['date'] ??= new \DateTimeImmutable($day, $tz);
            $groups[$day]['events'][] = $event;
        }
        ksort($groups);

        return array_values($groups);
    }

    /**
     * Build all seven days of the week starting at $monday, each with the
     * events that fall on it (empty days included for a full weekly overview).
     *
     * @param Event[] $events
     * @return array<int, array{date: \DateTimeImmutable, events: Event[]}>
     */
    private function buildWeekDays(\DateTimeImmutable $monday, array $events): array
    {
        $tz = $monday->getTimezone();
        $byDay = [];
        foreach ($events as $event) {
            $start = $event->getStartsAt()->setTimezone($tz)->setTime(0, 0);
            if ($start < $monday) {
                $start = $monday;
            }
            $byDay[$start->format('Y-m-d')][] = $event;
        }

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $monday->modify('+'.$i.' days');
            $days[] = ['date' => $date, 'events' => $byDay[$date->format('Y-m-d')] ?? []];
        }

        return $days;
    }

    /**
     * Build a Monday-first calendar grid for the month containing $first.
     * Multi-day events appear in every cell they cover.
     *
     * @param Event[] $events
     * @return array<int, array<int, array{date: \DateTimeImmutable, inMonth: bool, events: Event[]}>>
     */
    private function buildMonthGrid(\DateTimeImmutable $first, array $events): array
    {
        $tz = $first->getTimezone();
        $byDay = [];
        foreach ($events as $event) {
            $start = $event->getStartsAt()->setTimezone($tz)->setTime(0, 0);
            $end = ($event->getEndsAt() ?? $event->getStartsAt())->setTimezone($tz)->setTime(0, 0);
            $cursor = $start;
            $guard = 0;
            while ($cursor <= $end && $guard++ < 60) {
                $byDay[$cursor->format('Y-m-d')][] = $event;
                $cursor = $cursor->modify('+1 day');
            }
        }

        $monthNum = (int) $first->format('n');
        $cursor = $first->modify('-'.((int) $first->format('N') - 1).' days'); // back to Monday
        $lastDay = $first->modify('last day of this month');

        $weeks = [];
        for ($w = 0; $w < 6; $w++) {
            $week = [];
            for ($d = 0; $d < 7; $d++) {
                $key = $cursor->format('Y-m-d');
                $week[] = [
                    'date' => $cursor,
                    'inMonth' => (int) $cursor->format('n') === $monthNum,
                    'events' => $byDay[$key] ?? [],
                ];
                $cursor = $cursor->modify('+1 day');
            }
            $weeks[] = $week;
            if ($cursor > $lastDay) {
                break; // stop once we've passed the month end and finished the week
            }
        }

        return $weeks;
    }

    /** Shared sidebar filter data. */
    private function filterData(): array
    {
        return [
            'allCategories' => $this->categories->findAllOrdered(),
            'allCities' => $this->events->findUsedCities(),
        ];
    }
}
