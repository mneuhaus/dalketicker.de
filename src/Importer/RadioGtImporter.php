<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Importer for Radio Gütersloh's "Veranstaltungstipps" listing.
 *
 * The listing (e.g. /service/veranstaltungstipps/126484) is server-side
 * rendered by the AMS Tools "vtipps" widget inside a TYPO3 EXT:news template.
 * There is no ICS/RSS feed and no Event JSON-LD (only a BreadcrumbList), so we
 * scrape the `div.vtipp` blocks directly.
 *
 * Each list block carries the title, a detail link, category, a date (date
 * only, possibly a range) and a venue. The precise start/end time only lives on
 * the detail page (`div.when span.caption`, format "DD.MM.YYYY, HH:MM-HH:MM Uhr"),
 * together with the city/PLZ and a description, so we follow each detail link.
 *
 * Optional source config:
 *   - listUrl:      override the listing URL (defaults to Source::getUrl())
 *   - city:         override default city (defaults to "Kreis Gütersloh")
 *   - maxDetails:   cap how many detail pages to fetch (default 40)
 */
#[AutoconfigureTag('app.source_importer')]
final class RadioGtImporter implements SourceImporter
{
    private const BASE_URL = 'https://www.radioguetersloh.de';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)';
    private const TZ = 'Europe/Berlin';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'radio_gt';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $listUrl = $config['listUrl'] ?? $source->getUrl();
        if (!$listUrl) {
            throw new \RuntimeException('radio_gt source has no URL.');
        }

        $defaultCity = $config['city'] ?? 'Kreis Gütersloh';
        $maxDetails = (int) ($config['maxDetails'] ?? 40);

        $html = $this->fetch($listUrl);

        $crawler = new Crawler($html, $listUrl);
        $tz = new \DateTimeZone(self::TZ);
        $fetched = 0;

        foreach ($crawler->filter('div.vtipp')->each(static fn (Crawler $n) => $n) as $node) {
            $event = $this->mapListItem($node, $defaultCity, $tz);
            if ($event === null) {
                continue;
            }

            // Enrich with precise time / city / description from the detail page.
            if ($fetched < $maxDetails && $event->sourceUrl !== null) {
                $detailHtml = $this->tryFetch($event->sourceUrl);
                ++$fetched;
                if ($detailHtml !== null) {
                    $event = $this->enrichFromDetail($event, $detailHtml, $tz);
                }
            }

            yield $event;
        }
    }

    private function mapListItem(Crawler $node, string $defaultCity, \DateTimeZone $tz): ?ImportedEvent
    {
        $linkNode = $node->filter('div.vtipp_title a, .vtipp_title h2 a');
        $title = '';
        $detailUrl = null;
        if ($linkNode->count() > 0) {
            $title = $this->clean($linkNode->first()->text(''));
            $href = trim($linkNode->first()->attr('href') ?? '');
            $detailUrl = $href !== '' ? $this->absoluteUrl($href) : null;
        }

        if ($title === '') {
            $titleNode = $node->filter('.vtipp_title');
            if ($titleNode->count() > 0) {
                $title = $this->clean($titleNode->first()->text(''));
            }
        }
        if ($title === '') {
            return null;
        }

        $dateText = $this->blockText($node, 'div.vtipp_date');
        [$start, $end, $allDay] = $this->parseDateRange($dateText, $tz);
        if ($start === null) {
            return null;
        }

        $venue = $this->blockText($node, 'div.vtipp_location') ?: null;
        $categoryRaw = $this->blockText($node, 'div.vtipp_category');
        $description = $this->blockText($node, 'div.vtipp_text > p, div.vtipp_text') ?: null;

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: $end,
            allDay: $allDay,
            description: $description,
            venueName: $venue,
            city: $defaultCity,
            locationText: $venue,
            categorySlug: $this->mapCategory($categoryRaw),
            sourceUrl: $detailUrl,
            imageUrl: null, // never reuse foreign images
            externalId: $detailUrl !== null ? $this->externalIdFromUrl($detailUrl) : null,
            raw: [
                'date' => $dateText,
                'location' => $venue,
                'category' => $categoryRaw,
            ],
        );
    }

    /**
     * Pull the precise start/end time, city and a cleaner description from the
     * detail page. Falls back to the list values when anything is missing.
     */
    private function enrichFromDetail(ImportedEvent $event, string $html, \DateTimeZone $tz): ImportedEvent
    {
        $crawler = new Crawler($html);

        // Date + time block: "29.05.2026, 19:00-21:00 Uhr".
        $whenText = $this->blockText($crawler, 'div.when span.caption');
        if ($whenText !== '') {
            [$start, $end, $allDay] = $this->parseWhen($whenText, $tz);
            if ($start !== null) {
                $event->startsAt = $start;
                $event->endsAt = $end ?? $event->endsAt;
                $event->allDay = $allDay;
            }
        }

        // If we still only have a date, try to lift a time out of the body text.
        if ($event->allDay) {
            $body = $crawler->filter('div.vtipp_text');
            if ($body->count() > 0 && preg_match('/\b([01]?\d|2[0-3])[.:]([0-5]\d)\s*Uhr/u', $body->text(''), $m)) {
                $event->startsAt = $event->startsAt->setTime((int) $m[1], (int) $m[2]);
                $event->allDay = false;
            }
        }

        // Venue (where) + city from the hidden address block.
        $where = $this->blockText($crawler, 'div.where span.caption');
        if ($where !== '') {
            $event->venueName = $where;
            $event->locationText = $where;
        }
        $city = $this->cityFromDetail($crawler);
        if ($city !== null) {
            $event->city = $city;
        }

        // Prefer the detail description (first paragraph), trimmed.
        $textNode = $crawler->filter('div.vtipp_text');
        if ($textNode->count() > 0) {
            $desc = $this->clean($textNode->first()->text(''));
            // Drop a leading "Details" label that the template emits.
            $desc = preg_replace('/^Details\s+/u', '', $desc) ?? $desc;
            if ($desc !== '') {
                $event->description = $this->shorten($desc, 600);
            }
        }

        return $event;
    }

    /** Extract "PLZ Ort" from the hidden address block, returning the city. */
    private function cityFromDetail(Crawler $crawler): ?string
    {
        foreach ($crawler->filter('div.vtipp_area3 div, .where + div')->each(static fn (Crawler $n) => $n) as $node) {
            if (preg_match('/\b\d{5}\s+([A-Za-zÄÖÜäöüß .\-]+)/u', $this->clean($node->text('')), $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    /** Date only or range, e.g. "21.05.2026" or "29.05.2026 - 30.05.2026". */
    private function parseDateRange(string $text, \DateTimeZone $tz): array
    {
        if (!preg_match_all('/(\d{2})\.(\d{2})\.(\d{4})/', $text, $m, PREG_SET_ORDER)) {
            return [null, null, false];
        }

        $start = $this->makeDate($m[0], $tz);
        $end = isset($m[1]) ? $this->makeDate($m[1], $tz)?->setTime(23, 59) : null;

        return [$start, $end, true]; // date only => all-day until detail refines it
    }

    /** "29.05.2026, 19:00-21:00 Uhr" => start/end with time. */
    private function parseWhen(string $text, \DateTimeZone $tz): array
    {
        if (!preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $text, $d)) {
            return [null, null, false];
        }
        $start = $this->makeDate($d, $tz);
        if ($start === null) {
            return [null, null, false];
        }

        $end = null;
        $allDay = true;
        if (preg_match('/([01]?\d|2[0-3])[.:]([0-5]\d)(?:\s*-\s*([01]?\d|2[0-3])[.:]([0-5]\d))?\s*Uhr/u', $text, $t)) {
            $start = $start->setTime((int) $t[1], (int) $t[2]);
            $allDay = false;
            if (isset($t[3], $t[4]) && $t[3] !== '') {
                $end = $start->setTime((int) $t[3], (int) $t[4]);
            }
        }

        return [$start, $end, $allDay];
    }

    /** @param array{0:string,1:string,2:string,3:string} $m */
    private function makeDate(array $m, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('d.m.Y', sprintf('%s.%s.%s', $m[1], $m[2], $m[3]), $tz);

        return $date ? $date->setTime(0, 0) : null;
    }

    private function mapCategory(string $raw): ?string
    {
        $raw = mb_strtolower($this->clean($raw));
        if ($raw === '') {
            return null;
        }

        return match (true) {
            str_contains($raw, 'konzert'), str_contains($raw, 'musik') => 'musik',
            str_contains($raw, 'party'), str_contains($raw, 'disco') => 'party',
            str_contains($raw, 'theater'), str_contains($raw, 'bühne'), str_contains($raw, 'kabarett'), str_contains($raw, 'comedy'), str_contains($raw, 'lesung') => 'buehne',
            str_contains($raw, 'kunst'), str_contains($raw, 'ausstellung'), str_contains($raw, 'museum') => 'kunst',
            str_contains($raw, 'kind'), str_contains($raw, 'familie') => 'familie',
            str_contains($raw, 'sport') => 'sport',
            str_contains($raw, 'markt'), str_contains($raw, 'flohmarkt') => 'markt',
            str_contains($raw, 'kulinar'), str_contains($raw, 'genuss'), str_contains($raw, 'essen'), str_contains($raw, 'wein') => 'genuss',
            str_contains($raw, 'vortrag'), str_contains($raw, 'seminar'), str_contains($raw, 'bildung'), str_contains($raw, 'workshop') => 'bildung',
            default => 'sonstiges',
        };
    }

    private function blockText(Crawler $node, string $selector): string
    {
        $sel = $node->filter($selector);
        if ($sel->count() === 0) {
            return '';
        }

        return $this->clean($sel->first()->text(''));
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
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        return self::BASE_URL.'/'.ltrim($href, '/');
    }

    private function externalIdFromUrl(string $url): string
    {
        if (preg_match('#/veranstaltungstipps/(\d+)#', $url, $m)) {
            return 'radio_gt:'.$m[1];
        }

        return 'radio_gt:'.sha1($url);
    }

    private function clean(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function shorten(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > 0) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut).'…';
    }
}
