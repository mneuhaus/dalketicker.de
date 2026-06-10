<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke HTML scraper for events at the Stadtbibliothek Rietberg.
 *
 * The town of Rietberg runs a TYPO3 site with the "cyteventcalendar"
 * (Cyberhouse) extension. There is no ICS/RSS/JSON-LD feed and no
 * destination.one/Tribe API. The location detail page for the library only
 * renders its address (no event list), and the library is omitted from the
 * location filter while it has no upcoming events.
 *
 * The reliable target is the central, server-rendered overview page, which
 * carries rich schema.org microdata. We walk all pages by following the
 * pagination anchors (each link carries its own &cHash that must be reused
 * verbatim — a constructed/missing cHash returns the default/empty result),
 * parse every list item, and keep only those whose location name equals
 * "Stadtbibliothek Rietberg".
 */
#[AutoconfigureTag('app.source_importer')]
final class StadtbibRietbergImporter implements SourceImporter
{
    private const BASE = 'https://www.rietberg.de';
    private const OVERVIEW = self::BASE.'/tourismus/freizeitangebote/veranstaltungen/uebersicht.html';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.de)';
    private const VENUE = 'Stadtbibliothek Rietberg';
    private const MAX_PAGES = 30;
    private const MAX_EVENTS = 200;

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'stadtbib_rietberg';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $tz = new \DateTimeZone('Europe/Berlin');
        $city = $config['city'] ?? 'Rietberg';

        $pageUrl = self::OVERVIEW;
        $visited = [];
        $seen = [];
        $count = 0;

        for ($p = 0; $p < self::MAX_PAGES; ++$p) {
            if ($pageUrl === null || isset($visited[$pageUrl])) {
                break;
            }
            $visited[$pageUrl] = true;

            // The first page is the primary fetch: a dead source must surface as
            // a failed run. Pagination is best-effort, so later pages tolerate a
            // broken fetch and simply stop.
            $html = $p === 0 ? $this->fetch($pageUrl) : $this->tryFetch($pageUrl);
            if ($html === null) {
                break;
            }
            $crawler = new Crawler($html, $pageUrl);

            foreach ($crawler->filter('li.cyt-eventcalendar-list-item') as $node) {
                if ($count >= self::MAX_EVENTS) {
                    return;
                }
                $item = new Crawler($node);

                $venue = $this->text($item, '.cyt-eventcalendar-list-item-location span[itemprop="name"]');
                if ($venue === null || $this->normalize($venue) !== $this->normalize(self::VENUE)) {
                    continue;
                }

                $titleLink = $item->filter('.cyt-eventcalendar-list-item-title a');
                if ($titleLink->count() === 0) {
                    continue;
                }
                $title = trim($titleLink->first()->text(''));
                $href = $titleLink->first()->attr('href');
                if ($title === '' || !is_string($href) || $href === '') {
                    continue;
                }
                $detailUrl = $this->absolute($href);

                $start = $this->startDate($item, $tz);
                if ($start === null) {
                    continue;
                }

                $dedup = $detailUrl.'|'.$start->format('Y-m-d H:i');
                if (isset($seen[$dedup])) {
                    continue;
                }
                $seen[$dedup] = true;
                ++$count;

                // 01:00 is the TZ artifact the calendar emits for all-day items.
                $allDay = $start->format('H:i') === '01:00';

                $category = $this->text($item, '.cyt-eventcalendar-list-item-category span');

                yield new ImportedEvent(
                    title: $title,
                    startsAt: $start,
                    allDay: $allDay,
                    venueName: self::VENUE,
                    city: $city,
                    locationText: $this->locationText($item),
                    categorySlug: $this->mapCategory($category, $title),
                    sourceUrl: $detailUrl,
                    imageUrl: $this->image($item),
                    externalId: 'stadtbib_rietberg:'.$this->idFromUrl($detailUrl).':'.$start->format('Y-m-d-Hi'),
                    raw: array_filter([
                        'category' => $category,
                        'venue' => $venue,
                        'href' => $href,
                    ], static fn ($v) => $v !== null && $v !== ''),
                );
            }

            $pageUrl = $this->nextPageUrl($crawler, array_keys($visited));
        }
    }

    /**
     * Find the next pagination anchor we have not visited yet. Each anchor
     * carries its own cHash, so we follow it verbatim rather than building URLs.
     *
     * @param list<string> $visited
     */
    private function nextPageUrl(Crawler $crawler, array $visited): ?string
    {
        $anchors = $crawler->filter('.cyt-eventcalendar-pagination a[data-page]');
        $best = null;
        $bestPage = PHP_INT_MAX;
        $visitedSet = array_flip($visited);

        foreach ($anchors as $a) {
            $node = new Crawler($a);
            $href = $node->attr('href');
            $page = (int) ($node->attr('data-page') ?? 0);
            if (!is_string($href) || $href === '' || $page < 1) {
                continue;
            }
            $url = $this->absolute($href);
            if (isset($visitedSet[$url])) {
                continue;
            }
            if ($page < $bestPage) {
                $bestPage = $page;
                $best = $url;
            }
        }

        return $best;
    }

    private function startDate(Crawler $item, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $node = $item->filter('.cyt-eventcalendar-list-item-startdate');
        if ($node->count() === 0) {
            return null;
        }
        $content = $node->first()->attr('content');
        if (is_string($content) && trim($content) !== '') {
            // ISO like 2026-05-31T11:00
            $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', trim($content), $tz)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', trim($content), $tz);
            if ($date instanceof \DateTimeImmutable) {
                return $date;
            }
        }

        // Fallback: visible dd.mm.yyyy text.
        $text = trim($node->first()->text(''));
        if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $text, $m)) {
            return SafeDate::create((int) $m[3], (int) $m[2], (int) $m[1], 0, 0, $tz);
        }

        return null;
    }

    private function locationText(Crawler $item): ?string
    {
        $block = $item->filter('.cyt-eventcalendar-list-item-location');
        if ($block->count() === 0) {
            return self::VENUE;
        }
        $street = $this->text($block, 'span[itemprop="streetAddress"]');
        $zip = $this->text($block, 'span[itemprop="postalCode"]');
        $cityName = $this->text($block, 'span[itemprop="addressLocality"]');

        $parts = array_filter([
            self::VENUE,
            $street,
            trim(($zip ?? '').' '.($cityName ?? '')),
        ], static fn ($v) => $v !== null && trim((string) $v) !== '');

        return $parts !== [] ? implode(', ', $parts) : self::VENUE;
    }

    private function image(Crawler $item): ?string
    {
        $img = $item->filter('.cyt-eventcalendar-list-item-image img[itemprop="image"]');
        if ($img->count() > 0) {
            $src = $img->first()->attr('src');
            if (is_string($src) && trim($src) !== '') {
                return $this->absolute(trim($src));
            }
        }

        // Fallback: background-image url(...) on the image div.
        $div = $item->filter('.cyt-eventcalendar-list-item-image');
        if ($div->count() > 0) {
            $style = $div->first()->attr('style') ?? '';
            if (preg_match('/url\(([^)]+)\)/', $style, $m)) {
                $url = trim($m[1], " '\"");
                if ($url !== '') {
                    return $this->absolute($url);
                }
            }
        }

        return null;
    }

    private function mapCategory(?string $category, string $title): ?string
    {
        $haystack = mb_strtolower(($category ?? '').' '.$title);
        if (trim($haystack) === '') {
            return null;
        }

        $map = [
            'musik' => ['konzert', 'musik', 'chor', 'band', 'jazz'],
            'buehne' => ['theater', 'kabarett', 'comedy', 'lesung', 'autorenlesung', 'vorlesen', 'erzähl', 'bühne'],
            'kunst' => ['ausstellung', 'kunst', 'vernissage'],
            'familie' => ['kinder', 'familie', 'jugend', 'bilderbuch', 'kamishibai', 'basteln'],
            'genuss' => ['kulinarisch', 'wein', 'kochen', 'genuss', 'kaffee'],
            'markt' => ['markt', 'flohmarkt', 'basar', 'trödel'],
            'sport' => ['sport', 'lauf', 'turnier', 'fitness'],
            'bildung' => ['vortrag', 'seminar', 'workshop', 'kurs', 'führung', 'fuehrung', 'info', 'bildung', 'lesekreis', 'sprechstunde', 'beratung'],
        ];

        foreach ($map as $slug => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return $slug;
                }
            }
        }

        return null;
    }

    /** Fetch a URL or throw, so a dead source surfaces as a failed run. */
    private function fetch(string $url): string
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => 20,
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

    /** Tolerant variant for pagination: a broken next page must not kill the run. */
    private function tryFetch(string $url): ?string
    {
        try {
            return $this->fetch($url);
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function absolute(string $href): string
    {
        $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }
        if (str_starts_with($href, '/')) {
            return self::BASE.$href;
        }

        return self::BASE.'/'.ltrim($href, '/');
    }

    private function idFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return basename($path, '.html');
    }

    private function text(Crawler $scope, string $selector): ?string
    {
        $nodes = $scope->filter($selector);
        if ($nodes->count() === 0) {
            return null;
        }
        $text = trim(preg_replace('/\s+/', ' ', $nodes->first()->text('')) ?? '');

        return $text !== '' ? $text : null;
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($value))) ?? '';
    }
}
