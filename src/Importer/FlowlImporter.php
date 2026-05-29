<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Importer for flowl.de, a regional flea-market ("Flohmarkt") calendar built on
 * WordPress + "The Events Calendar" (tribe). We talk to the public tribe REST
 * API at /wp-json/tribe/events/v1/events instead of scraping HTML.
 *
 * The feed is paged (per_page/page) and returns structured JSON with
 * title/start_date/end_date/venue/url/image per event. Since every entry on
 * flowl.de is a flea market, all events map to the "markt" category.
 *
 * We restrict to the district of Gütersloh by matching the venue city against
 * the known Kreis Gütersloh municipalities (the upstream `search=Gütersloh`
 * query is fuzzy and also returns neighbouring towns).
 *
 * Legally safe: sourceUrl points at the original event permalink; imageUrl, if
 * present, hotlinks the original upload (never downloaded).
 *
 * Optional source config:
 *   - city:     default city when a venue carries no place (default "Kreis Gütersloh")
 *   - search:   override the upstream search term (default "Gütersloh")
 *   - perPage:  page size (default 50, capped at 50 by the API)
 *   - maxPages: hard cap on pages fetched (default 20)
 */
#[AutoconfigureTag('app.source_importer')]
final class FlowlImporter implements SourceImporter
{
    private const DEFAULT_BASE = 'https://flowl.de/wp-json/tribe/events/v1/events';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)';
    private const DEFAULT_PER_PAGE = 50;
    private const DEFAULT_MAX_PAGES = 20;

    /**
     * Municipalities of the district of Gütersloh (lowercased, no diacritics).
     * Venue cities are matched against these to keep only regional markets.
     */
    private const KREIS_GT_TOWNS = [
        'guetersloh', 'rheda', 'wiedenbrueck', 'verl', 'rietberg', 'halle',
        'borgholzhausen', 'werther', 'steinhagen', 'versmold', 'harsewinkel',
        'schloss holte', 'stukenbrock', 'langenberg', 'herzebrock', 'clarholz',
        'isselhorst', 'avenwedde', 'suerenheide', 'varensell', 'druffel',
        'batenhorst', 'hoerste', 'bokel', 'westbarthausen',
    ];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'flowl';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $base = $source->getUrl() ?: self::DEFAULT_BASE;
        // Strip any query string from the configured URL; we build it ourselves.
        $base = strtok($base, '?') ?: self::DEFAULT_BASE;

        $defaultCity = (string) ($config['city'] ?? 'Kreis Gütersloh');
        $search = (string) ($config['search'] ?? 'Gütersloh');
        $perPage = max(1, min(50, (int) ($config['perPage'] ?? self::DEFAULT_PER_PAGE)));
        $maxPages = max(1, (int) ($config['maxPages'] ?? self::DEFAULT_MAX_PAGES));

        $tz = new \DateTimeZone('Europe/Berlin');
        $seen = [];

        for ($page = 1; $page <= $maxPages; ++$page) {
            $data = $this->fetchPage($base, $search, $perPage, $page);
            if ($data === null) {
                break;
            }

            $events = $data['events'] ?? [];
            if (!\is_array($events) || $events === []) {
                break;
            }

            foreach ($events as $event) {
                if (!\is_array($event)) {
                    continue;
                }
                $mapped = $this->mapEvent($event, $defaultCity, $tz);
                if ($mapped === null) {
                    continue;
                }
                $key = $mapped->dedupKey();
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                yield $mapped;
            }

            $totalPages = (int) ($data['total_pages'] ?? 0);
            if ($totalPages > 0 && $page >= $totalPages) {
                break;
            }
            if (\count($events) < $perPage) {
                break;
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPage(string $base, string $search, int $perPage, int $page): ?array
    {
        try {
            $response = $this->http->request('GET', $base, [
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => 30,
                'max_redirects' => 5,
                'query' => [
                    'per_page' => $perPage,
                    'page' => $page,
                    'search' => $search,
                ],
            ]);

            if ($response->getStatusCode() >= 400) {
                return null;
            }

            $data = json_decode($response->getContent(), true);
        } catch (\Throwable) {
            return null;
        }

        return \is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function mapEvent(array $event, string $defaultCity, \DateTimeZone $tz): ?ImportedEvent
    {
        $title = $this->cleanText($this->str($event['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $start = $this->parseDate($this->str($event['start_date'] ?? ''), $tz);
        if ($start === null) {
            return null;
        }

        $venue = \is_array($event['venue'] ?? null) ? $event['venue'] : [];
        $venueCity = $this->str($venue['city'] ?? '');
        if (!$this->isKreisGt($venueCity)) {
            return null;
        }

        $end = $this->parseDate($this->str($event['end_date'] ?? ''), $tz);
        if ($end !== null && $end <= $start) {
            $end = null;
        }

        $allDay = (bool) ($event['all_day'] ?? false);

        $venueName = $this->cleanText($this->str($venue['venue'] ?? '')) ?: null;
        $city = $venueCity !== '' ? $venueCity : $defaultCity;
        $locationText = $this->buildLocationText($venueName, $venue);

        $sourceUrl = $this->str($event['url'] ?? '') ?: null;
        $externalId = $this->str($event['id'] ?? '') ?: ($this->str($event['global_id'] ?? '') ?: $sourceUrl);

        $description = $this->cleanText($this->str($event['excerpt'] ?? '') ?: $this->str($event['description'] ?? ''));
        $description = $description !== '' ? $this->shorten($description) : null;

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: $end,
            allDay: $allDay,
            description: $description,
            venueName: $venueName,
            city: $city,
            locationText: $locationText,
            categorySlug: 'markt',
            sourceUrl: $sourceUrl,
            imageUrl: $this->extractImage($event['image'] ?? null),
            price: null,
            organizer: null,
            externalId: $externalId,
            raw: [
                'id' => $this->str($event['id'] ?? ''),
                'global_id' => $this->str($event['global_id'] ?? ''),
                'slug' => $this->str($event['slug'] ?? ''),
            ],
        );
    }

    /**
     * Match a venue city against the Kreis Gütersloh municipalities. Cities may
     * carry suffixes/quarters ("Rietberg-Druffel", "Halle/ Westfalen"), so we
     * test for any known town as a substring of the normalized city.
     */
    private function isKreisGt(string $city): bool
    {
        if ($city === '') {
            return false;
        }
        $normalized = $this->normalizeCity($city);
        foreach (self::KREIS_GT_TOWNS as $town) {
            if (str_contains($normalized, str_replace(' ', '', $town))) {
                return true;
            }
        }

        return false;
    }

    private function normalizeCity(string $city): string
    {
        $city = mb_strtolower($city);
        $city = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $city);

        return preg_replace('/[^a-z0-9]+/', '', $city) ?? '';
    }

    /**
     * @param array<string, mixed> $venue
     */
    private function buildLocationText(?string $venueName, array $venue): ?string
    {
        $parts = [];
        if ($venueName !== null) {
            $parts[] = $venueName;
        }
        $address = $this->cleanText($this->str($venue['address'] ?? ''));
        if ($address !== '') {
            $parts[] = $address;
        }
        $zip = $this->str($venue['zip'] ?? '');
        $cityPart = $this->cleanText($this->str($venue['city'] ?? ''));
        $line = trim($zip.' '.$cityPart);
        if ($line !== '') {
            $parts[] = $line;
        }

        $parts = array_values(array_unique(array_filter($parts)));

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * The tribe API returns `false` when no image is set, otherwise an object
     * with an absolute `url`. Hotlink the original; never download.
     */
    private function extractImage(mixed $image): ?string
    {
        if (!\is_array($image)) {
            return null;
        }
        $url = $this->str($image['url'] ?? '');

        return preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }

    private function parseDate(string $value, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            // tribe emits "Y-m-d H:i:s" in the event's local timezone.
            return new \DateTimeImmutable($value, $tz);
        } catch (\Throwable) {
            return null;
        }
    }

    private function shorten(string $text, int $max = 400): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim($lastSpace !== false ? mb_substr($cut, 0, $lastSpace) : $cut).' …';
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5);
        $text = strip_tags($text);
        $text = preg_replace('#\s+#u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function str(mixed $value): string
    {
        if (\is_string($value)) {
            return trim($value);
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        return '';
    }
}
