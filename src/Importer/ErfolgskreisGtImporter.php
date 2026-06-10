<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Importer for "Erfolgskreis GT" (https://www.erfolgskreis-gt.de/veranstaltungen/),
 * the joint events portal of the district of Gütersloh.
 *
 * The public TYPO3 page renders nothing server-side: the event list is a Vue
 * app embedded from pages.destination.one (the et4 / destination.one PAGES
 * product) that queries the structured REST backend at meta.et4.de/rest.ashx.
 *
 * We bypass the SPA and talk to that REST backend directly. The required
 * licensekey is a short-lived JWT (a few hours), so it MUST NOT be hardcoded.
 * Instead we fetch the PAGES bootstrap HTML on every run and scrape the fresh
 * token + experience id from its inline configuration, then page through the
 * JSON feed.
 *
 * Each REST item can carry several occurrences (timeIntervals); we emit one
 * {@see ImportedEvent} per occurrence so recurring events appear on each date.
 *
 * The feed already exposes a per-event image in media_objects, so we hotlink
 * that absolute (dam.destination.one) URL instead of fetching/rehosting it.
 *
 * Optional source config:
 *   - experience:   override the et4 experience id (default: erfolgskreis-gt)
 *   - licensekey:   override/static fallback token (discouraged, expires)
 *   - bootstrapUrl: override the PAGES URL used to scrape the token
 *   - city:         default city when an item carries no place
 *   - months:       look-ahead window in months (default: 12)
 *   - maxItems:     hard cap on fetched items (default: 1000)
 */
#[AutoconfigureTag('app.source_importer')]
final class ErfolgskreisGtImporter implements SourceImporter
{
    private const DEFAULT_EXPERIENCE = 'erfolgskreis-gt';
    private const DEFAULT_BOOTSTRAP = 'https://pages.destination.one/de/erfolgskreis-gt/default/search/Event/mode:next_months,12/sort:chronological';
    private const META_BASE = 'https://meta.et4.de/rest.ashx/search/';
    // The TYPO3 site has no server-rendered per-event page; the destination.one
    // PAGES app deep-links a single event via its query DSL (id:<event-id>).
    private const PAGES_DETAIL_BASE = 'https://pages.destination.one/de/%s/default/search/Event/id:%s';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)';
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
        return 'erfolgskreis_gt';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $experience = (string) ($config['experience'] ?? self::DEFAULT_EXPERIENCE);
        $defaultCity = (string) ($config['city'] ?? 'Kreis Gütersloh');
        $months = (int) ($config['months'] ?? 12);
        $maxItems = (int) ($config['maxItems'] ?? 1000);

        $licensekey = $this->resolveLicenseKey($config);
        if ($licensekey === null) {
            throw new \RuntimeException('Could not obtain a destination.one license key for erfolgskreis_gt.');
        }

        $tz = new \DateTimeZone('Europe/Berlin');
        $offset = 0;
        $fetched = 0;

        while ($fetched < $maxItems) {
            $items = $this->fetchPage($experience, $licensekey, $months, $offset);
            if ($items === []) {
                break;
            }

            foreach ($items as $item) {
                ++$fetched;
                yield from $this->mapItem($item, $experience, $defaultCity, $tz);
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
                'timeout' => 30,
            ])->getContent();

            // The Vue bootstrap embeds the token in the initFinder request block
            // and again as window.META_TOKEN.
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
     * Fetch one page of the REST feed.
     *
     * The first page (offset 0) is the primary fetch: if it fails the whole run
     * is worthless, so transport/HTTP errors there throw a {@see \RuntimeException}
     * with the URL. Follow-up pagination pages stay tolerant (return []) so a
     * single broken page can't discard the items already collected.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchPage(string $experience, string $licensekey, int $months, int $offset): array
    {
        $query = [
            'experience' => $experience,
            'licensekey' => $licensekey,
            'type' => 'Event',
            'template' => 'ET2014A.json',
            'q' => 'all:all -systag:has_abnormal_interval',
            'mode' => 'next_months,'.$months,
            'sort' => 'start asc',
            'offset' => $offset,
            'limit' => self::PAGE_SIZE,
        ];

        try {
            $response = $this->http->request('GET', self::META_BASE, [
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => 30,
                'query' => $query,
            ]);
            $status = $response->getStatusCode();
            $body = $status < 400 ? $response->getContent() : null;
        } catch (\Throwable $e) {
            if ($offset === 0) {
                throw new \RuntimeException(sprintf('Fetching %s failed: %s', self::META_BASE, $e->getMessage()), 0, $e);
            }

            return [];
        }
        if ($body === null) {
            if ($offset === 0) {
                throw new \RuntimeException(sprintf('Fetching %s failed: HTTP %d', self::META_BASE, $status));
            }

            return [];
        }

        $data = json_decode($body, true);
        if (!\is_array($data) || ($data['status'] ?? null) !== 'OK') {
            if ($offset === 0) {
                throw new \RuntimeException(sprintf('Fetching %s returned an unexpected payload (status %s).', self::META_BASE, \is_array($data) ? (string) ($data['status'] ?? 'missing') : 'non-JSON'));
            }

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
        if ($title === '') {
            return;
        }
        // Several feed titles end with a dangling " - "; tidy that up.
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
        if ($city === '') {
            $city = $defaultCity;
        }
        // Guard against address fragments / mojibake leaking into the city
        // (e.g. "Tö? 44a"): a real town has no digits or replacement chars.
        if (preg_match('/[0-9?\x{FFFD}]/u', $city) || mb_strlen($city) > 40) {
            $city = $defaultCity;
        }
        $locationText = $this->buildLocationText($venueName, $item, $address);
        $organizer = trim((string) ($address['name'] ?? '')) ?: null;

        foreach ($intervals as $interval) {
            if (!\is_array($interval)) {
                continue;
            }
            $start = $this->parseDate($interval['start'] ?? null, $tz);
            if ($start === null) {
                continue;
            }
            $end = $this->parseDate($interval['end'] ?? null, $tz);
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
        // Prefer the full details text over the short teaser so descriptions
        // aren't cut off mid-sentence; fall back to the teaser if there are no details.
        $text = $this->extractText($item, 'details') ?? $this->extractText($item, 'teaser');
        if ($text === null) {
            return null;
        }
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
        if ($text === '') {
            return null;
        }
        // Generous cap: full event descriptions fit, only truly huge ones are trimmed.
        if (mb_strlen($text) > 2000) {
            $text = mb_substr($text, 0, 1997).'...';
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
     * The event's "Veranstaltungsseite". The TYPO3 detail route 404s for every
     * event, so we deep-link the destination.one PAGES app to the single event
     * (always reachable, always the right event). The feed's organizer URL
     * ("web") is too unreliable to use as primary (dead hosts, bare homepages),
     * so it only serves as a last resort when no event id is available.
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
     * Hotlink the original event image from the feed's media_objects (no
     * extra request, no rehosting). Prefer the "default" relation, then the
     * highest-priority image/* object; URLs are already absolute.
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
     * Filters out junk links (e.g. a malformed "http://https//…/" homepage).
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

    /** @param mixed $value ISO-8601 string like 2026-09-18T18:30:00+02:00 */
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
