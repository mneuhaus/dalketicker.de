<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Importer for the Borgholzhausen events portal.
 *
 * Like the district-wide "Erfolgskreis GT" portal this is a destination.one /
 * et4 PAGES app over the shared meta.et4.de REST backend; only the experience
 * id ("borgholzhausen") and the city filter differ. We bypass the Vue SPA and
 * query the REST backend directly.
 *
 * The required licensekey is a short-lived JWT (it carries an `exp` claim, the
 * sample expired within a day), so it MUST NOT be hardcoded. On every run we
 * fetch the PAGES bootstrap HTML and scrape the fresh token from its inline
 * configuration, then page through the JSON feed.
 *
 * Each REST item can carry several occurrences (timeIntervals); we emit one
 * {@see ImportedEvent} per occurrence so recurring events appear on each date.
 *
 * Optional source config:
 *   - experience:   override the et4 experience id (default: borgholzhausen)
 *   - cityFilter:   override the city: query filter (default: borgholzhausen)
 *   - licensekey:   override/static fallback token (discouraged, expires)
 *   - bootstrapUrl: override the PAGES URL used to scrape the token
 *   - city:         default city when an item carries no place
 *   - months:       look-ahead window in months (default: 24)
 *   - maxItems:     hard cap on fetched items (default: 200)
 */
