<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Importer for the city of Schloß Holte-Stukenbrock's events, served through the
 * TEUTO_Navigator portal (https://www.teutonavigator.de/.../wlan/portal).
 *
 * That portal is a JavaScript-only SPA (a captive-WiFi landing page) with no
 * server-rendered event list, but it is powered by the destination.one / et4
 * platform and exposes the same structured REST backend as other et4 portals
 * at meta.et4.de/rest.ashx.
 *
 * We bypass the SPA and query that REST backend directly. The required
 * licensekey is a short-lived token (a few hours), so it MUST NOT be hardcoded.
 * On every run we fetch the portal bootstrap HTML and scrape the fresh token
 * from its inline `window.META_TOKEN = "…"`, then page through the JSON feed.
 *
 * Each REST item can carry several occurrences (timeIntervals), including past
 * ones for recurring series; we emit one {@see ImportedEvent} per *future*
 * occurrence so recurring events appear on each upcoming date.
 *
 * The feed already exposes a per-event image in media_objects, so we hotlink
 * that absolute (dam.destination.one) URL instead of fetching/rehosting it.
 *
 * Optional source config:
 *   - experience:   override the et4 experience id (default: schlossholtestukenbrock)
 *   - licensekey:   override/static fallback token (discouraged, expires)
 *   - bootstrapUrl: override the portal URL used to scrape the token
 *   - city:         default city (default: Schloß Holte-Stukenbrock)
 *   - months:       look-ahead window in months (default: 12)
 *   - maxItems:     hard cap on fetched items (default: 200)
 */
#[AutoconfigureTag('app.source_importer')]
final class StadtShsImporter implements SourceImporter
{
    private const DEFAULT_EXPERIENCE = 'schlossholtestukenbrock';
    private const DEFAULT_BOOTSTRAP = 'https://www.teutonavigator.de/de/schlossholtestukenbrock/wlan/portal';
    private const META_BASE = 'https://meta.et4.de/rest.ashx/search/';
    // The SPA deep-links a single event through its detail route; this always
    // resolves to the correct event (the feed's organizer "web" URL is too
    // unreliable to use as primary: dead hosts, bare homepages).
    private const PORTAL_DETAIL = 'https://www.teutonavigator.de/de/%s/wlan/portal/detail/%s';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.de)';
    private const BROWSER_UA = 'Mozilla/5.0';
    private const PAGE_SIZE = 100;

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
        'film' => 'buehne',
        'kino' => 'buehne',
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
        'beratung' => 'bildung',
        'festival/open-air' => 'musik',
        'brauchtum/kultur' => 'sonstiges',
        'sonstiges' => 'sonstiges',
    ];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'stadt_shs';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $experience = (string) ($config['experience'] ?? self::DEFAULT_EXPERIENCE);
        $defaultCity = (string) ($config['city'] ?? 'Schloß Holte-Stukenbrock');
        $months = (int) ($config['months'] ?? 12);
        $maxItems = (int) ($config['maxItems'] ?? 200);

        $licensekey = $this->resolveLicenseKey($config);
        if ($licensekey === null) {
            throw new \RuntimeException('Could not obtain a destination.one license key for stadt_shs.');
        }

        $tz = new \DateTimeZone('Europe/Berlin');
        $now = new \DateTimeImmutable('now', $tz);
        $offset = 0;
        $fetched = 0;

        while ($fetched < $maxItems) {
            $items = $this->fetchPage($experience, $licensekey, $months, $offset);
            if ($items === []) {
                break;
            }

            foreach ($items as $item) {
                ++$fetched;
                yield from $this->mapItem($item, $experience, $defaultCity, $tz, $now);
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
     * Prefer a fresh token scraped from the portal bootstrap; fall back to a
     * statically configured one only if scraping fails.
     */
    private function resolveLicenseKey(array $config): ?string
    {
        $bootstrapUrl = (string) ($config['bootstrapUrl'] ?? self::DEFAULT_BOOTSTRAP);

        try {
            // The portal serves the token only to a browser-like UA.
            $html = $this->http->request('GET', $bootstrapUrl, [
                'headers' => ['User-Agent' => self::BROWSER_UA],
                'timeout' => 20,
            ])->getContent();

            if (preg_match('/META_TOKEN\s*=\s*"([^"]+)"/', $html, $m)) {
                return $m[1];
            }
            if (preg_match('/"licensekey"\s*:\s*"([^"]+)"/', $html, $m)) {
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
    private function fetchPage(string $experience, string $licensekey, int $months, int $offset): array
    {
        try {
            $body = $this->http->request('GET', self::META_BASE, [
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => 20,
                'query' => [
                    'experience' => $experience,
                    'licensekey' => $licensekey,
                    'type' => 'Event',
                    'template' => 'ET2014A.json',
                    'mode' => 'next_months,'.$months,
                    'sort' => 'start asc',
                    'offset' => $offset,
                    'limit' => self::PAGE_SIZE,
                ],
            ])->getContent();
        } catch (\Throwable) {
            return [];
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
    private function mapItem(array $item, string $experience, string $defaultCity, \DateTimeZone $tz, \DateTimeImmutable $now): iterable
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

        $id = (string) ($item['id'] ?? '');
        $globalId = (string) ($item['global_id'] ?? '');
        $sourceUrl = $this->buildSourceUrl($globalId, $id, $experience, (string) ($item['web'] ?? ''));
        $description = $this->extractDescription($item);
        $price = $this->extractText($item, 'PRICE_INFO');
        $categorySlug = $this->mapCategory($item['categories'] ?? []);
        $imageUrl = $this->extractImageUrl($item['media_objects'] ?? null);

        $organizerAddress = $this->findAddress($item['addresses'] ?? null, 'organizer');
        $venueName = trim((string) ($item['name'] ?? '')) ?: null;
        $city = trim((string) ($item['city'] ?? ''));
        if ($city === '' || preg_match('/[0-9?\x{FFFD}]/u', $city) || mb_strlen($city) > 40) {
            $city = $defaultCity;
        }
        $locationText = $this->buildLocationText($venueName, $item);
        $organizer = trim((string) ($organizerAddress['name'] ?? '')) ?: null;

        // De-duplicate identical occurrence days within one item.
        $seenDays = [];

        foreach ($intervals as $interval) {
            if (!\is_array($interval)) {
                continue;
            }
            $start = $this->parseDate($interval['start'] ?? null, $tz);
            if ($start === null) {
                continue;
            }
            // Skip past occurrences; recurring series carry many old intervals.
            if ($start < $now) {
                continue;
            }
            $dayKey = $start->format('Y-m-d\TH:i');
            if (isset($seenDays[$dayKey])) {
                continue;
            }
            $seenDays[$dayKey] = true;

            $end = $this->parseDate($interval['end'] ?? null, $tz);
            $allDay = !str_contains((string) ($interval['start'] ?? ''), 'T')
                || ($start->format('H:i') === '00:00' && ($end === null || $end->format('H:i') === '00:00'));

            $externalId = $id !== ''
                ? $experience.':'.$id.':'.$dayKey
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
                    'global_id' => $globalId,
                    'categories' => $item['categories'] ?? [],
                    'web' => (string) ($item['web'] ?? ''),
                ],
            );
        }
    }

    /**
     * @param mixed $addresses
     *
     * @return array<string, mixed>
     */
    private function findAddress($addresses, string $rel): array
    {
        if (!\is_array($addresses)) {
            return [];
        }
        foreach ($addresses as $address) {
            if (\is_array($address) && ($address['rel'] ?? null) === $rel) {
                return $address;
            }
        }

        return [];
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

    /** @param array<string, mixed> $item */
    private function buildLocationText(?string $venueName, array $item): ?string
    {
        $parts = [];
        if ($venueName !== null) {
            $parts[] = $venueName;
        }
        $street = trim((string) ($item['street'] ?? ''));
        if ($street !== '') {
            $parts[] = $street;
        }
        $zip = trim((string) ($item['zip'] ?? ''));
        $cityPart = trim((string) ($item['city'] ?? ''));
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
     * The event's portal detail page; the SPA route always resolves to the
     * correct event. The feed's organizer URL ("web") only serves as a last
     * resort when no event id is available.
     */
    private function buildSourceUrl(string $globalId, string $id, string $experience, string $web): ?string
    {
        $slug = $globalId !== '' ? $globalId : ($id !== '' ? 'e_'.$id : '');
        if ($slug !== '') {
            return sprintf(self::PORTAL_DETAIL, rawurlencode($experience), rawurlencode($slug));
        }
        $web = trim($web);

        return $web !== '' && $this->isHttpUrl($web) ? $web : null;
    }

    /** A well-formed absolute http(s) URL with a dotted host. */
    private function isHttpUrl(string $url): bool
    {
        if (!preg_match('#^https?://#i', $url) || filter_var($url, \FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $host = parse_url($url, \PHP_URL_HOST);

        return \is_string($host) && str_contains($host, '.') && !str_contains($host, ':');
    }

    /**
     * Hotlink the original event image from the feed's media_objects. Prefer
     * the "default" relation, then the highest-priority image/* object.
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

    /** @param mixed $value ISO-8601 string like 2026-08-27T18:00:00+02:00 */
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
