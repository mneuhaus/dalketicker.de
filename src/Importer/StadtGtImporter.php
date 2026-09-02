<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke importer for the official city of Gütersloh event calendar
 * (https://www.guetersloh.de/de/veranstaltungen/).
 *
 * The site renders server-side HTML (no JS needed) but exposes no global
 * ICS/RSS/JSON-LD feed. The list page supports server-side date filtering via
 * the `from`/`to` query parameters ("YYYY-MM-DD HH:MM:SS"); each list entry
 * links to a detail page whose query string already carries the exact
 * start/end datetime of that occurrence. We crawl the list, derive precise
 * start/end times from the link, then fetch each detail page for venue and a
 * short description.
 *
 * Optional source config:
 *   - from / to:   override the default date window (raw "Y-m-d H:i:s")
 *   - daysAhead:   size of the default window in days (default 60)
 *   - maxEvents:   cap on number of events to fetch detail pages for (safety)
 *   - city:        default city (defaults to "Gütersloh")
 */
#[AutoconfigureTag('app.source_importer')]
final class StadtGtImporter implements SourceImporter
{
    private const BASE = 'https://www.guetersloh.de';
    private const LIST_PATH = '/de/veranstaltungen/';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'stadt_gt';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $tz = new \DateTimeZone('Europe/Berlin');
        $city = $config['city'] ?? 'Gütersloh';
        $maxEvents = (int) ($config['maxEvents'] ?? 200);

        $listUrl = $this->buildListUrl($source, $config, $tz);
        $listHtml = $this->fetch($listUrl);

        $crawler = new Crawler($listHtml, $listUrl);
        $seen = [];
        $count = 0;

        foreach ($crawler->filter('li.listEntry') as $node) {
            if ($count >= $maxEvents) {
                break;
            }

            $entry = new Crawler($node);
            $linkNodes = $entry->filter('h3.listEntryTitle a');
            if ($linkNodes->count() === 0) {
                continue;
            }
            $link = $linkNodes->first();
            $title = trim($link->text(''));
            $href = $link->attr('href') ?? '';
            if ($title === '' || $href === '') {
                continue;
            }

            $detailUrl = $this->absoluteUrl($href);
            // The query string already holds the exact occurrence datetimes.
            [$start, $end] = $this->datesFromUrl($detailUrl, $tz);
            if ($start === null) {
                // Fall back to the rendered date text inside the list entry.
                [$start, $end] = $this->datesFromListEntry($entry, $tz);
            }
            if ($start === null) {
                continue;
            }

            $externalId = $this->externalIdFor($detailUrl, $start);
            if (isset($seen[$externalId])) {
                continue;
            }
            $seen[$externalId] = true;
            ++$count;

            // The list entry already carries the event thumbnail (lazy-loaded:
            // the real URL sits in data-src, src is a placeholder). Hotlink the
            // original; no extra request needed.
            $imageUrl = $this->imageFromListEntry($entry);

            // Without the detail page the event would be stored with venue and
            // description missing — a different content hash that downgrades
            // the existing row until the next run. Skipping it is harmless:
            // prune-unseen tolerates a week of misses.
            $detailHtml = $this->tryFetch($detailUrl);
            if ($detailHtml === null) {
                continue;
            }
            $detail = new Crawler($detailHtml, $detailUrl);
            $title = $this->detailTitle($detail) ?? $title;
            [$venueName, $locationText] = $this->location($detail);
            $description = $this->description($detail);

            $allDay = $start->format('H:i') === '00:00'
                && ($end === null || $end->format('H:i') === '00:00');

            yield new ImportedEvent(
                title: $title,
                startsAt: $start,
                endsAt: $end,
                allDay: $allDay,
                description: $description,
                venueName: $venueName,
                city: $city,
                locationText: $locationText,
                categorySlug: $this->mapCategory($title, $description),
                sourceUrl: $detailUrl,
                imageUrl: $imageUrl,
                externalId: $externalId !== '' ? $externalId : null,
                raw: [
                    'detailUrl' => $detailUrl,
                    'listUrl' => $listUrl,
                ],
            );
        }
    }

    private function buildListUrl(Source $source, array $config, \DateTimeZone $tz): string
    {
        // An explicit URL on the source wins (e.g. preconfigured filter window).
        $url = $source->getUrl();
        if (is_string($url) && $url !== '') {
            return $url;
        }

        $now = new \DateTimeImmutable('now', $tz);
        $daysAhead = (int) ($config['daysAhead'] ?? 60);
        $from = isset($config['from']) && is_string($config['from'])
            ? $config['from']
            : $now->format('Y-m-d').' 00:00:00';
        $to = isset($config['to']) && is_string($config['to'])
            ? $config['to']
            : $now->modify('+'.max(1, $daysAhead).' days')->format('Y-m-d').' 23:59:59';

        return self::BASE.self::LIST_PATH.'?from='.rawurlencode($from).'&to='.rawurlencode($to);
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

    /** Tolerant variant for detail pages: one broken page must not kill the run. */
    private function tryFetch(string $url): ?string
    {
        try {
            return $this->fetch($url);
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function absoluteUrl(string $href): string
    {
        $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }
        if (str_starts_with($href, '/')) {
            return self::BASE.$href;
        }

        return self::BASE.self::LIST_PATH.$href;
    }

    /**
     * Derive start/end from the detail link's from/to query parameters.
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function datesFromUrl(string $url, \DateTimeZone $tz): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return [null, null];
        }
        parse_str($query, $params);

        $start = $this->parseDbDate($params['from'] ?? null, $tz);
        $end = $this->parseDbDate($params['to'] ?? null, $tz);
        if ($end !== null && $start !== null && $end < $start) {
            $end = null;
        }

        return [$start, $end];
    }

    private function parseDbDate(mixed $value, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);

        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $tz);
        if ($date instanceof \DateTimeImmutable) {
            return $date;
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value, $tz);

        return $date instanceof \DateTimeImmutable ? $date->setTime(0, 0) : null;
    }

    /**
     * Fallback: parse the rendered date text in the list entry, e.g.
     * "Fr, 29.05.2026, 14:30 Uhr - 17:00 Uhr" or a multi-day span.
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function datesFromListEntry(Crawler $entry, \DateTimeZone $tz): array
    {
        $dateNodes = $entry->filter('.listEntryDate');
        if ($dateNodes->count() === 0) {
            return [null, null];
        }

        $fromDate = $this->firstText($dateNodes, '.dayDate.dayFrom');
        $fromTime = $this->firstText($dateNodes, '.timeFrom');
        $toDate = $this->firstText($dateNodes, '.dayTo.dayDate');
        $toTime = $this->firstText($dateNodes, '.timeTo');

        $start = $this->buildDate($fromDate, $fromTime, $tz);
        if ($start === null) {
            return [null, null];
        }

        // Single-day events repeat the start date for the end time.
        $end = $this->buildDate($toDate !== '' ? $toDate : $fromDate, $toTime, $tz);
        if ($end !== null && $end < $start) {
            $end = null;
        }

        return [$start, $end];
    }

    /**
     * Extract the event thumbnail from a list entry and return its absolute
     * original URL (hotlink). Images are lazy-loaded, so the real source is in
     * `data-src`; `src` only holds an inline placeholder SVG.
     */
    private function imageFromListEntry(Crawler $entry): ?string
    {
        $imgNodes = $entry->filter('.listEntryThumbnail img');
        if ($imgNodes->count() === 0) {
            $imgNodes = $entry->filter('img');
            if ($imgNodes->count() === 0) {
                return null;
            }
        }
        $img = $imgNodes->first();

        $src = $img->attr('data-src');
        if (!is_string($src) || trim($src) === '' || str_starts_with($src, 'data:')) {
            $src = $img->attr('src');
        }
        $src = is_string($src) ? trim($src) : '';
        if ($src === '' || str_starts_with($src, 'data:')) {
            return null;
        }

        return $this->absoluteUrl($src);
    }

    private function firstText(Crawler $scope, string $selector): string
    {
        $nodes = $scope->filter($selector);

        return $nodes->count() > 0 ? trim($nodes->first()->text('')) : '';
    }

    private function buildDate(string $date, string $time, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $date, $m)) {
            return null;
        }
        [$h, $i] = [0, 0];
        if (preg_match('/(\d{1,2}):(\d{2})/', $time, $tm)) {
            $h = (int) $tm[1];
            $i = (int) $tm[2];
        }

        return SafeDate::create((int) $m[3], (int) $m[2], (int) $m[1], $h, $i, $tz);
    }

    private function externalIdFor(string $url, \DateTimeImmutable $start): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $slug = basename($path, '.php');

        // The URL also carries a splitId, but the site regenerates those
        // numbers between renders — used as identity they created a fresh
        // row (and a visible duplicate) on every import. The occurrence
        // start is the stable discriminator between occurrences of an entry.
        return 'stadt_gt:'.$slug.':'.$start->format('Y-m-d\TH:i');
    }

    private function detailTitle(Crawler $detail): ?string
    {
        $h1 = $detail->filter('h1');
        if ($h1->count() === 0) {
            return null;
        }
        $title = trim($h1->first()->text(''));

        return $title !== '' ? $title : null;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function location(Crawler $detail): array
    {
        $block = $detail->filter('.elementObjectEventMultiLocation');
        if ($block->count() === 0) {
            return [null, null];
        }
        $block = $block->first();

        $venue = null;
        $strong = $block->filter('strong');
        if ($strong->count() > 0) {
            $venue = trim($strong->first()->text(''));
            $venue = $venue !== '' ? $venue : null;
        }

        // Collect all paragraph text as the full location string.
        $parts = [];
        foreach ($block->filter('p') as $p) {
            $text = trim(preg_replace('/\s+/', ' ', (new Crawler($p))->text('')) ?? '');
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        $locationText = $parts !== [] ? implode(', ', $parts) : null;

        return [$venue, $locationText];
    }

    private function description(Crawler $detail): ?string
    {
        $meta = $detail->filter('meta[name="Description"]');
        if ($meta->count() === 0) {
            $meta = $detail->filter('meta[name="description"]');
        }
        if ($meta->count() === 0) {
            return null;
        }

        $text = trim((string) $meta->first()->attr('content'));
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > 500) {
            $text = mb_substr($text, 0, 497).'...';
        }

        return $text;
    }

    private function mapCategory(string $title, ?string $description): ?string
    {
        $haystack = mb_strtolower($title.' '.($description ?? ''));

        $map = [
            'musik' => ['konzert', 'musik', 'chor', 'band', 'orchester', 'jazz', 'klavier', 'singen'],
            'party' => ['party', 'disco', 'tanzabend', 'feier'],
            'buehne' => ['theater', 'kabarett', 'comedy', 'bühne', 'lesung', 'schauspiel', 'oper', 'musical'],
            'kunst' => ['ausstellung', 'kunst', 'galerie', 'vernissage', 'museum'],
            'familie' => ['kinder', 'familie', 'spielenachmittag', 'basteln', 'jugend', 'eltern'],
            'sport' => ['sport', 'lauf', 'turnier', 'fußball', 'fussball', 'fitness', 'wanderung', 'radtour'],
            'markt' => ['markt', 'flohmarkt', 'basar', 'trödel'],
            'genuss' => ['kulinarisch', 'wein', 'kochen', 'genuss', 'kaffee', 'essen'],
            'bildung' => ['vortrag', 'seminar', 'workshop', 'kurs', 'führung', 'fuehrung', 'infoabend', 'bildung', 'lesekreis'],
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
}