#[AutoconfigureTag('app.source_importer')]
final class BibBorgholzhausenImporter implements SourceImporter
{
    private const DEFAULT_EXPERIENCE = 'borgholzhausen';
    private const DEFAULT_CITY_FILTER = 'borgholzhausen';
    private const DEFAULT_CITY = 'Borgholzhausen';
    private const DEFAULT_BOOTSTRAP = 'https://pages.destination.one/de/borgholzhausen/default_withmap/search/Event/city:%22borgholzhausen%22/mode:next_months,24/sort:chronological/view:map,half';
    private const META_BASE = 'https://meta.et4.de/rest.ashx/search/';
    // The destination.one PAGES app deep-links a single event via its query DSL.
    private const PAGES_DETAIL_BASE = 'https://pages.destination.one/de/%s/default_withmap/search/Event/id:%s';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)';
    private const PAGE_SIZE = 50;
    private const DEFAULT_MONTHS = 24;
    private const DEFAULT_MAX_ITEMS = 200;

    /** et4 category name (lowercased) => internal slug. */
    private const CATEGORY_MAP = [
        'konzert divers' => 'musik',
        'konzert' => 'musik',
        'musik' => 'musik',
        'party' => 'party',
        'bühne divers' => 'buehne',
        'theater' => 'buehne',
        'kabarett' => 'buehne',
        'comedy' => 'buehne',
        'lesung' => 'buehne',
        'ausstellung' => 'kunst',
        'kunst' => 'kunst',
        'familie und kinder' => 'familie',
        'familie' => 'familie',
        'kinder' => 'familie',
        'sport' => 'sport',
        'wanderung' => 'sport',
        'markt' => 'markt',
        'gastronomie' => 'genuss',
        'genuss/gourmet' => 'genuss',
        'genuss' => 'genuss',
        'führung/besichtigung' => 'bildung',
        'ausflug/exkursion' => 'bildung',
        'vortrag' => 'bildung',
        'seminar' => 'bildung',
        'brauchtum/kultur' => 'sonstiges',
        'sonstiges' => 'sonstiges',
    ];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'bib_borgholzhausen';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $experience = (string) ($config['experience'] ?? self::DEFAULT_EXPERIENCE);
        $cityFilter = (string) ($config['cityFilter'] ?? self::DEFAULT_CITY_FILTER);
        $defaultCity = (string) ($config['city'] ?? self::DEFAULT_CITY);
        $months = (int) ($config['months'] ?? self::DEFAULT_MONTHS);
        $maxItems = (int) ($config['maxItems'] ?? self::DEFAULT_MAX_ITEMS);

        $licensekey = $this->resolveLicenseKey($config);
        if ($licensekey === null) {
            throw new \RuntimeException('Could not obtain a destination.one license key for bib_borgholzhausen.');
        }

        $tz = new \DateTimeZone('Europe/Berlin');
        $offset = 0;
        $fetched = 0;

        while ($fetched < $maxItems) {
            $items = $this->fetchPage($experience, $cityFilter, $licensekey, $months, $offset);
            if ($items === []) {
                break;
            }

            foreach ($items as $item) {
                ++$fetched;
                yield from $this->mapItem($item, $experience, $defaultCity, $tz);
                if ($fetched >= $maxItems) {
                    break;
                }
            }

            if (\count($items) < self::PAGE_SIZE) {
                break;
            }
            $offset += self::PAGE_SIZE;
        }
    }

    /**
     * Prefer a fresh token scraped from the PAGES bootstrap; fall back to a
     * statically configured one only if scraping fails.
     */
    private function resolveLicenseKey(array $config): ?string
    {
        $bootstrapUrl = (string) ($config['bootstrapUrl'] ?? self::DEFAULT_BOOTSTRAP);

        try {
            $html = $this->http->request('GET', $bootstrapUrl, [
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => 20,
            ])->getContent();

            if (preg_match('/"licensekey"\s*:\s*"([^"]+)"/', $html, $m)) {
                return $m[1];
            }
            if (preg_match('/META_TOKEN\s*=\s*"([^"]+)"/', $html, $m)) {
                return $m[1];
            }
        } catch (\Throwable) {
            // fall through to static fallback
        }

        $static = $config['licensekey'] ?? null;

        return \is_string($static) && $static !== '' ? $static : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchPage(string $experience, string $cityFilter, string $licensekey, int $months, int $offset): array
    {
        $query = [
            'experience' => $experience,
            'licensekey' => $licensekey,
            'type' => 'Event',
            'template' => 'ET2014A.json',
            'q' => 'city:"'.$cityFilter.'"',
            'mode' => 'next_months,'.$months,
            'sort' => 'chronological',
            'offset' => $offset,
            'limit' => self::PAGE_SIZE,
        ];

        try {
            $response = $this->http->request('GET', self::META_BASE, [
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => 20,
                'query' => $query,
            ]);
            $status = $response->getStatusCode();
            $body = $status < 400 ? $response->getContent() : null;
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Fetching %s (offset %d) failed: %s', self::META_BASE, $offset, $e->getMessage()), 0, $e);
        }
        if ($body === null) {
            throw new \RuntimeException(sprintf('Fetching %s (offset %d) failed: HTTP %d', self::META_BASE, $offset, $status));
        }

        $data = json_decode($body, true);
        if (!\is_array($data) || ($data['status'] ?? null) !== 'OK') {
            return [];
        }

        $items = $data['items'] ?? [];

        return \is_array($items) ? $items : [];
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return iterable<ImportedEvent>
     */
    private function mapItem(array $item, string $experience, string $defaultCity, \DateTimeZone $tz): iterable
    {
        $title = trim((string) ($item['title'] ?? ''));
        $title = trim(rtrim($title, " -\t"));
        if ($title === '') {
            return;
        }

        $intervals = $item['timeIntervals'] ?? [];
        if (!\is_array($intervals) || $intervals === []) {
            return;
        }

        $id = (string) ($item['id'] ?? $item['global_id'] ?? '');
        $sourceUrl = $this->buildSourceUrl($id, $experience, (string) ($item['web'] ?? ''));
        if ($sourceUrl === null) {
            return;
        }
        $description = $this->extractDescription($item);
        $price = $this->extractText($item, 'PRICE_INFO');
        $categorySlug = $this->mapCategory($item['categories'] ?? []);
        $imageUrl = $this->extractImageUrl($item['media_objects'] ?? null);

        $address = (\is_array($item['addresses'] ?? null) && isset($item['addresses'][0]) && \is_array($item['addresses'][0]))
            ? $item['addresses'][0]
            : [];
        $venueName = trim((string) ($item['name'] ?? '')) ?: null;
        $city = trim((string) ($item['city'] ?? ''));
        if ($city === '') {
            $city = trim((string) ($address['city'] ?? ''));
        }
        if ($city === '' || preg_match('/[0-9?\x{FFFD}]/u', $city) || mb_strlen($city) > 40) {
            $city = $defaultCity;
        }
        $locationText = $this->buildLocationText($venueName, $item, $address);
        $organizer = trim((string) ($address['name'] ?? '')) ?: null;

        $now = new \DateTimeImmutable('now', $tz);

        foreach ($intervals as $interval) {
            if (!\is_array($interval)) {
                continue;
            }
            $start = $this->parseDate($interval['start'] ?? null, $tz);
            if ($start === null) {
                continue;
            }
            $end = $this->parseDate($interval['end'] ?? null, $tz);
            // Skip occurrences clearly in the past (defensive: feed may include them).
            if (($end ?? $start) < $now->modify('-1 day')) {
                continue;
            }
            $allDay = !str_contains((string) ($interval['start'] ?? ''), 'T')
                || ($start->format('H:i') === '00:00' && ($end === null || $end->format('H:i') === '00:00'));

            $externalId = $id !== ''
                ? $experience.':'.$id.':'.$start->format('Y-m-d\TH:i')
                : null;

            yield new ImportedEvent(
                title: $title,
                startsAt: $start,
                endsAt: $end,
                allDay: $allDay,
                description: $description,
                venueName: $venueName,
                city: $city,
                locationText: $locationText,
                categorySlug: $categorySlug,
                sourceUrl: $sourceUrl,
                imageUrl: $imageUrl,
                price: $price,
                organizer: $organizer,
                externalId: $externalId,
                raw: [
                    'id' => $id,
                    'global_id' => (string) ($item['global_id'] ?? ''),
                    'categories' => $item['categories'] ?? [],
                    'web' => (string) ($item['web'] ?? ''),
                ],
            );
        }
    }

    /** @param array<string, mixed> $item */
    private function extractDescription(array $item): ?string
    {
        $text = $this->extractText($item, 'teaser') ?? $this->extractText($item, 'details');
        if ($text === null) {
            return null;
        }
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > 400) {
            $text = mb_substr($text, 0, 397).'...';
        }

        return $text;
    }

    /**
     * Returns the first plain-text value for the given text relation.
     *
     * @param array<string, mixed> $item
     */
    private function extractText(array $item, string $rel): ?string
    {
        $texts = $item['texts'] ?? [];
        if (!\is_array($texts)) {
            return null;
        }
        $htmlFallback = null;
        foreach ($texts as $t) {
            if (!\is_array($t) || ($t['rel'] ?? null) !== $rel) {
                continue;
            }
            $value = trim((string) ($t['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            if (($t['type'] ?? null) === 'text/plain') {
                return $value;
            }
            $htmlFallback ??= $value;
        }

        return $htmlFallback;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $address
     */
    private function buildLocationText(?string $venueName, array $item, array $address): ?string
    {
        $parts = [];
        if ($venueName !== null) {
            $parts[] = $venueName;
        }
        $street = trim((string) ($item['street'] ?? $address['street'] ?? ''));
        if ($street !== '') {
            $parts[] = $street;
        }
        $zip = trim((string) ($item['zip'] ?? $address['zip'] ?? ''));
        $cityPart = trim((string) ($item['city'] ?? $address['city'] ?? ''));
        $line = trim($zip.' '.$cityPart);
        if ($line !== '') {
            $parts[] = $line;
        }

        $parts = array_values(array_unique(array_filter($parts)));

        return $parts === [] ? null : implode(', ', $parts);
    }

    /** @param mixed $categories */
    private function mapCategory($categories): ?string
    {
        if (!\is_array($categories)) {
            return null;
        }
        foreach ($categories as $cat) {
            $key = mb_strtolower(trim((string) $cat));
            if ($key !== '' && isset(self::CATEGORY_MAP[$key])) {
                return self::CATEGORY_MAP[$key];
            }
        }

        return null;
    }

    /**
     * The event's detail page. The destination.one PAGES app deep-links a single
     * event via its query DSL (id:<event-id>), which always resolves. The feed's
     * organizer URL ("web") is too unreliable to use as primary, so it only
     * serves as a last resort when no event id is available.
     */
    private function buildSourceUrl(string $id, string $experience, string $web): ?string
    {
        if ($id !== '') {
            return sprintf(self::PAGES_DETAIL_BASE, rawurlencode($experience), rawurlencode($id));
        }
        $web = trim($web);

        return $web !== '' && $this->isHttpUrl($web) ? $web : null;
    }

    /** A well-formed absolute http(s) URL with a dotted host (rejects mojibake/double-scheme). */
    private function isHttpUrl(string $url): bool
    {
        if (!preg_match('#^https?://#i', $url) || filter_var($url, \FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $host = parse_url($url, \PHP_URL_HOST);

        return \is_string($host) && str_contains($host, '.') && !str_contains($host, ':');
    }

    /**
     * Hotlink the original event image from the feed's media_objects.
     *
     * @param mixed $mediaObjects
     */
    private function extractImageUrl($mediaObjects): ?string
    {
        if (!\is_array($mediaObjects)) {
            return null;
        }

        $best = null;
        $bestPrio = \PHP_INT_MIN;
        foreach ($mediaObjects as $media) {
            if (!\is_array($media)) {
                continue;
            }
            $url = trim((string) ($media['url'] ?? ''));
            if (!$this->isUsableImageUrl($url, (string) ($media['type'] ?? ''))) {
                continue;
            }
            if (($media['rel'] ?? null) === 'default') {
                return $url;
            }
            $prio = (int) ($media['prio'] ?? 0);
            if ($best === null || $prio > $bestPrio) {
                $best = $url;
                $bestPrio = $prio;
            }
        }

        return $best;
    }

    /**
     * A media object only counts as an image if it is a well-formed absolute
     * URL that is either declared image/* or ends in a known image extension.
     */
    private function isUsableImageUrl(string $url, string $type): bool
    {
        if ($url === '' || !$this->isHttpUrl($url)) {
            return false;
        }
        if ($type !== '') {
            return str_starts_with($type, 'image/');
        }

        return (bool) preg_match('~\.(jpe?g|png|webp|gif|avif)(\?|#|$)~i', $url);
    }

    /** @param mixed $value ISO-8601 string like 2026-09-26T19:30:00+02:00 */
    private function parseDate($value, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->setTimezone($tz);
        } catch (\Exception) {
            return null;
        }
    }
}
