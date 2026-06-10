<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Importer for marktcom.de (https://www.marktcom.de/), a directory of markets
 * and flea markets ("Märkte & Flohmärkte"). The configured start URL is already
 * pre-filtered to Nordrhein-Westfalen / Landkreis Gütersloh via the Ransack
 * query parameters (q[event_bundesland_matches], q[event_landkreis_matches]).
 *
 * The listing is rendered fully server-side as a Bootstrap markup tree:
 *
 *   <h2>Märkte am Sonntag den 31.05.2026</h2>
 *   <ul class="marktliste">
 *     <li class="p-2">
 *       <div class="eventname"><a href="/veranstaltung/...">Title</a></div>
 *       <div class="d-md-none">33378 Rheda-Wiedenbrück</div>   (mobile place)
 *       <p class="cat">subtitle</p>
 *       <p class="description">teaser ... [mehr]</p>
 *       <div class="text-right">33378 Rheda-Wiedenbrück</div>  (desktop place)
 *       <div class="badge badge-primary"><i></i> 31.05.2026</div>
 *       <div class="badge">Antik-Trödelmarkt</div>            (market type)
 *     </li>
 *     <li class="p-2"> ...advertisement (no .eventname)... </li>
 *   </ul>
 *
 * We page through ?page=N following the rel="next" link. Each market is a
 * single all-day occurrence (the site exposes no clock times). The per-item
 * date badge is read directly from the <li> so advertisement rows and grouping
 * are irrelevant. Everything is a market, so categorySlug is always "markt".
 *
 * The page's og:image is only the generic marktcom logo, never a per-event
 * image, so imageUrl stays null.
 *
 * Optional source config:
 *   - city:     default city when an item carries no place (default: Kreis Gütersloh)
 *   - maxPages: hard cap on followed pages (default: 20)
 */
#[AutoconfigureTag('app.source_importer')]
final class MarktcomImporter implements SourceImporter
{
    private const BASE_URL = 'https://www.marktcom.de';
    private const DEFAULT_URL = 'https://www.marktcom.de/termine/verzeichnis?q[event_bundesland_matches]=Nordrhein-Westfalen&q[event_landkreis_matches]=Gütersloh';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)';
    private const DEFAULT_MAX_PAGES = 20;

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'marktcom';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $defaultCity = (string) ($config['city'] ?? 'Kreis Gütersloh');
        $maxPages = (int) ($config['maxPages'] ?? self::DEFAULT_MAX_PAGES);

        $tz = new \DateTimeZone('Europe/Berlin');
        $url = $source->getUrl() ?: self::DEFAULT_URL;
        $seen = [];
        $pages = 0;

        while ($url !== null && $pages < $maxPages) {
            ++$pages;
            // The first page is the primary fetch: if it fails the whole run is
            // worthless, so let it throw. Pagination follow-ups stay tolerant.
            $html = $pages === 1 ? $this->fetch($url) : $this->tryFetch($url);
            if ($html === null) {
                break;
            }

            $crawler = new Crawler($html, $url);

            foreach ($crawler->filter('ul.marktliste li')->each(static fn (Crawler $n): Crawler => $n) as $li) {
                $event = $this->mapItem($li, $defaultCity, $tz, $seen);
                if ($event !== null) {
                    yield $event;
                }
            }

            $url = $this->nextUrl($crawler);
        }
    }

    /** Fetch a URL or throw, so a dead source surfaces as a failed run. */
    private function fetch(string $url): string
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => 30,
            ]);
            $status = $response->getStatusCode();
            $content = $status < 400 ? $response->getContent() : null;
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Fetching %s failed: %s', $url, $e->getMessage()), 0, $e);
        }
        if ($content === null) {
            throw new \RuntimeException(sprintf('Fetching %s failed: HTTP %d', $url, $status));
        }

        return $content;
    }

    /** Tolerant variant for pagination follow-ups: one broken page must not kill the run. */
    private function tryFetch(string $url): ?string
    {
        try {
            return $this->fetch($url);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * @param array<string, true> $seen passed by reference to dedupe across pages
     */
    private function mapItem(Crawler $li, string $defaultCity, \DateTimeZone $tz, array &$seen): ?ImportedEvent
    {
        $link = $li->filter('.eventname a');
        if ($link->count() === 0) {
            // Advertisement / spacer row.
            return null;
        }

        $title = $this->clean($link->first()->text(''));
        if ($title === '') {
            return null;
        }

        $href = trim((string) $link->first()->attr('href'));
        $sourceUrl = $this->absoluteUrl($href);

        $start = $this->extractDate($li, $tz);
        if ($start === null) {
            return null;
        }

        $externalId = $this->buildExternalId($href, $start);
        $dedup = $externalId ?? ($title.'|'.$start->format('Y-m-d'));
        if (isset($seen[$dedup])) {
            return null;
        }
        $seen[$dedup] = true;

        $locationText = $this->extractPlace($li);
        $city = $this->extractCity($locationText, $defaultCity);
        $marketType = $this->extractMarketType($li);
        $description = $this->extractDescription($li);

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: null,
            allDay: true,
            description: $description,
            venueName: null,
            city: $city,
            locationText: $locationText,
            categorySlug: 'markt',
            sourceUrl: $sourceUrl,
            imageUrl: null,
            price: null,
            organizer: null,
            externalId: $externalId,
            raw: [
                'href' => $href,
                'marketType' => $marketType,
                'place' => $locationText,
            ],
        );
    }

    /** Reads the DD.MM.YYYY date from the calendar badge of an item. */
    private function extractDate(Crawler $li, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $text = '';
        $primary = $li->filter('.badge-primary');
        if ($primary->count() > 0) {
            $text = $primary->first()->text('');
        } else {
            $text = $li->text('');
        }

        if (!preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $text, $m)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(
            'd.m.Y H:i:s',
            sprintf('%02d.%02d.%04d 00:00:00', (int) $m[1], (int) $m[2], (int) $m[3]),
            $tz,
        );

        return $date instanceof \DateTimeImmutable ? $date : null;
    }

    private function extractPlace(Crawler $li): ?string
    {
        foreach (['.text-right', '.d-md-none'] as $selector) {
            $node = $li->filter($selector);
            if ($node->count() > 0) {
                $value = $this->clean($node->first()->text(''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /** Turns "33378 Rheda-Wiedenbrück" into "Rheda-Wiedenbrück". */
    private function extractCity(?string $place, string $default): string
    {
        if ($place === null) {
            return $default;
        }
        $city = trim(preg_replace('/^\s*\d{4,5}\s*/', '', $place) ?? $place);

        return $city !== '' ? $city : $default;
    }

    /** The colored "market type" badge, e.g. "Antik-Trödelmarkt". */
    private function extractMarketType(Crawler $li): ?string
    {
        foreach ($li->filter('.badge')->each(static fn (Crawler $n): Crawler => $n) as $badge) {
            if (str_contains((string) $badge->attr('class'), 'badge-primary')) {
                continue; // that's the date badge
            }
            $value = $this->clean($badge->text(''));
            if ($value !== '' && !preg_match('/\d{1,2}\.\d{1,2}\.\d{4}/', $value)) {
                return $value;
            }
        }

        return null;
    }

    private function extractDescription(Crawler $li): ?string
    {
        $node = $li->filter('.description');
        $text = $node->count() > 0 ? $this->clean($node->first()->text('')) : '';
        if ($text === '') {
            $cat = $li->filter('.cat');
            $text = $cat->count() > 0 ? $this->clean($cat->first()->text('')) : '';
        }
        $text = trim(preg_replace('/\s*\[mehr\]\s*$/u', '', $text) ?? $text);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > 400) {
            $text = mb_substr($text, 0, 397).'...';
        }

        return $text;
    }

    private function nextUrl(Crawler $crawler): ?string
    {
        $next = $crawler->filter('a[rel="next"]');
        if ($next->count() === 0) {
            return null;
        }
        $href = trim((string) $next->first()->attr('href'));

        return $href !== '' ? $this->absoluteUrl($href) : null;
    }

    /** Builds a stable id from the detail slug plus a term_id query if present. */
    private function buildExternalId(string $href, \DateTimeImmutable $start): ?string
    {
        if ($href === '') {
            return null;
        }
        $path = parse_url($href, PHP_URL_PATH);
        $slug = \is_string($path) ? trim($path, '/') : trim($href, '/');
        $slug = str_replace('veranstaltung/', '', $slug);

        $query = parse_url($href, PHP_URL_QUERY);
        $termId = '';
        if (\is_string($query)) {
            parse_str($query, $params);
            $termId = isset($params['term_id']) ? (string) $params['term_id'] : '';
        }

        $suffix = $termId !== '' ? $termId : $start->format('Y-m-d');

        return 'marktcom:'.$slug.':'.$suffix;
    }

    private function absoluteUrl(string $href): ?string
    {
        if ($href === '') {
            return null;
        }
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        return self::BASE_URL.'/'.ltrim($href, '/');
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
