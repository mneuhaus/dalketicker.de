<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke HTML scraper for the city of Rietberg event calendar at
 * https://www.rietberg.de/tourismus/freizeitangebote/veranstaltungen/uebersicht.html
 *
 * The site runs the TYPO3 "cyt-eventcalendar" plugin and renders server-side
 * HTML with schema.org Microdata (no ICS/RSS/JSON-LD feed). Each event is a
 * `<li class="cyt-eventcalendar-list-item" itemtype="http://schema.org/Event">`
 * carrying:
 *   - `[itemprop=startDate]` / `[itemprop=endDate]` content attributes in ISO
 *     "YYYY-MM-DDTHH:MM" format (all-day events use the placeholder time T01:00).
 *   - `.cyt-eventcalendar-list-item-title[itemprop=name] > a` with the title and
 *     the detail link (relative `/tourismus/.../detail/...`).
 *   - `.cyt-eventcalendar-list-item-image img[itemprop=image]` thumbnail.
 *   - a nested `[itemprop=location]` Place with name + PostalAddress.
 *   - `.cyt-eventcalendar-list-item-category span` with a free-text category.
 *
 * The overview shows 15 events per page. Pagination links live in
 * `ul.cyt-eventcalendar-pagination` and each carries its own `cHash`, so we
 * follow the rendered anchors rather than constructing URLs ourselves.
 *
 * Optional source config:
 *   - city:       default city (defaults to "Rietberg")
 *   - maxEvents:  cap on number of events (default 200)
 *   - maxPages:   cap on pagination follows (default 12)
 */
