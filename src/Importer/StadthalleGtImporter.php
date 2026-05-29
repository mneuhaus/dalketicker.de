<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke importer for the Stadthalle Gütersloh (TYPO3 "events" extension).
 *
 * The site exposes no ICS/RSS/JSON-LD. The full programme is reached via an
 * AJAX list endpoint (controller=AjaxEvent) that returns JSON of the form
 * {"html":"<fragment>","debug":{"showingDates":N,"moreDates":N}}. The fragment
 * uses div.teaser blocks but its dates lack the year ("21. Okt"). Each fragment
 * embeds a "Mehr Termine" button (#moreRequestButton) carrying a fresh,
 * parameter-bound cHash for the next page, which is how we paginate.
 *
 * For accurate data we visit each /veranstaltung/<slug> detail page, which
 * carries the full date ("21. Oktober 2026"), time ("20.00 Uhr"), room, price,
 * organizer and a per-occurrence list (div.event-date-block) for series.
 *
 * The per-event hero image is taken from the detail page's og:image meta tag
 * (already loaded while parsing, so no extra requests) and hotlinked as-is.
 * sourceUrl always points at the detail page.
 */
#[AutoconfigureTag('app.source_importer')]
final class StadthalleGtImporter implements SourceImporter
{
    private const BASE = 'https://www.stadthalle-gt.de';
    private const UA = 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)';
    private const MAX_PAGES = 60;

    /** German month name => month number (full + common abbreviations). */
    private const MONTHS = [
        'jan' => 1, 'januar' => 1,
        'feb' => 2, 'februar' => 2,
        'mär' => 3, 'maerz' => 3, 'märz' => 3, 'mrz' => 3,
        'apr' => 4, 'april' => 4,
        'mai' => 5,
        'jun' => 6, 'juni' => 6,
        'jul' => 7, 'juli' => 7,
        'aug' => 8, 'august' => 8,
        'sep' => 9, 'sept' => 9, 'september' => 9,
        'okt' => 10, 'oktober' => 10,
        'nov' => 11, 'november' => 11,
        'dez' => 12, 'dezember' => 12,
    ];

    /** TYPO3 category slug => Dalketicker category slug. */
    private const CATEGORY_MAP = [
        'konzert' => 'musik',
        'musical' => 'musik',
        'musiktheater' => 'buehne',
        'comedy' => 'buehne',
        'kabarett' => 'buehne',
        'show' => 'buehne',
        'theater' => 'buehne',
        'lesung' => 'buehne',
        'vortrag' => 'bildung',
        'kinder' => 'familie',
        'familie' => 'familie',
        'ausstellung' => 'kunst',
        'kunst' => 'kunst',
    ];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'stadthalle_gt';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $city = $config['city'] ?? 'Gütersloh';
        $tz = new \DateTimeZone('Europe/Berlin');

        // Reference point for inferring the year of list-only dates ("21. Okt").
        $now = new \DateTimeImmutable('now', $tz);

        $slugs = $this->collectSlugs($source->getUrl());

        $seen = [];
        foreach ($slugs as $slug => $listDate) {
            try {
                $events = $this->parseDetail($slug, $listDate, $city, $tz, $now);
            } catch (\Throwable) {
                $events = [];
            }

            if ($events === []) {
                // Detail page unreachable/unparseable: emit a minimal event from
                // the list date alone so the occurrence is not silently lost.
                $fallback = $this->buildFromList($slug, $listDate, $city, $tz, $now);
                if ($fallback !== null) {
                    $events = [$fallback];
                }
            }

            foreach ($events as $event) {
                $key = $event->externalId ?? $event->dedupKey();
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                yield $event;
            }
        }
    }

    /**
     * Walks the main page and the AJAX pagination chain, returning a map of
     * detail slug => raw list date string ("21. Okt") for year inference.
     *
     * @return array<string, ?string>
     */
    private function collectSlugs(?string $startUrl): array
    {
        $slugs = [];

        // First batch: the main page already embeds #ajaxTeaserHolder.
        $main = $this->fetch(self::BASE . '/');
        $next = $main !== null ? $this->harvest($main, $slugs) : null;

        // If the main page yielded nothing usable, fall back to the configured
        // AJAX list URL (which carries its own cHash).
        if ($next === null && $startUrl) {
            $json = $this->fetchAjax($startUrl);
            if ($json !== null) {
                $next = $this->harvest($json, $slugs);
            }
        }

        $pages = 0;
        while ($next !== null && $pages < self::MAX_PAGES) {
            ++$pages;
            $json = $this->fetchAjax($next);
            if ($json === null) {
                break;
            }
            $next = $this->harvest($json, $slugs);
        }

        return $slugs;
    }

    /**
     * Extracts teaser slugs + raw list dates from a page/fragment and returns
     * the absolute URL of the next "Mehr Termine" button, or null when done.
     *
     * @param array<string, ?string> $slugs filled by reference
     */
    private function harvest(string $html, array &$slugs): ?string
    {
        $crawler = new Crawler($html);

        $crawler->filter('div.teaser')->each(function (Crawler $teaser) use (&$slugs): void {
            $link = $teaser->filter('a[href*="/veranstaltung/"]');
            if (!$link->count()) {
                return;
            }
            $slug = $this->slugFromHref($link->first()->attr('href') ?? '');
            if ($slug === null) {
                return;
            }
            $date = null;
            $dateNode = $teaser->filter('span.date');
            if ($dateNode->count()) {
                $date = trim($dateNode->first()->text(''));
            }
            // Keep the earliest list date seen for a slug.
            if (!array_key_exists($slug, $slugs)) {
                $slugs[$slug] = $date;
            }
        });

        $button = $crawler->filter('#moreRequestButton a, .teasers-more a[data-linktype="ajax"]');
        if (!$button->count()) {
            return null;
        }
        $href = $button->first()->attr('href');

        return $href ? $this->absolute($href) : null;
    }

    /**
     * Parses a detail page into one ImportedEvent per occurrence.
     *
     * @return list<ImportedEvent>
     */
    private function parseDetail(
        string $slug,
        ?string $listDate,
        string $city,
        \DateTimeZone $tz,
        \DateTimeImmutable $now,
    ): array {
        $url = self::BASE . '/veranstaltung/' . $slug;
        $html = $this->fetch($url);
        if ($html === null) {
            return [];
        }

        $crawler = new Crawler($html);

        $title = $this->firstText($crawler, 'h1');
        if ($title === null || $title === '') {
            return [];
        }

        $subtitle = $this->firstText($crawler, '.main-title-subtitle');
        $cancelled = stripos($html, 'ABGESAGT') !== false;

        $categorySlug = null;
        $catNode = $crawler->filter('.event-categories a');
        if ($catNode->count()) {
            $catHref = $catNode->first()->attr('href') ?? '';
            if (preg_match('#/kategorie/([a-z0-9-]+)#', $catHref, $m)) {
                $categorySlug = self::CATEGORY_MAP[$m[1]] ?? null;
            }
        }

        $description = $this->metaDescription($crawler);
        $imageUrl = $this->ogImage($crawler);

        // Venue: room head + venue name ("Großer Saal" / "Stadthalle").
        $room = $this->firstText($crawler, '.event-location-head');
        $venueName = $room !== null && $room !== '' ? 'Stadthalle Gütersloh – ' . $room : 'Stadthalle Gütersloh';

        $organizer = null;
        $orgNode = $crawler->filter('.event-organizer span:not(.event-organizer-head)');
        if ($orgNode->count()) {
            $organizer = trim($orgNode->first()->text('')) ?: null;
        }

        $events = [];
        $blocks = $crawler->filter('.event-date-block');
        $occurrence = 0;
        $blocks->each(function (Crawler $block) use (
            &$events,
            &$occurrence,
            $slug,
            $url,
            $title,
            $subtitle,
            $description,
            $imageUrl,
            $venueName,
            $city,
            $organizer,
            $categorySlug,
            $cancelled,
            $tz,
        ): void {
            // A single date block carries one h3; a date range ("23. Januar
            // 2026 — 10. Juni 2026") splits the start and end over two h3s.
            $h3 = $block->filter('h3');
            $dateText = $h3->count() ? trim($h3->first()->text('')) : null;
            $start = $this->parseFullDate($dateText, $tz);
            if ($start === null) {
                return;
            }
            $end = null;
            if ($h3->count() > 1) {
                $end = $this->parseFullDate(trim($h3->eq(1)->text('')), $tz);
            }

            $time = null;
            $price = null;
            $block->filter('span')->each(function (Crawler $span) use (&$time, &$price): void {
                $text = trim($span->text(''));
                if ($text === '') {
                    return;
                }
                if ($time === null && preg_match('/(\d{1,2})[:.](\d{2})\s*Uhr/u', $text, $m)) {
                    $time = [(int) $m[1], (int) $m[2]];
                } elseif ($price === null && str_contains($text, '€')) {
                    $price = trim($text);
                }
            });

            $allDay = $time === null;
            if ($time !== null) {
                $start = $start->setTime($time[0], $time[1]);
            }
            // For a multi-day range without a known end time, keep the end on
            // the last day at end-of-day so the occurrence spans the period.
            if ($end !== null) {
                $end = $time !== null ? $end->setTime($time[0], $time[1]) : $end->setTime(23, 59);
            }

            ++$occurrence;

            $events[] = new ImportedEvent(
                title: $this->composeTitle($title, $subtitle, $cancelled),
                startsAt: $start,
                endsAt: $end,
                allDay: $allDay,
                description: $description,
                venueName: $venueName,
                city: $city,
                locationText: $venueName,
                categorySlug: $categorySlug,
                sourceUrl: $url,
                imageUrl: $imageUrl,
                price: $price,
                organizer: $organizer,
                externalId: 'stadthalle_gt:' . $slug . ':' . $start->format('Y-m-d-Hi'),
                raw: [
                    'slug' => $slug,
                    'occurrence' => $occurrence,
                    'rawDate' => $dateText,
                    'cancelled' => $cancelled,
                ],
            );
        });

        return $events;
    }

    /**
     * Fallback event built purely from the list teaser when the detail page is
     * unavailable. The year is inferred chronologically from the current date.
     */
    private function buildFromList(
        string $slug,
        ?string $listDate,
        string $city,
        \DateTimeZone $tz,
        \DateTimeImmutable $now,
    ): ?ImportedEvent {
        $start = $this->parseShortDate($listDate, $tz, $now);
        if ($start === null) {
            return null;
        }

        return new ImportedEvent(
            title: ucwords(str_replace('-', ' ', $slug)),
            startsAt: $start,
            allDay: true,
            description: null,
            venueName: 'Stadthalle Gütersloh',
            city: $city,
            locationText: 'Stadthalle Gütersloh',
            categorySlug: null,
            sourceUrl: self::BASE . '/veranstaltung/' . $slug,
            imageUrl: null,
            externalId: 'stadthalle_gt:' . $slug . ':' . $start->format('Y-m-d'),
            raw: ['slug' => $slug, 'rawDate' => $listDate, 'fallback' => true],
        );
    }

    private function composeTitle(string $title, ?string $subtitle, bool $cancelled): string
    {
        $full = $title;
        if ($subtitle !== null && $subtitle !== '' && stripos($subtitle, $title) === false) {
            $full .= ': ' . $subtitle;
        }
        if ($cancelled && stripos($full, 'abgesagt') === false) {
            $full .= ' (ABGESAGT)';
        }

        return $full;
    }

    /** Parses a full German date like "21. Oktober 2026" (time set later). */
    private function parseFullDate(?string $text, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if ($text === null) {
            return null;
        }
        if (!preg_match('/(\d{1,2})\.\s*([A-Za-zÄÖÜäöü]+)\s+(\d{4})/u', $text, $m)) {
            return null;
        }
        $month = $this->monthNumber($m[2]);
        if ($month === null) {
            return null;
        }

        return (new \DateTimeImmutable('now', $tz))
            ->setDate((int) $m[3], $month, (int) $m[1])
            ->setTime(0, 0);
    }

    /**
     * Parses a year-less list date like "21. Okt" and infers the year so the
     * date lands within roughly the next twelve months.
     */
    private function parseShortDate(?string $text, \DateTimeZone $tz, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if ($text === null) {
            return null;
        }
        if (!preg_match('/(\d{1,2})\.\s*([A-Za-zÄÖÜäöü]+)/u', $text, $m)) {
            return null;
        }
        $month = $this->monthNumber($m[2]);
        if ($month === null) {
            return null;
        }
        $day = (int) $m[1];

        $candidate = $now->setDate((int) $now->format('Y'), $month, $day)->setTime(0, 0);
        // If the date already lies clearly in the past, assume next year.
        if ($candidate < $now->modify('-7 days')) {
            $candidate = $candidate->setDate((int) $now->format('Y') + 1, $month, $day);
        }

        return $candidate;
    }

    private function monthNumber(string $name): ?int
    {
        $key = mb_strtolower(trim($name), 'UTF-8');
        $key = rtrim($key, '.');
        if (isset(self::MONTHS[$key])) {
            return self::MONTHS[$key];
        }
        // Try a 3-letter abbreviation of a full name.
        $abbr = mb_substr($key, 0, 3, 'UTF-8');

        return self::MONTHS[$abbr] ?? null;
    }

    private function metaDescription(Crawler $crawler): ?string
    {
        foreach (['meta[name="description"]', 'meta[property="og:description"]'] as $sel) {
            $node = $crawler->filter($sel);
            if ($node->count()) {
                $content = trim($node->first()->attr('content') ?? '');
                if ($content !== '') {
                    return mb_substr($content, 0, 500);
                }
            }
        }

        return null;
    }

    /**
     * Returns the absolute og:image URL of a detail page (hotlinked, not
     * downloaded), or null when none is present. The source occasionally emits
     * a doubled slash after the host ("…de//fileadmin/…"), which we collapse.
     */
    private function ogImage(Crawler $crawler): ?string
    {
        foreach (['meta[property="og:image"]', 'meta[name="twitter:image"]'] as $sel) {
            $node = $crawler->filter($sel);
            if (!$node->count()) {
                continue;
            }
            $content = trim($node->first()->attr('content') ?? '');
            if ($content === '') {
                continue;
            }
            $url = $this->absolute($content);
            // Collapse a stray double slash in the path (but keep "https://").
            $url = preg_replace('#([^:])//+#', '$1/', $url) ?? $url;

            return $url;
        }

        return null;
    }

    private function firstText(Crawler $crawler, string $selector): ?string
    {
        $node = $crawler->filter($selector);
        if (!$node->count()) {
            return null;
        }

        return trim($node->first()->text('')) ?: null;
    }

    private function slugFromHref(string $href): ?string
    {
        if (preg_match('#/veranstaltung/([a-z0-9-]+)#', $href, $m)) {
            return $m[1];
        }

        return null;
    }

    private function absolute(string $href): string
    {
        $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);
        if (str_starts_with($href, 'http')) {
            return $href;
        }

        return self::BASE . '/' . ltrim($href, '/');
    }

    private function fetch(string $url): ?string
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => self::UA],
                'timeout' => 30,
            ]);
            if ($response->getStatusCode() >= 400) {
                return null;
            }

            return $response->getContent(false);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Fetches an AJAX list URL and returns its embedded HTML fragment. */
    private function fetchAjax(string $url): ?string
    {
        $body = $this->fetch($url);
        if ($body === null) {
            return null;
        }
        try {
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($data) && isset($data['html']) && \is_string($data['html'])
            ? $data['html']
            : null;
    }
}
