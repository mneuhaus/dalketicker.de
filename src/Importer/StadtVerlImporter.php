<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke HTML scraper for the city of Verl event calendar
 * (https://www.verl.de/freizeit-kultur/veranstaltungskalender.html).
 *
 * TYPO3 site with the cyt_eventcalendar extension; no ICS/RSS/JSON-LD feed
 * exists. The list page renders server-side and is paginated via the
 * `tx_cyteventcalendar_calendarlist[page]` query param. We follow the
 * pagination links found in `.cyt-eventcalendar-pagination` (their cHash is
 * page-specific, so building them by hand is unreliable).
 *
 * Each event is an `a.item`:
 *   - href:            relative detail URL ending in `-DD-MM-YYYY.html`.
 *   - h3.title:        event title.
 *   - div.date-time:   text "DD.MM.YYYY  HH.MM  [- HH.MM]  Uhr" (dot time sep).
 *   - div.organizer:   organizer name.
 *   - img.border:      relative thumbnail (often a generic logo).
 */
#[AutoconfigureTag('app.source_importer')]
final class StadtVerlImporter implements SourceImporter
{
    private const BASE = 'https://www.verl.de';
    private const DEFAULT_LIST = self::BASE.'/freizeit-kultur/veranstaltungskalender.html';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.de)';
    private const MAX_EVENTS = 200;
    private const MAX_PAGES = 8;

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'stadt_verl';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $tz = new \DateTimeZone('Europe/Berlin');
        $city = $config['city'] ?? 'Verl';

        $listUrl = $source->getUrl() ?: self::DEFAULT_LIST;
        $seen = [];
        $count = 0;
        $visitedPages = [];

        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $html = $this->fetch($listUrl);
            if ($html === null) {
                break;
            }
            $visitedPages[$listUrl] = true;

            $crawler = new Crawler($html, $listUrl);

            foreach ($crawler->filter('a.item') as $node) {
                if ($count >= self::MAX_EVENTS) {
                    return;
                }

                try {
                    $event = $this->parseItem(new Crawler($node), $tz, $city);
                } catch (\Throwable) {
                    continue;
                }
                if ($event === null) {
                    continue;
                }

                if (isset($seen[$event->externalId])) {
                    continue;
                }
                $seen[$event->externalId] = true;
                ++$count;

                yield $event;
            }

            $next = $this->nextPageUrl($crawler);
            if ($next === null || isset($visitedPages[$next])) {
                break;
            }
            $listUrl = $next;
        }
    }

    private function parseItem(Crawler $item, \DateTimeZone $tz, string $city): ?ImportedEvent
    {
        $href = $item->attr('href');
        if (!is_string($href) || $href === '') {
            return null;
        }
        $detailUrl = $this->absolute($href);

        $title = $this->text($item, 'h3.title');
        if ($title === null || $title === '') {
            return null;
        }

        $dateTimeText = $this->text($item, 'div.date-time');
        $start = $this->parseStart($dateTimeText, $href, $tz);
        if ($start === null) {
            return null;
        }
        $end = $this->parseEnd($dateTimeText, $start, $tz);

        $organizer = $this->text($item, 'div.organizer');
        $imageUrl = $this->absolute($this->attr($item, 'img.border', 'src'));

        $allDay = $start->format('H:i') === '00:00';

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: $end,
            allDay: $allDay,
            city: $city,
            categorySlug: $this->mapCategory($title, $organizer),
            sourceUrl: $detailUrl,
            imageUrl: $imageUrl,
            organizer: $organizer,
            externalId: 'stadt_verl:'.basename((string) parse_url($detailUrl, PHP_URL_PATH), '.html'),
            raw: array_filter([
                'dateTime' => $dateTimeText,
                'href' => $href,
            ], static fn ($v) => $v !== null && $v !== ''),
        );
    }

    /**
     * Parse "DD.MM.YYYY  HH.MM" out of the date-time text. The date in the href
     * (-DD-MM-YYYY.html) is used as a fallback if the inline date is missing.
     */
    private function parseStart(?string $text, string $href, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $day = $month = $year = null;
        $hour = 0;
        $minute = 0;

        if ($text !== null && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $text, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/-(\d{2})-(\d{2})-(\d{4})\.html$/', $href, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        }

        if ($day === null) { // day/month/year are always assigned together
            return null;
        }

        // First time token after the date: "HH.MM" (dot separator).
        if ($text !== null && preg_match('/\d{2}\.\d{2}\.\d{4}\s+(\d{1,2})\.(\d{2})/', $text, $tm)) {
            $hour = (int) $tm[1];
            $minute = (int) $tm[2];
        }

        return SafeDate::create($year, $month, $day, $hour, $minute, $tz);
    }

    /**
     * Parse an optional end time ("- HH.MM") that follows the start time on the
     * same calendar day.
     */
    private function parseEnd(?string $text, \DateTimeImmutable $start, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if ($text === null || !preg_match('/-\s*(\d{1,2})\.(\d{2})\s*(?:Uhr|$)/', $text, $m)) {
            return null;
        }
        $end = $start->setTime((int) $m[1], (int) $m[2]);

        return $end > $start ? $end : null;
    }

    private function nextPageUrl(Crawler $crawler): ?string
    {
        $pagination = $crawler->filter('.cyt-eventcalendar-pagination a, nav.cyt-eventcalendar-pagination a');
        foreach ($pagination as $a) {
            $node = new Crawler($a);
            $rel = (string) $node->attr('rel');
            $label = mb_strtolower(trim($node->text('')));
            $cls = (string) $node->attr('class');
            if (str_contains($rel, 'next')
                || str_contains($cls, 'next')
                || str_contains($label, 'weiter')
                || str_contains($label, 'nächste')
                || $label === '›' || $label === '»') {
                $href = $node->attr('href');
                if (is_string($href) && $href !== '') {
                    return $this->absolute($href);
                }
            }
        }

        return null;
    }

    private function mapCategory(string $title, ?string $organizer): ?string
    {
        $haystack = mb_strtolower($title.' '.($organizer ?? ''));

        $map = [
            'musik' => ['konzert', 'musik', 'chor', 'band', 'orchester', 'jazz', 'singen', 'festival'],
            'party' => ['party', 'disco', 'tanzabend'],
            'buehne' => ['theater', 'kabarett', 'comedy', 'bühne', 'lesung', 'schauspiel', 'oper', 'musical'],
            'kunst' => ['ausstellung', 'kunst', 'galerie', 'vernissage', 'museum'],
            'familie' => ['kinder', 'familie', 'basteln', 'jugend', 'spielzeug'],
            'sport' => ['sport', 'lauf', 'turnier', 'fußball', 'fussball', 'fitness', 'wanderung', 'radtour', 'hockey'],
            'markt' => ['markt', 'flohmarkt', 'basar', 'trödel', 'troedel'],
            'genuss' => ['kulinarisch', 'wein', 'kochabend', 'kochen', 'genuss', 'kaffee'],
            'bildung' => ['vortrag', 'seminar', 'workshop', 'kurs', 'führung', 'fuehrung', 'infoabend', 'messe', 'karriere'],
        ];

        foreach ($map as $slug => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return $slug;
                }
            }
        }

        return 'sonstiges';
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

    private function absolute(?string $href): ?string
    {
        if ($href === null || $href === '') {
            return null;
        }
        $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        return self::BASE.'/'.ltrim($href, '/');
    }

    private function text(Crawler $node, string $selector): ?string
    {
        $sub = $node->filter($selector);
        if ($sub->count() === 0) {
            return null;
        }
        $text = trim(preg_replace('/\s+/', ' ', $sub->first()->text('')) ?? '');

        return $text !== '' ? $text : null;
    }

    private function attr(Crawler $node, string $selector, string $attr): ?string
    {
        $sub = $node->filter($selector);
        if ($sub->count() === 0) {
            return null;
        }

        return $sub->first()->attr($attr);
    }
}
