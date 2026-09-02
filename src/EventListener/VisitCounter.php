<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Repository\RegionRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Privacy-friendly, cookieless visit counting.
 *
 * Runs after the response is sent (kernel.terminate) so it never slows a
 * request. We only ever persist *aggregate* numbers:
 *   - daily_stat:  page views + a rough visitor count per day
 *   - page_stat:   page views per route per day (for "top pages")
 *   - visitor_day: a per-day, salted SHA-256 token (NOT the IP) used solely to
 *                  de-duplicate visitors within the day; old tokens are purged
 *                  so only the counts survive.
 *
 * No cookies, no client-side script, no personal data stored → needs no
 * consent banner (in line with the site's no-tracking posture).
 */
#[AsEventListener(event: KernelEvents::TERMINATE)]
final class VisitCounter
{
    /** Bot/crawler/monitoring user agents we don't count as humans. */
    private const BOT = '/bot|crawl|spider|slurp|bing|google|yandex|baidu|duckduck|facebookexternalhit|embedly|preview|monitor|uptime|pingdom|curl|wget|python-requests|headless|lighthouse|semrush|ahrefs/i';

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly RegionRepository $regions,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $response = $event->getResponse();

        if ($request->getMethod() !== 'GET' || $response->getStatusCode() !== 200) {
            return;
        }
        if (!str_contains((string) $response->headers->get('Content-Type', ''), 'text/html')) {
            return;
        }

        $route = (string) $request->attributes->get('_route', '');
        // Skip internal (_wdt/_profiler/_error), admin and auth routes. Non-HTML
        // responses (image proxy, feeds, manifest) were already filtered above.
        if ($route === '' || $route[0] === '_' || str_starts_with($route, 'admin_') || str_starts_with($route, 'app_')) {
            return;
        }

        $ua = (string) $request->headers->get('User-Agent', '');
        if ($ua === '' || preg_match(self::BOT, $ua)) {
            return;
        }

        $day = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d');
        // Resolve the region from the request host: at terminate time the
        // request stack is already empty, so RegionContext would only work by
        // the side effect of an earlier call having memoized the region.
        $region = $this->regions->findByHost($request->getHost()) ?? $this->regions->findDefault();
        $regionId = $region->getId();
        if ($regionId === null) {
            return;
        }

        try {
            $this->db->executeStatement(
                'INSERT INTO daily_stat (region_id, day, views, visitors) VALUES (:region, :d, 1, 0) ON CONFLICT (region_id, day) DO UPDATE SET views = daily_stat.views + 1',
                ['region' => $regionId, 'd' => $day],
            );
            $this->db->executeStatement(
                'INSERT INTO page_stat (region_id, day, route_key, views) VALUES (:region, :d, :r, 1) ON CONFLICT (region_id, day, route_key) DO UPDATE SET views = page_stat.views + 1',
                ['region' => $regionId, 'd' => $day, 'r' => mb_substr($route, 0, 64)],
            );

            // Per-event popularity: count views of individual detail pages too.
            if ($route === 'event_show') {
                $eventId = (int) $request->attributes->get('id', 0);
                if ($eventId > 0) {
                    $this->db->executeStatement(
                        'INSERT INTO event_stat (day, event_id, views) VALUES (:d, :e, 1) ON CONFLICT (day, event_id) DO UPDATE SET views = event_stat.views + 1',
                        ['d' => $day, 'e' => $eventId],
                    );
                }
            }

            // Rough unique visitors: a salted, day-scoped hash (never the IP).
            $token = hash('sha256', $this->secret.'|'.$regionId.'|'.$day.'|'.$request->getClientIp().'|'.$ua);
            $isNew = $this->db->executeStatement(
                'INSERT INTO visitor_day (region_id, day, token) VALUES (:region, :d, :t) ON CONFLICT (region_id, day, token) DO NOTHING',
                ['region' => $regionId, 'd' => $day, 't' => $token],
            );
            if ($isNew === 1) {
                $this->db->executeStatement('UPDATE daily_stat SET visitors = visitors + 1 WHERE region_id = :region AND day = :d', ['region' => $regionId, 'd' => $day]);
            }

            // Occasionally drop yesterday's tokens — we only keep the counts.
            if (random_int(1, 50) === 1) {
                $this->db->executeStatement('DELETE FROM visitor_day WHERE day < :d', ['d' => $day]);
            }
        } catch (\Throwable) {
            // Counting must never break a page.
        }
    }
}
