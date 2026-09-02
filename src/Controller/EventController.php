<?php

declare(strict_types=1);

namespace App\Controller;

use App\Calendar\IcsFeedBuilder;
use App\Entity\Event;
use App\Entity\Region;
use App\Enum\BookingStatus;
use App\Repository\CategoryRepository;
use App\Repository\EventRepository;
use App\Search\EventFilter;
use App\Service\RegionContext;
use App\Twig\RegionExtension;
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
        private readonly IcsFeedBuilder $icsFeed,
        private readonly RegionContext $regions,
        private readonly RegionExtension $regionExtension,
    ) {
    }

    /** Primary view: upcoming events grouped by day. */
    #[Route('/', name: 'event_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = EventFilter::fromRequest($request);
        $region = $this->regions->current();
        $total = $this->events->countUpcoming($filter, $region);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        // Clamp to what exists: an absurd ?seite= would overflow the offset
        // into a float (TypeError → 500); it simply lands on the last page.
        $page = min(max(1, $request->query->getInt('seite', 1)), $pages);

        $events = $this->events->findUpcoming($filter, self::PER_PAGE, ($page - 1) * self::PER_PAGE, $region);

        // Recurring series (same title+venue) are collapsed to one card by the
        // repository; map the label data ("täglich · bis …") onto those cards.
        $seriesInfo = $this->events->seriesLabelInfo($filter, $region);
        $seriesByEvent = [];
        foreach ($events as $event) {
            $key = $event->getTitle().'|'.($event->getVenue()?->getId() ?? '');
            if (isset($seriesInfo[$key])) {
                $seriesByEvent[$event->getId()] = $seriesInfo[$key];
            }
        }

        $today = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Berlin'));
        // Group multi-day events that span into the window under the window
        // start (e.g. a Thu–Sun market shows on Saturday for the "Wochenende"
        // filter). The window start is "von" when given — also when it lies in
        // the past, otherwise a custom August range would collapse into one
        // "Heute" group — and today for the default upcoming list.
        $floor = $filter->onlySaved ? null : ($filter->from ?? $today);

        return $this->render('event/index.html.twig', [
            'groups' => $this->groupByDay($events, $floor),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'filter' => $filter,
            'view' => 'list',
            'seriesByEvent' => $seriesByEvent,
        ] + $this->filterData($region));
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
        $region = $this->regions->current();
        $events = $this->events->findForMonth($year, $month, $filter, $region);

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
        ] + $this->filterData($region));
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
        $region = $this->regions->current();
        $events = $this->events->findInRange($monday, $nextMonday, $filter, $region);
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
        ] + $this->filterData($region));
    }

    /** "Überrasch mich": a playful shuffle through today's events (mobile-first). */
    #[Route('/ueberrasch-mich', name: 'event_surprise', methods: ['GET'])]
    public function surprise(): Response
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $today = $this->clock->now()->setTimezone($tz)->setTime(0, 0);
        $region = $this->regions->current();

        return $this->render('event/surprise.html.twig', [
            'events' => $this->events->findInRange($today, $today->modify('+1 day'), new EventFilter(), $region),
            'view' => 'surprise',
        ]);
    }

    /**
     * Subscribable iCalendar feed of the (optionally filtered) programme. Honours
     * the same search/category/course/city filters as the list view; the time
     * range is intentionally ignored (a subscription always means "from now on").
     */
    #[Route('/kalender.ics', name: 'event_feed', methods: ['GET'])]
    public function feed(Request $request): Response
    {
        $requested = EventFilter::fromRequest($request);
        $region = $this->regions->current();
        // Drop the temporal + "Meine Events" parts; keep the meaningful filters.
        $filter = new EventFilter(
            q: $requested->q,
            categorySlugs: $requested->categorySlugs,
            city: $requested->city,
            course: $requested->course,
        );

        $events = $this->events->findForFeed($filter, region: $region);
        $ics = $this->icsFeed->build($events, $this->feedName($filter, $region));

        $response = new Response($ics, Response::HTTP_OK, ['Content-Type' => 'text/calendar; charset=utf-8']);
        $response->headers->set('Content-Disposition', 'inline; filename="'.$region->getSiteName().'.ics"');
        // Nothing user-specific in the feed; let clients and the proxy absorb
        // the frequent calendar-app polls (imports only run every three hours).
        $response->setPublic();
        $response->setMaxAge(3600);
        $response->setSharedMaxAge(3600);

        return $response;
    }

    /** Human-readable calendar name reflecting the active filters. */
    private function feedName(EventFilter $filter, Region $region): string
    {
        $bits = [];
        if ($filter->categorySlugs !== []) {
            $names = [];
            foreach ($this->categories->findAllOrdered() as $category) {
                if (in_array($category->getSlug(), $filter->categorySlugs, true)) {
                    $names[] = $category->getName();
                }
            }
            if ($names !== []) {
                $bits[] = implode(', ', $names);
            }
        }
        if ($filter->course === 'only') {
            $bits[] = 'Kurse';
        } elseif ($filter->course === 'hide') {
            $bits[] = 'ohne Kurse';
        }
        if ($filter->city !== null) {
            $bits[] = $filter->city;
        }
        if ($filter->q !== null) {
            $bits[] = '„'.$filter->q.'“';
        }

        return $region->getSiteName().' – '.($bits !== [] ? implode(' · ', $bits) : $region->getAreaName());
    }

    #[Route('/event/{id}-{slug}', name: 'event_show', requirements: ['id' => '\d+', 'slug' => '[^/]*'], methods: ['GET'])]
    public function show(Request $request, int $id, string $slug): Response
    {
        $region = $this->regions->current();
        $event = $this->events->findVisible($id, $region);
        if ($event === null) {
            throw $this->createNotFoundException('Event nicht gefunden.');
        }

        $filter = EventFilter::fromRequest($request);

        // Canonicalize the slug in the URL (keep the filter query).
        if ($slug !== $event->getSlug()) {
            return $this->redirectToRoute('event_show', ['id' => $id, 'slug' => $event->getSlug()] + $filter->toQueryParams(), Response::HTTP_MOVED_PERMANENTLY);
        }

        // Only the immediate neighbours are shown (one before, one after).
        $around = $this->events->findAround($filter, $event, 1, $region);

        return $this->render('event/show.html.twig', [
            'event' => $event,
            'before' => $around['before'],
            'after' => $around['after'],
            'filter' => $filter,
            'sourceHome' => $this->homepageOf($event->getSource()->getUrl()),
            'jsonLd' => $this->eventJsonLd($event),
        ]);
    }

    /**
     * schema.org/Event JSON-LD for rich results in Google. Only facts for
     * facts-only (aggregator) sources; description/image only where we may show
     * the creative content (own/licensed sources).
     */
    private function eventJsonLd(Event $event): string
    {
        // Absolute URLs are built on the region's canonical host, not the
        // request host: alias hosts serve the same page, and the structured
        // data must agree with <link rel="canonical">.
        $base = $event->getRegion()->getBaseUrl();
        $url = $base.$this->generateUrl('event_show', ['id' => $event->getId(), 'slug' => $event->getSlug()]);
        $booking = $event->getBookingStatus();
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $event->getTitle(),
            // All-day events get a date-only startDate per schema.org; timed
            // ones carry the local offset so Google doesn't guess a timezone.
            'startDate' => $event->isAllDay()
                ? $event->getStartsAt()->format('Y-m-d')
                : $event->getStartsAt()->format('Y-m-d\TH:i:sP'),
            // What the page badges as "Fällt aus" must not be "scheduled" for Google.
            'eventStatus' => $booking === BookingStatus::Cancelled
                ? 'https://schema.org/EventCancelled'
                : 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'url' => $url,
        ];
        if ($event->getEndsAt() !== null) {
            $data['endDate'] = $event->isAllDay()
                ? $event->getEndsAt()->format('Y-m-d')
                : $event->getEndsAt()->format('Y-m-d\TH:i:sP');
        }
        $venue = $event->getVenue();
        if ($venue !== null) {
            $place = ['@type' => 'Place', 'name' => $venue->getName()];
            if ($venue->getFullAddress() !== '') {
                $place['address'] = $venue->getFullAddress();
            }
            $data['location'] = $place;
        } elseif ($event->getLocationText() !== null) {
            $data['location'] = ['@type' => 'Place', 'name' => $event->getLocationText()];
        }
        $home = $this->homepageOf($event->getSource()->getUrl());
        if ($event->getOrganizer() !== null) {
            $organizer = ['@type' => 'Organization', 'name' => $event->getOrganizer()];
            if ($home !== null) {
                $organizer['url'] = $home; // recommended "url" inside organizer
            }
            $data['organizer'] = $organizer;
        }

        // Offer (recommended): the original event/ticket page + price when we can
        // read it from the free-text price ("Eintritt frei" → 0, "12 €" → 12 EUR).
        $offerUrl = preg_match('#^https?://#', (string) $event->getSourceUrl()) ? $event->getSourceUrl() : $url;
        $offer = [
            '@type' => 'Offer',
            'url' => $offerUrl,
            'availability' => $booking?->isSoldOut() ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock',
        ];
        $price = $event->getPrice();
        if ($price !== null && preg_match('/frei|kostenlos|gratis|umsonst/i', $price)) {
            $offer['price'] = '0';
            $offer['priceCurrency'] = 'EUR';
        } elseif ($price !== null && preg_match('/(\d+(?:[.,]\d{1,2})?)/', $price, $m)) {
            $offer['price'] = str_replace(',', '.', $m[1]);
            $offer['priceCurrency'] = 'EUR';
        }
        $data['offers'] = $offer;

        // Creative parts only where allowed; otherwise our own fallbacks (logo
        // image + a factual one-liner) so the recommended fields aren't empty.
        if (!$event->isFactsOnly() && $event->getImageUrl() !== null) {
            $data['image'] = $base.$this->generateUrl('image_proxy', ['id' => $event->getId()]);
        } else {
            $data['image'] = $this->regionExtension->shareImage();
        }
        $desc = !$event->isFactsOnly()
            ? ($event->getSummary() ?: ($event->getDescription() !== null ? trim(strip_tags($event->getDescription())) : null))
            : null;
        if ($desc === null || $desc === '') {
            // Factual fallback (no foreign text): title + place.
            $desc = $event->getTitle().($event->getDisplayLocation() !== null ? ' – '.$event->getDisplayLocation() : '');
        }
        $data['description'] = mb_substr($desc, 0, 500);

        // Escape "<", ">" and "&" (< …) on top of the slashes: an upstream
        // title containing "<!--<script" would otherwise flip the HTML tokenizer
        // into its double-escaped script state, where the real "</script>" no
        // longer closes the JSON-LD block and the rest of the page vanishes.
        return json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG | \JSON_HEX_AMP) ?: '{}';
    }

    /** Reduce a source URL to its bare homepage (scheme + host) for linking. */
    private function homepageOf(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $parts = parse_url($url);
        if (empty($parts['host'])) {
            return null;
        }

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'];
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
        // Visible grid window: at most six leading days back to Monday, at most
        // six trailing days to complete the last week.
        $gridStart = $first->modify('-6 days');
        $gridEnd = $first->modify('last day of this month')->modify('+6 days');
        $byDay = [];
        foreach ($events as $event) {
            $start = $event->getStartsAt()->setTimezone($tz)->setTime(0, 0);
            $endsAt = $event->getEndsAt()?->setTimezone($tz);
            $end = ($endsAt ?? $start)->setTime(0, 0);
            // For timed events an end at exactly midnight means "until the end
            // of the previous day" — don't paint the following day. All-day
            // events store an inclusive end (importers already shifted the
            // exclusive DTEND back), so no further adjustment there.
            if (!$event->isAllDay() && $endsAt !== null && $endsAt->format('His') === '000000' && $end > $start) {
                $end = $end->modify('-1 day');
            }
            // Clamp to the grid window, so a long-runner that started months
            // earlier is still painted in the viewed month (the guard below is
            // only a safety net and must not swallow it).
            if ($start < $gridStart) {
                $start = $gridStart;
            }
            if ($end > $gridEnd) {
                $end = $gridEnd;
            }
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

    /**
     * Shared sidebar filter data.
     *
     * @return array<string, mixed>
     */
    private function filterData(Region $region): array
    {
        return [
            'allCategories' => $this->categories->findAllOrdered(),
            'allCities' => $this->events->findUsedCities($region),
        ];
    }
}
