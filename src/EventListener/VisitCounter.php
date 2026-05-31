<?php

declare(strict_types=1);

namespace App\EventListener;

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
        // Skip internal (_wdt/_profiler/_error), admin, auth and the image proxy.
        if ($route === '' || $route[0] === '_' || str_starts_with($route, 'admin_') || str_starts_with($route, 'app_') || $route === 'image_proxy') {
            return;
        }

        $ua = (string) $request->headers->get('User-Agent', '');
        if ($ua === '' || preg_match(self::BOT, $ua)) {
            return;
        }

        $day = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d');

        try {
            $this->db->executeStatement(
                'INSERT INTO daily_stat (day, views, visitors) VALUES (:d, 1, 0) ON CONFLICT (day) DO UPDATE SET views = daily_stat.views + 1',
                ['d' => $day],
            );
            $this->db->executeStatement(
                'INSERT INTO page_stat (day, route_key, views) VALUES (:d, :r, 1) ON CONFLICT (day, route_key) DO UPDATE SET views = page_stat.views + 1',
                ['d' => $day, 'r' => mb_substr($route, 0, 64)],
            );

            // Rough unique visitors: a salted, day-scoped hash (never the IP).
            $token = hash('sha256', $this->secret.'|'.$day.'|'.$request->getClientIp().'|'.$ua);
            $isNew = $this->db->executeStatement(
                'INSERT INTO visitor_day (day, token) VALUES (:d, :t) ON CONFLICT (day, token) DO NOTHING',
                ['d' => $day, 't' => $token],
            );
            if ($isNew === 1) {
                $this->db->executeStatement('UPDATE daily_stat SET visitors = visitors + 1 WHERE day = :d', ['d' => $day]);
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
