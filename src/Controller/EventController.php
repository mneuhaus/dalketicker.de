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
            'monthDate' => $first,
            'prev' => $prev,
            'next' => $next,
            'today' => $now->setTime(0, 0),
            'filter' => $filter,
            'view' => 'month',
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
     *
     * @param Event[] $events
     * @return array<int, array{date: \DateTimeImmutable, events: Event[]}>
     */
    private function groupByDay(array $events): array
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $groups = [];
        foreach ($events as $event) {
            $day = $event->getStartsAt()->setTimezone($tz)->format('Y-m-d');
            $groups[$day]['date'] ??= new \DateTimeImmutable($day, $tz);
            $groups[$day]['events'][] = $event;
        }

        return array_values($groups);
    }

    /**
     * Build a Monday-first calendar grid for the month containing $first.
     *
     * @param Event[] $events
     * @return array<int, array<int, array{date: \DateTimeImmutable, inMonth: bool, events: Event[]}>>
     */
    private function buildMonthGrid(\DateTimeImmutable $first, array $events): array
    {
        $tz = $first->getTimezone();
        // Bucket events by each day they cover (multi-day events span cells).
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
        // Back up to the Monday on or before the 1st (N: 1=Mon .. 7=Sun).
        $gridStart = $first->modify('-'.((int) $first->format('N') - 1).' days');

        $weeks = [];
        $cursor = $gridStart;
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
            // Stop after we've passed the month and completed a week.
            if ((int) $cursor->format('n') !== $monthNum && $cursor > $first->modify('last day of this month')) {
                break;
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