#[AutoconfigureTag('app.source_importer')]
final class StadtRietbergImporter implements SourceImporter
{
    private const BASE = 'https://www.rietberg.de';
    private const DEFAULT_LIST = self::BASE.'/tourismus/freizeitangebote/veranstaltungen/uebersicht.html';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.de)';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'stadt_rietberg';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $tz = new \DateTimeZone('Europe/Berlin');
        $city = $config['city'] ?? 'Rietberg';
        $maxEvents = (int) ($config['maxEvents'] ?? 200);
        $maxPages = (int) ($config['maxPages'] ?? 12);

        $url = $source->getUrl() ?: self::DEFAULT_LIST;
        $seenPages = [];
        $count = 0;

        for ($page = 0; $page < $maxPages; ++$page) {
            if ($url === null || isset($seenPages[$url])) {
                break;
            }
            $seenPages[$url] = true;

            $html = $this->fetch($url);
            if ($html === null) {
                break;
            }
            $crawler = new Crawler($html, $url);

            foreach ($crawler->filter('li.cyt-eventcalendar-list-item') as $node) {
                if ($count >= $maxEvents) {
                    return;
                }

                $event = $this->parseItem(new Crawler($node), $tz, $city);
                if ($event === null) {
                    continue;
                }
                ++$count;
                yield $event;
            }

            $url = $this->nextPageUrl($crawler, $seenPages);
        }
    }

    private function parseItem(Crawler $item, \DateTimeZone $tz, string $city): ?ImportedEvent
    {
        $titleNode = $item->filter('.cyt-eventcalendar-list-item-title');
        if ($titleNode->count() === 0) {
            return null;
        }
        $title = trim($titleNode->first()->text(''));
        if ($title === '') {
            return null;
        }

        $start = $this->parseIso($this->itemContent($item, '[itemprop="startDate"]'), $tz);
        if ($start === null) {
            return null;
        }
        $end = $this->parseIso($this->itemContent($item, '[itemprop="endDate"]'), $tz);
        if ($end !== null && $end < $start) {
            $end = null;
        }

        // The plugin uses T01:00 as the placeholder time for all-day events.
        $allDay = $start->format('H:i') === '01:00';
        if ($allDay) {
            $start = $start->setTime(0, 0);
            if ($end !== null) {
                $end = $end->format('H:i') === '01:00' ? null : $end;
            }
        }

        $href = $this->detailHref($item);
        if ($href === null) {
            return null;
        }
        $detailUrl = $this->absoluteUrl($href);
        $externalId = $this->externalIdFromUrl($detailUrl);

        $imageUrl = $this->imageUrl($item);
        $category = $this->itemText($item, '.cyt-eventcalendar-list-item-category');
        [$venueName, $locationText] = $this->location($item);

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: $end,
            allDay: $allDay,
            venueName: $venueName,
            city: $city,
            locationText: $locationText,
            categorySlug: $this->mapCategory($category, $title),
            sourceUrl: $detailUrl,
            imageUrl: $imageUrl,
            externalId: $externalId,
            raw: array_filter([
                'category' => $category,
                'href' => $href,
            ], static fn ($v) => $v !== null && $v !== ''),
        );
    }

    private function detailHref(Crawler $item): ?string
    {
        foreach ($item->filter('a') as $a) {
            $href = (new Crawler($a))->attr('href');
            if (is_string($href) && str_contains($href, '/veranstaltungen/detail/')) {
                return $href;
            }
        }

        return null;
    }

    private function imageUrl(Crawler $item): ?string
    {
        $img = $item->filter('.cyt-eventcalendar-list-item-image img[itemprop="image"]');
        if ($img->count() === 0) {
            $img = $item->filter('img[itemprop="image"]');
        }
        if ($img->count() === 0) {
            return null;
        }
        $src = $img->first()->attr('src');
        if (!is_string($src) || trim($src) === '' || str_starts_with($src, 'data:')) {
            return null;
        }

        return $this->absoluteUrl(trim($src));
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function location(Crawler $item): array
    {
        $block = $item->filter('.cyt-eventcalendar-list-item-location');
        if ($block->count() === 0) {
            return [null, null];
        }
        $block = $block->first();

        $venue = null;
        $name = $block->filter('[itemprop="name"]');
        if ($name->count() > 0) {
            $venue = trim($name->first()->text(''));
            $venue = $venue !== '' ? $venue : null;
        }

        $parts = [];
        foreach (['streetAddress', 'postalCode', 'addressLocality'] as $prop) {
            $n = $block->filter('[itemprop="'.$prop.'"]');
            if ($n->count() > 0) {
                $t = trim($n->first()->text(''));
                if ($t !== '') {
                    $parts[] = $t;
                }
            }
        }

        $location = [];
        if ($venue !== null) {
            $location[] = $venue;
        }
        if ($parts !== []) {
            $location[] = implode(' ', $parts);
        }

        return [$venue, $location !== [] ? implode(', ', $location) : null];
    }

    private function nextPageUrl(Crawler $crawler, array $seenPages): ?string
    {
        $links = $crawler->filter('ul.cyt-eventcalendar-pagination a');
        $current = 0;
        $candidates = [];
        foreach ($links as $a) {
            $node = new Crawler($a);
            $page = (int) ($node->attr('data-page') ?? '0');
            $href = $node->attr('href');
            if ($page <= 0 || !is_string($href) || $href === '') {
                continue;
            }
            $abs = $this->absoluteUrl(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));
            $candidates[$page] = $abs;
            $parentClass = $node->closest('li')?->attr('class') ?? '';
            if (str_contains($parentClass, 'current')) {
                $current = $page;
            }
        }

        $nextPage = $current + 1;
        if (isset($candidates[$nextPage]) && !isset($seenPages[$candidates[$nextPage]])) {
            return $candidates[$nextPage];
        }

        return null;
    }

    private function fetch(string $url): ?string
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => 20,
            ]);
            if ($response->getStatusCode() >= 400) {
                return null;
            }

            return $response->getContent();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseIso(?string $value, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, $tz);
            if ($date instanceof \DateTimeImmutable) {
                return $format === 'Y-m-d' ? $date->setTime(0, 0) : $date;
            }
        }

        return null;
    }

    private function itemContent(Crawler $item, string $selector): ?string
    {
        $n = $item->filter($selector);

        return $n->count() > 0 ? $n->first()->attr('content') : null;
    }

    private function itemText(Crawler $item, string $selector): ?string
    {
        $n = $item->filter($selector);
        if ($n->count() === 0) {
            return null;
        }
        $t = trim(preg_replace('/\s+/', ' ', $n->first()->text('')) ?? '');

        return $t !== '' ? $t : null;
    }

    private function absoluteUrl(string $href): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }
        if (str_starts_with($href, '/')) {
            return self::BASE.$href;
        }

        return self::BASE.'/'.$href;
    }

    private function externalIdFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $slug = basename($path, '.html');

        return 'stadt_rietberg:'.$slug;
    }

    private function mapCategory(?string $category, string $title): ?string
    {
        $haystack = mb_strtolower(($category ?? '').' '.$title);
        if (trim($haystack) === '') {
            return null;
        }

        $map = [
            'musik' => ['konzert', 'musik', 'chor', 'band', 'orchester', 'jazz', 'klavier', 'singen'],
            'party' => ['party', 'disco', 'tanzabend', 'feier'],
            'buehne' => ['theater', 'kabarett', 'comedy', 'bühne', 'lesung', 'schauspiel', 'oper', 'musical'],
            'kunst' => ['ausstellung', 'kunst', 'galerie', 'vernissage', 'museum'],
            'familie' => ['kinder', 'familie', 'spielenachmittag', 'basteln', 'jugend', 'eltern', 'hüpfburg'],
            'sport' => ['sport', 'lauf', 'turnier', 'fußball', 'fussball', 'fitness', 'wanderung', 'radtour'],
            'markt' => ['markt', 'flohmarkt', 'basar', 'trödel'],
            'genuss' => ['kulinarisch', 'wein', 'kochen', 'genuss', 'kaffee', 'essen'],
            'bildung' => ['vortrag', 'seminar', 'workshop', 'kurs', 'führung', 'fuehrung', 'infoabend', 'bildung'],
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
