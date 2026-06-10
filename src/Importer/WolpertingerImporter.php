<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Importer for "Wolpertinger – Der Spieleladen" (https://wolpertinger-der-spieleladen.de/),
 * a board-game store in Gütersloh that runs tournaments and game nights.
 *
 * The single-page site is rendered server-side (Bootstrap, no SPA, no WP REST/
 * tribe feed, no JSON-LD). The event list lives in an `.eventplan` section as a
 * stack of `.event` blocks. Each block carries:
 *   - `.eventday`     the day-of-month number
 *   - `.eventmonth`   the German month name (the year is NOT printed)
 *   - `.eventtitle`   the event title
 *   - `.eventdescshort` / `.eventdesc.details` description text, the latter
 *     containing a "Zeit & Ort: HH:MM - HH:MM Uhr Venue, street, zip city" line
 *
 * There are no per-event detail links (the "Details" buttons just expand the
 * inline text) and no event images, so sourceUrl points at the page (with a
 * stable anchor) and imageUrl stays null.
 *
 * Because the markup omits the year, we infer it: a month/day already in the
 * past for the current year is assumed to be next year. externalId is derived
 * from the resolved date + a normalized title so re-runs dedupe cleanly.
 *
 * Optional source config:
 *   - city:  default city (default: Gütersloh)
 */
#[AutoconfigureTag('app.source_importer')]
final class WolpertingerImporter implements SourceImporter
{
    private const DEFAULT_URL = 'https://wolpertinger-der-spieleladen.de/';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)';

    private const MONTHS = [
        'januar' => 1, 'jan' => 1,
        'februar' => 2, 'feb' => 2,
        'märz' => 3, 'maerz' => 3, 'mär' => 3, 'mae' => 3, 'mrz' => 3,
        'april' => 4, 'apr' => 4,
        'mai' => 5,
        'juni' => 6, 'jun' => 6,
        'juli' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8,
        'september' => 9, 'sep' => 9, 'sept' => 9,
        'oktober' => 10, 'okt' => 10,
        'november' => 11, 'nov' => 11,
        'dezember' => 12, 'dez' => 12,
    ];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'wolpertinger';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl() ?: self::DEFAULT_URL;
        $config = $source->getConfig();
        $defaultCity = (string) ($config['city'] ?? 'Gütersloh');
        $tz = new \DateTimeZone('Europe/Berlin');
        $now = new \DateTimeImmutable('now', $tz);

        $html = $this->fetch($url);

        try {
            $crawler = new Crawler($html, $url);
        } catch (\Throwable) {
            return;
        }

        $nodes = $crawler->filter('.eventplan .event');
        if ($nodes->count() === 0) {
            return;
        }

        $seen = [];
        foreach ($nodes as $domNode) {
            $event = new Crawler($domNode);
            $mapped = $this->mapEvent($event, $url, $defaultCity, $tz, $now);
            if ($mapped === null) {
                continue;
            }
            if (isset($seen[$mapped->externalId])) {
                continue;
            }
            $seen[$mapped->externalId] = true;
            yield $mapped;
        }
    }

    private function mapEvent(
        Crawler $event,
        string $pageUrl,
        string $defaultCity,
        \DateTimeZone $tz,
        \DateTimeImmutable $now,
    ): ?ImportedEvent {
        $title = $this->text($event, '.eventtitle');
        if ($title === '') {
            return null;
        }

        $dayRaw = $this->text($event, '.eventday');
        $monthRaw = $this->text($event, '.eventmonth');
        if ($dayRaw === '' || $monthRaw === '') {
            return null;
        }
        $day = (int) preg_replace('/\D+/', '', $dayRaw);
        $month = self::MONTHS[mb_strtolower(trim($monthRaw))] ?? null;
        if ($day < 1 || $day > 31 || $month === null) {
            return null;
        }

        // The full-description block carries the "Zeit & Ort" line.
        $detailHtml = $this->innerHtml($event, '.eventdesc.details');
        [$startTime, $endTime] = $this->parseTimes($detailHtml);
        [$venueName, $locationText, $city] = $this->parseLocation($detailHtml, $defaultCity);

        $start = $this->resolveDate($day, $month, $startTime, $tz, $now);
        if ($start === null) {
            return null;
        }
        $end = null;
        if ($endTime !== null) {
            $candidate = $start->setTime((int) $endTime[0], (int) $endTime[1]);
            // An end before the start means it crosses midnight; keep same day
            // otherwise (these are short evening events) and only set a real range.
            if ($candidate > $start) {
                $end = $candidate;
            }
        }
        $allDay = $startTime === null;

        $description = $this->buildDescription($event);
        $externalId = self::getKey().':'.$start->format('Y-m-d').':'.ImportedEvent::normalize($title);

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: $end,
            allDay: $allDay,
            description: $description,
            venueName: $venueName ?? 'Wolpertinger – Der Spieleladen',
            city: $city ?? $defaultCity,
            locationText: $locationText,
            categorySlug: 'sonstiges',
            sourceUrl: $pageUrl.'#'.rawurlencode(ImportedEvent::normalize($title)),
            imageUrl: null,
            price: null,
            organizer: 'Wolpertinger – Der Spieleladen',
            externalId: $externalId,
            raw: [
                'title' => $title,
                'day' => $day,
                'month' => $month,
                'startTime' => $startTime !== null ? sprintf('%02d:%02d', $startTime[0], $startTime[1]) : null,
            ],
        );
    }

    /**
     * Infer the calendar year: pick the current year, but if that date already
     * lies clearly in the past (more than a day ago) assume the next year, since
     * the list only ever advertises upcoming events.
     *
     * @param array{0:int,1:int}|null $time
     */
    private function resolveDate(int $day, int $month, ?array $time, \DateTimeZone $tz, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        [$h, $m] = $time ?? [0, 0];
        $year = (int) $now->format('Y');

        $date = SafeDate::create($year, $month, $day, $h, $m, $tz);
        if ($date === null) {
            return null;
        }
        if ($date < $now->modify('-1 day')) {
            $date = SafeDate::create($year + 1, $month, $day, $h, $m, $tz) ?? $date;
        }

        return $date;
    }

    /**
     * Pulls "17:00 - 21:00 Uhr" style time ranges from the detail text.
     *
     * @return array{0:?array{0:int,1:int},1:?array{0:int,1:int}} [start, end]
     */
    private function parseTimes(string $detailHtml): array
    {
        $text = $this->htmlToText($detailHtml);
        if ($text === '') {
            return [null, null];
        }

        if (preg_match('/(\d{1,2})[:.](\d{2})\s*(?:-|–|bis)\s*(\d{1,2})[:.](\d{2})/u', $text, $m)) {
            return [[(int) $m[1], (int) $m[2]], [(int) $m[3], (int) $m[4]]];
        }
        if (preg_match('/(\d{1,2})[:.](\d{2})\s*Uhr/u', $text, $m)) {
            return [[(int) $m[1], (int) $m[2]], null];
        }

        return [null, null];
    }

    /**
     * Extracts venue/address from the "Zeit & Ort" line, e.g.
     * "... Uhr Wolpertinger, Blessenstätte 25, 33330 Gütersloh".
     *
     * @return array{0:?string,1:?string,2:?string} [venueName, locationText, city]
     */
    private function parseLocation(string $detailHtml, string $defaultCity): array
    {
        $text = $this->htmlToText($detailHtml);
        if ($text === '') {
            return [null, null, null];
        }

        // Take everything after the last "Uhr" (end of the time range) within the
        // "Zeit & Ort" segment, drop a trailing "Details" button label.
        if (!preg_match('/Uhr\s+(.*)$/u', $text, $m)) {
            return [null, null, null];
        }
        $location = trim($m[1]);
        $location = preg_replace('/\s*Details\s*$/u', '', $location) ?? $location;
        $location = trim($location);
        if ($location === '') {
            return [null, null, null];
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $location)), static fn (string $p): bool => $p !== ''));
        $venueName = $parts[0] ?? null;

        $city = $defaultCity;
        $last = end($parts);
        if (\is_string($last) && preg_match('/\d{5}\s+(.+)$/u', $last, $cm)) {
            $city = trim($cm[1]);
        }

        return [$venueName, $location, $city];
    }

    private function buildDescription(Crawler $event): ?string
    {
        $short = $this->text($event, '.eventdescshort');
        $details = $this->htmlToText($this->innerHtml($event, '.eventdesc.details'));
        // Strip the trailing "Zeit & Ort: ..." administrative line from the prose.
        $details = preg_replace('/Zeit\s*&?\s*Ort\s*:.*$/us', '', $details) ?? $details;
        $details = trim($details);

        $text = trim($short.' '.$details);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > 500) {
            $text = mb_substr($text, 0, 497).'...';
        }

        return $text;
    }

    private function text(Crawler $node, string $selector): string
    {
        try {
            $sub = $node->filter($selector);
        } catch (\Throwable) {
            return '';
        }
        if ($sub->count() === 0) {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', $sub->first()->text('')) ?? '');
    }

    private function innerHtml(Crawler $node, string $selector): string
    {
        try {
            $sub = $node->filter($selector);
        } catch (\Throwable) {
            return '';
        }

        return $sub->count() > 0 ? $sub->first()->html('') : '';
    }

    private function htmlToText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $html = preg_replace('/<br\s*\/?>/i', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /** Fetch a URL or throw, so a dead source surfaces as a failed run. */
    private function fetch(string $url): string
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => 30,
                'max_redirects' => 5,
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
}
