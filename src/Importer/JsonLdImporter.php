<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Generic schema.org Event importer driven by JSON-LD. Fetches the source URL,
 * extracts every `<script type="application/ld+json">` block and yields one
 * {@see ImportedEvent} per schema.org `Event` (incl. subtypes like Festival,
 * MusicEvent, TheaterEvent, …).
 *
 * Some sites only expose Event JSON-LD on the individual detail pages and ship
 * a bare listing. When the listing page carries no Event objects, the importer
 * discovers detail links from the HTML and fetches their JSON-LD (capped).
 *
 * Images are hotlinked (never downloaded/hosted): the schema.org `image` of the
 * event — already present in the loaded JSON-LD — is exposed as an absolute URL
 * on imageUrl. sourceUrl always points at the original detail page.
 *
 * Optional source config:
 *   - city:        default city when the JSON-LD location has no place
 *   - category:    default category slug
 *   - venue:       default venue name
 *   - linkPattern: regex (incl. delimiters) matching detail-page hrefs;
 *                  defaults to a heuristic looking for "event" in the path
 *   - maxDetails:  cap on detail pages to fetch (default 60)
 */
#[AutoconfigureTag('app.source_importer')]
final class JsonLdImporter implements SourceImporter
{
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)';
    private const DEFAULT_MAX_DETAILS = 60;

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'jsonld';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl();
        if (!$url) {
            throw new \RuntimeException('JSON-LD source has no URL.');
        }

        $config = $source->getConfig();
        $html = $this->fetch($url);

        $events = $this->extractEventObjects($html);
        $seenIds = [];

        // The listing page already carries Event JSON-LD: use it directly.
        if ($events !== []) {
            foreach ($events as $event) {
                $mapped = $this->mapEvent($event, $config, $url);
                if ($mapped !== null && $this->markSeen($mapped, $seenIds)) {
                    yield $mapped;
                }
            }

            return;
        }

        // Otherwise follow detail links and read their JSON-LD.
        $max = (int) ($config['maxDetails'] ?? self::DEFAULT_MAX_DETAILS);
        $count = 0;
        foreach ($this->discoverDetailLinks($html, $url, $config) as $detailUrl) {
            if ($count >= $max) {
                break;
            }
            ++$count;

            $detailHtml = $this->tryFetch($detailUrl);
            if ($detailHtml === null) {
                continue;
            }
            foreach ($this->extractEventObjects($detailHtml) as $event) {
                $mapped = $this->mapEvent($event, $config, $detailUrl);
                if ($mapped !== null && $this->markSeen($mapped, $seenIds)) {
                    yield $mapped;
                }
            }
        }
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

    /** Tolerant variant for detail pages: one broken page must not kill the run. */
    private function tryFetch(string $url): ?string
    {
        try {
            return $this->fetch($url);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * Extract all schema.org Event objects (incl. subtypes) from the JSON-LD
     * blocks in $html, flattening @graph and arrays.
     *
     * @return list<array<string, mixed>>
     */
    private function extractEventObjects(string $html): array
    {
        if (!preg_match_all(
            '#<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
            $html,
            $matches
        )) {
            return [];
        }

        $events = [];
        foreach ($matches[1] as $raw) {
            $decoded = $this->decodeJson($raw);
            if ($decoded === null) {
                continue;
            }
            $this->collectEvents($decoded, $events);
        }

        return $events;
    }

    private function decodeJson(string $raw): mixed
    {
        $raw = trim($raw);
        // Strip CDATA wrappers and HTML comments occasionally seen around JSON-LD.
        $raw = preg_replace('#^<!\[CDATA\[(.*)\]\]>$#s', '$1', $raw) ?? $raw;
        $raw = preg_replace('#^<!--(.*)-->$#s', '$1', $raw) ?? $raw;

        try {
            return json_decode($raw, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param list<array<mixed>> $out
     */
    private function collectEvents(mixed $node, array &$out): void
    {
        if (\is_array($node) && array_is_list($node)) {
            foreach ($node as $child) {
                $this->collectEvents($child, $out);
            }

            return;
        }

        if (!\is_array($node)) {
            return;
        }

        if ($this->isEventType($node['@type'] ?? null)) {
            $out[] = $node;
        }

        if (isset($node['@graph'])) {
            $this->collectEvents($node['@graph'], $out);
        }
    }

    private function isEventType(mixed $type): bool
    {
        foreach (\is_array($type) ? $type : [$type] as $candidate) {
            if (\is_string($candidate) && str_contains($candidate, 'Event')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $event
     */
    private function mapEvent(array $event, array $config, string $fallbackUrl): ?ImportedEvent
    {
        $title = $this->cleanText($this->str($event['name'] ?? ''));
        $startRaw = $this->str($event['startDate'] ?? '');
        if ($title === '' || $startRaw === '') {
            return null;
        }

        $start = $this->parseDate($startRaw);
        if ($start === null) {
            return null;
        }

        $allDay = !$this->hasTime($startRaw);
        $end = null;
        $endRaw = $this->str($event['endDate'] ?? '');
        if ($endRaw !== '') {
            $end = $this->parseDate($endRaw);
            // A date-only end equal to the start is multi-day filler; keep only
            // genuine ranges that move beyond the start moment.
            if ($end !== null && $end <= $start) {
                $end = null;
            }
        }

        [$venueName, $city, $locationText] = $this->mapLocation($event['location'] ?? null, $config);

        $sourceUrl = $this->str($event['url'] ?? '');
        if ($sourceUrl === '') {
            $sourceUrl = $this->offerUrl($event['offers'] ?? null);
        }
        if ($sourceUrl === '') {
            $sourceUrl = $fallbackUrl;
        }

        $description = $this->cleanText($this->str($event['description'] ?? ''));
        $description = $description !== '' ? $this->shorten($description) : null;

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: $end,
            allDay: $allDay,
            description: $description,
            venueName: $venueName,
            city: $city ?? ($config['city'] ?? null),
            locationText: $locationText,
            categorySlug: $config['category'] ?? null,
            sourceUrl: $sourceUrl,
            imageUrl: $this->mapImage($event['image'] ?? null, $fallbackUrl), // hotlink, never hosted
            price: $this->offerPrice($event['offers'] ?? null),
            organizer: $this->nameOf($event['organizer'] ?? null) ?: null,
            externalId: $sourceUrl !== $fallbackUrl ? $sourceUrl : ($sourceUrl.'#'.$start->format('Y-m-d')),
            raw: $event,
        );
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} [venueName, city, locationText]
     */
    private function mapLocation(mixed $location, array $config): array
    {
        if (\is_array($location) && array_is_list($location)) {
            $location = $location[0] ?? null;
        }
        if (!\is_array($location)) {
            return [$config['venue'] ?? null, null, null];
        }

        $name = $this->cleanText($this->str($location['name'] ?? ''));
        $name = $name !== '' ? $name : ($config['venue'] ?? null);

        $city = null;
        $address = $location['address'] ?? null;
        $addressText = '';
        if (\is_array($address)) {
            $city = $this->str($address['addressLocality'] ?? '') ?: null;
            $parts = array_filter([
                $this->str($address['streetAddress'] ?? ''),
                trim($this->str($address['postalCode'] ?? '').' '.$this->str($address['addressLocality'] ?? '')),
            ], static fn (string $p): bool => $p !== '');
            $addressText = implode(', ', $parts);
        } elseif (\is_string($address)) {
            $addressText = trim($address);
        }

        $locationText = trim(($name ?? '').($addressText !== '' ? ', '.$addressText : ''), ', ');

        return [$name, $city, $locationText !== '' ? $locationText : null];
    }

    /**
     * Resolve the schema.org `image` of an event to a single absolute URL.
     * Accepts a string URL, a list of those, or an ImageObject carrying
     * `url`/`contentUrl`. Relative URLs are resolved against the page URL.
     * No extra HTTP requests: the value is already part of the loaded JSON-LD.
     */
    private function mapImage(mixed $image, string $baseUrl): ?string
    {
        if (\is_array($image) && array_is_list($image)) {
            $image = $image[0] ?? null;
        }

        $candidate = '';
        if (\is_string($image)) {
            $candidate = trim($image);
        } elseif (\is_array($image)) {
            $candidate = $this->str($image['url'] ?? '') ?: $this->str($image['contentUrl'] ?? '');
        }

        if ($candidate === '') {
            return null;
        }

        return $this->absolutize($candidate, $baseUrl);
    }

    private function offerUrl(mixed $offers): string
    {
        foreach ($this->offerList($offers) as $offer) {
            $url = $this->str($offer['url'] ?? '');
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    private function offerPrice(mixed $offers): ?string
    {
        foreach ($this->offerList($offers) as $offer) {
            $price = $this->str($offer['price'] ?? '');
            if ($price === '') {
                continue;
            }
            $currency = $this->str($offer['priceCurrency'] ?? '');
            if (is_numeric($price) && (float) $price === 0.0) {
                return 'kostenlos';
            }
            $formatted = is_numeric($price) ? number_format((float) $price, 2, ',', '.') : $price;

            return $currency === 'EUR' ? $formatted.' €' : trim($formatted.' '.$currency);
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function offerList(mixed $offers): array
    {
        if (\is_array($offers) && array_is_list($offers)) {
            return array_values(array_filter($offers, '\is_array'));
        }
        if (\is_array($offers)) {
            return [$offers];
        }

        return [];
    }

    private function nameOf(mixed $node): string
    {
        if (\is_string($node)) {
            return $this->cleanText($node);
        }
        if (\is_array($node) && array_is_list($node)) {
            $node = $node[0] ?? null;
        }
        if (\is_array($node)) {
            return $this->cleanText($this->str($node['name'] ?? ''));
        }

        return '';
    }

    /**
     * Discover plausible event detail-page URLs from a listing page.
     *
     * @return list<string>
     */
    private function discoverDetailLinks(string $html, string $baseUrl, array $config): array
    {
        try {
            $crawler = new Crawler($html, $baseUrl);
        } catch (\Throwable) {
            return [];
        }

        $pattern = isset($config['linkPattern']) && \is_string($config['linkPattern'])
            ? $config['linkPattern']
            : '~/events?/[^?\#]+~i';

        $links = [];
        $crawler->filter('a[href]')->each(function (Crawler $node) use (&$links, $baseUrl, $pattern): void {
            $href = $node->attr('href');
            if ($href === null || $href === '') {
                return;
            }
            if (@preg_match($pattern, $href) !== 1) {
                return;
            }
            $abs = $this->absolutize($href, $baseUrl);
            if ($abs !== null) {
                $links[$abs] = true;
            }
        });

        return array_keys($links);
    }

    private function absolutize(string $href, string $baseUrl): ?string
    {
        $href = html_entity_decode($href, \ENT_QUOTES | \ENT_HTML5);
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $base = parse_url($baseUrl);
        if ($base === false || !isset($base['scheme'], $base['host'])) {
            return null;
        }
        $origin = $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }
        $path = $base['path'] ?? '/';
        $dir = substr($path, 0, strrpos($path, '/') ?: 0);

        return $origin.$dir.'/'.$href;
    }

    /**
     * @param array<string, true> $seenIds
     */
    private function markSeen(ImportedEvent $event, array &$seenIds): bool
    {
        $key = $event->dedupKey();
        if (isset($seenIds[$key])) {
            return false;
        }
        $seenIds[$key] = true;

        return true;
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $tz = new \DateTimeZone('Europe/Berlin');

        // ISO 8601 (with or without time/offset) is the schema.org default.
        if (preg_match('#^\d{4}-\d{2}-\d{2}#', $value)) {
            try {
                $date = new \DateTimeImmutable($value, $tz);

                return $this->hasTime($value) ? $date->setTimezone($tz) : $date->setTime(0, 0);
            } catch (\Throwable) {
                // fall through to German formats
            }
        }

        // German fallbacks for sites that put human dates into startDate.
        $normalized = $this->germanToIso($value);
        if ($normalized !== null) {
            try {
                return new \DateTimeImmutable($normalized, $tz);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function germanToIso(string $value): ?string
    {
        $months = [
            'januar' => '01', 'februar' => '02', 'märz' => '03', 'maerz' => '03',
            'april' => '04', 'mai' => '05', 'juni' => '06', 'juli' => '07',
            'august' => '08', 'september' => '09', 'oktober' => '10',
            'november' => '11', 'dezember' => '12',
        ];

        $time = '00:00';
        if (preg_match('#(\d{1,2})[:.](\d{2})\s*Uhr?#iu', $value, $tm)) {
            $time = sprintf('%02d:%s', (int) $tm[1], $tm[2]);
        }

        // "14.06.2026" / "14. 6. 2026"
        if (preg_match('#(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})#', $value, $m)) {
            return sprintf('%s-%02d-%02dT%s', $m[3], (int) $m[2], (int) $m[1], $time);
        }

        // "14. Juni 2026"
        if (preg_match('#(\d{1,2})\.?\s+([A-Za-zäöüÄÖÜ]+)\s+(\d{4})#u', $value, $m)) {
            $month = $months[mb_strtolower($m[2])] ?? null;
            if ($month !== null) {
                return sprintf('%s-%s-%02dT%s', $m[3], $month, (int) $m[1], $time);
            }
        }

        return null;
    }

    private function hasTime(string $value): bool
    {
        return (bool) preg_match('#\d{2}:\d{2}#', $value);
    }

    private function shorten(string $text, int $max = 600): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max);
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim($lastSpace !== false ? mb_substr($cut, 0, $lastSpace) : $cut).' …';
    }

    /**
     * Strip HTML/shortcode noise and collapse whitespace. Some CMSes dump raw
     * page-builder shortcodes into the description; drop those entirely.
     */
    private function cleanText(string $text): string
    {
        $text = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5);
        $text = preg_replace('#\[/?[a-z_]+[^\]]*\]#i', ' ', $text) ?? $text;
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
