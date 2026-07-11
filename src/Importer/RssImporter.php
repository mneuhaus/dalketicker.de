<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Generic RSS 2.0 / Atom feed importer. Fetches the feed at
 * {@see Source::getUrl()} and yields one {@see ImportedEvent} per item/entry.
 *
 * Feeds are not really event calendars, so dates are derived best-effort:
 *   1. an explicit "Datum: DD.MM.YY[YY], HH:MM" line inside the description,
 *   2. a German date (and optional time) parsed from the title or description,
 *   3. the publication date (pubDate / atom:published) as a last resort.
 *
 * If the feed item carries an image in its already-loaded markup (Media RSS
 * media:content / media:thumbnail, an image <enclosure>, or the first <img> in
 * the content/description HTML), it is hotlinked as imageUrl (the original URL,
 * never downloaded or re-hosted). Relative image URLs are resolved against the
 * item link / feed URL. No extra detail requests are made; imageUrl stays null
 * when the item has no usable image. The item link is always used as the
 * sourceUrl pointing to the original page.
 *
 * Optional source config:
 *   - city:        default city for every event
 *   - venue:       default venue name when none can be derived
 *   - category:    default category slug (must be one of the allowed slugs)
 *   - categoryMap: map of lower-cased feed <category> labels => category slug
 */
#[AutoconfigureTag('app.source_importer')]
final class RssImporter implements SourceImporter
{
    private const TZ = 'Europe/Berlin';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)';

    /** German month names (and common abbreviations) => month number. */
    private const MONTHS = [
        'januar' => 1, 'jan' => 1,
        'februar' => 2, 'feb' => 2,
        'marz' => 3, 'maerz' => 3, 'mar' => 3, 'mrz' => 3,
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
        return 'rss';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl();
        if (!$url) {
            throw new \RuntimeException('RSS source has no URL.');
        }

        $body = $this->http->request('GET', $url, [
            'headers' => ['User-Agent' => self::USER_AGENT],
            'timeout' => 30,
        ])->getContent();

        $xml = $this->parseXml($body);
        if ($xml === null) {
            // Fail loudly: a silently empty run would count as healthy and
            // eventually let the prune pass hide all events of this source.
            throw new \RuntimeException(sprintf('RSS feed %s could not be parsed.', $url));
        }

        $config = $source->getConfig();
        foreach ($this->items($xml) as $item) {
            $event = $this->mapItem($item, $config, $url);
            if ($event !== null) {
                yield $event;
            }
        }
    }

    private function parseXml(string $body): ?\SimpleXMLElement
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $xml ?: null;
    }

    /**
     * Normalizes RSS <item> and Atom <entry> into a flat field array.
     *
     * @return iterable<array{title: string, link: string, description: string, categories: list<string>, published: ?string, image: ?string}>
     */
    private function items(\SimpleXMLElement $xml): iterable
    {
        // RSS 2.0: rss/channel/item, RDF: RDF/item, Atom: feed/entry.
        $nodes = $xml->channel->item ?? $xml->item ?? $xml->entry ?? null;
        if ($nodes === null) {
            return;
        }

        foreach ($nodes as $node) {
            yield $this->normalizeNode($node);
        }
    }

    /**
     * @return array{title: string, link: string, description: string, categories: list<string>, published: ?string, image: ?string}
     */
    private function normalizeNode(\SimpleXMLElement $node): array
    {
        $title = trim((string) ($node->title ?? ''));
        $link = $this->extractLink($node);

        // RSS description / WordPress content:encoded / Atom summary+content.
        $content = trim((string) ($node->children('content', true)->encoded ?? ''));
        $description = trim((string) ($node->description ?? ''));
        $summary = trim((string) ($node->summary ?? ''));
        $atomContent = trim((string) ($node->content ?? ''));
        $description = $description ?: $summary;
        // Prefer the richer body for date mining, keep both for the raw payload.
        $body = $content ?: $atomContent ?: $description;

        $categories = [];
        foreach ($node->category as $cat) {
            $label = trim((string) $cat);
            if ($label !== '') {
                $categories[] = $label;
            }
        }

        $published = $this->firstNonEmpty([
            (string) ($node->pubDate ?? ''),
            (string) ($node->published ?? ''),
            (string) ($node->updated ?? ''),
            (string) $node->children('dc', true)->date,
        ]);

        return [
            'title' => $title,
            'link' => $link,
            'description' => $body,
            'categories' => $categories,
            'published' => $published,
            'image' => $this->extractImage($node, $body),
        ];
    }

    /**
     * Pulls a single image URL from the item's already-loaded markup, in order
     * of decreasing reliability: Media RSS media:content / media:thumbnail, an
     * image <enclosure>, then the first <img src> in the content/description
     * HTML. No extra HTTP requests are made. The returned URL may be relative;
     * it is resolved against the item link / feed URL in {@see mapItem()}.
     */
    private function extractImage(\SimpleXMLElement $node, string $body): ?string
    {
        $media = $node->children('media', true);

        // Media RSS <media:content url="..." medium="image" type="image/...">.
        foreach ($media->content as $content) {
            $medium = (string) $content['medium'];
            $type = (string) $content['type'];
            if ($medium === 'image' || str_starts_with($type, 'image/') || ($medium === '' && $type === '')) {
                $url = trim((string) $content['url']);
                if ($url !== '') {
                    return $url;
                }
            }
        }

        // Media RSS <media:thumbnail url="...">.
        foreach ($media->thumbnail as $thumb) {
            $url = trim((string) $thumb['url']);
            if ($url !== '') {
                return $url;
            }
        }

        // RSS <enclosure url="..." type="image/...">.
        foreach ($node->enclosure as $enclosure) {
            $type = (string) $enclosure['type'];
            $url = trim((string) $enclosure['url']);
            if ($url !== '' && ($type === '' ? $this->looksLikeImageUrl($url) : str_starts_with($type, 'image/'))) {
                return $url;
            }
        }

        // First <img src> inside the content/description HTML.
        if ($body !== '' && preg_match('/<img\b[^>]*?\bsrc\s*=\s*["\']([^"\']+)["\']/i', $body, $m)) {
            $url = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($url !== '') {
                return $url;
            }
        }

        return null;
    }

    private function looksLikeImageUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        return \is_string($path) && preg_match('/\.(jpe?g|png|gif|webp|avif)$/i', $path) === 1;
    }

    private function extractLink(\SimpleXMLElement $node): string
    {
        $link = trim((string) ($node->link ?? ''));
        if ($link !== '') {
            return $link;
        }

        // Atom: <link href="..." rel="alternate"/>; pick alternate or first.
        $fallback = '';
        foreach ($node->link as $l) {
            $href = trim((string) $l['href']);
            if ($href === '') {
                continue;
            }
            $rel = (string) $l['rel'];
            if ($rel === '' || $rel === 'alternate') {
                return $href;
            }
            $fallback = $fallback ?: $href;
        }

        return $fallback;
    }

    /**
     * @param array{title: string, link: string, description: string, categories: list<string>, published: ?string, image: ?string} $item
     */
    private function mapItem(array $item, array $config, string $feedUrl): ?ImportedEvent
    {
        $title = $this->cleanTitle($item['title']);
        if ($title === '') {
            return null;
        }

        $descriptionText = $this->htmlToText($item['description']);
        $haystack = $item['title']."\n".$descriptionText;

        [$start, $end] = $this->deriveDates($haystack);

        $usedFallback = false;
        if ($start === null) {
            $start = $this->parsePublished($item['published']);
            $usedFallback = true;
        }
        if ($start === null) {
            // No usable date at all -> not an event we can place.
            return null;
        }

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: $usedFallback ? null : $end,
            allDay: false,
            description: $this->shorten($descriptionText),
            venueName: $this->deriveVenue($descriptionText) ?? ($config['venue'] ?? null),
            city: $config['city'] ?? null,
            locationText: $this->deriveVenue($descriptionText),
            categorySlug: $this->mapCategory($item['categories'], $config),
            sourceUrl: $item['link'] !== '' ? $item['link'] : null,
            imageUrl: $this->resolveImage($item['image'], $item['link'], $feedUrl),
            externalId: $item['link'] !== '' ? $item['link'] : null,
            raw: [
                'title' => $item['title'],
                'link' => $item['link'],
                'categories' => implode(', ', $item['categories']),
                'published' => $item['published'] ?? '',
                'derivedFromPubDate' => $usedFallback ? '1' : '0',
            ],
        );
    }

    /**
     * Resolves the (possibly relative) image URL to an absolute hotlink URL.
     * Already-absolute http(s) and protocol-relative URLs are passed through;
     * relative ones are resolved against the item link, falling back to the
     * feed URL. Returns null when no usable absolute URL can be formed.
     */
    private function resolveImage(?string $image, string $itemLink, string $feedUrl): ?string
    {
        $image = trim((string) $image);
        if ($image === '') {
            return null;
        }

        if (str_starts_with($image, '//')) {
            $scheme = parse_url($itemLink !== '' ? $itemLink : $feedUrl, PHP_URL_SCHEME);

            return (\is_string($scheme) && $scheme !== '' ? $scheme : 'https').':'.$image;
        }

        $scheme = parse_url($image, PHP_URL_SCHEME);
        if ($scheme === 'http' || $scheme === 'https') {
            return $image;
        }
        // Reject non-http schemes (data:, javascript:, ...).
        if (\is_string($scheme) && $scheme !== '') {
            return null;
        }

        $base = $itemLink !== '' ? $itemLink : $feedUrl;

        return $this->resolveRelativeUrl($image, $base);
    }

    /** Resolves a relative URL against an absolute base URL (RFC 3986-ish). */
    private function resolveRelativeUrl(string $relative, string $base): ?string
    {
        $parts = parse_url($base);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($relative, '/')) {
            return $origin.$relative;
        }

        $basePath = $parts['path'] ?? '/';
        $dir = str_contains($basePath, '/') ? substr($basePath, 0, strrpos($basePath, '/') + 1) : '/';

        return $origin.$this->normalizePath($dir.$relative);
    }

    /** Collapses "." and ".." segments in a path. */
    private function normalizePath(string $path): string
    {
        $segments = explode('/', $path);
        $out = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }

        return '/'.implode('/', $out);
    }

    /** Strips a trailing " - DD.MM.YY[YY], HH:MM" date suffix from the title. */
    private function cleanTitle(string $title): string
    {
        $title = preg_replace('/\s*[-–—|]\s*\d{1,2}\.\d{1,2}\.\d{2,4}(,?\s*\d{1,2}[:.]\d{2})?\s*$/u', '', $title) ?? $title;

        return trim($title);
    }

    /**
     * Derives [start, end] DateTimeImmutable from free text. Returns
     * [null, null] when no German date can be found.
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function deriveDates(string $text): array
    {
        $tz = new \DateTimeZone(self::TZ);

        // Strategy 1: explicit "Datum: DD.MM.YY[YY], HH:MM - DD.MM.YY[YY], HH:MM".
        if (preg_match('/Datum:\s*(\d{1,2}\.\d{1,2}\.\d{2,4})(?:,?\s*(\d{1,2})[:.](\d{2}))?(?:\s*[-–]\s*(\d{1,2}\.\d{1,2}\.\d{2,4})(?:,?\s*(\d{1,2})[:.](\d{2}))?)?/u', $text, $m)) {
            $start = $this->makeNumericDate($m[1], $m[2] ?? null, $m[3] ?? null, $tz);
            $end = isset($m[4]) && $m[4] !== ''
                ? $this->makeNumericDate($m[4], $m[5] ?? null, $m[6] ?? null, $tz)
                : null;
            if ($start !== null) {
                return [$start, $this->validEnd($start, $end)];
            }
        }

        // Strategy 2: numeric date "DD.MM.YYYY" with optional "HH:MM Uhr" / "HH Uhr".
        if (preg_match('/(\d{1,2}\.\d{1,2}\.\d{2,4})/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            $after = (int) $m[1][1] + strlen($m[1][0]);
            [$hour, $minute] = $this->findTimeNear($text, $after);
            $start = $this->makeNumericDate($m[1][0], $hour, $minute, $tz);
            if ($start !== null) {
                return [$start, null];
            }
        }

        // Strategy 3: spelled-out German date "13. Juni 2026" / "1. Mai 2026".
        if (preg_match('/(\d{1,2})\.\s*([A-Za-zäöüÄÖÜ]+)\s+(\d{4})/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            $month = self::MONTHS[$this->foldMonth($m[2][0])] ?? null;
            if ($month !== null) {
                $after = (int) $m[3][1] + strlen($m[3][0]);
                [$hour, $minute] = $this->findTimeNear($text, $after);
                $start = $this->makeDate((int) $m[1][0], $month, (int) $m[3][0], $hour, $minute, $tz);
                if ($start !== null) {
                    return [$start, null];
                }
            }
        }

        return [null, null];
    }

    /** End is only kept when it is after the start (guards against parse noise). */
    private function validEnd(\DateTimeImmutable $start, ?\DateTimeImmutable $end): ?\DateTimeImmutable
    {
        return $end !== null && $end > $start ? $end : null;
    }

    /**
     * Looks for a "HH:MM" or "HH Uhr" time shortly after the date that ended at
     * the given byte $offset. Uses byte-based substr to match PREG_OFFSET_CAPTURE
     * offsets, and only accepts ":" as the time separator so it never re-reads a
     * date's own ".MM." as a time.
     */
    private function findTimeNear(string $text, int $offset): array
    {
        $window = substr($text, max(0, $offset), 60);
        if (preg_match('/(\d{1,2}):(\d{2})\s*(?:Uhr)?/u', $window, $t)) {
            return [$t[1], $t[2]];
        }
        if (preg_match('/(\d{1,2})\s*Uhr/u', $window, $t)) {
            return [$t[1], '00'];
        }

        return [null, null];
    }

    private function makeNumericDate(string $date, ?string $hour, ?string $minute, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})$/', $date, $m)) {
            return null;
        }
        $year = (int) $m[3];
        if ($year < 100) {
            $year += 2000;
        }

        return $this->makeDate((int) $m[1], (int) $m[2], $year, $hour, $minute, $tz);
    }

    private function makeDate(int $day, int $month, int $year, ?string $hour, ?string $minute, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if ($year < 2000 || $year > 2100) {
            return null;
        }

        $h = $hour !== null ? max(0, min(23, (int) $hour)) : 0;
        $i = $minute !== null ? max(0, min(59, (int) $minute)) : 0;

        return SafeDate::create($year, $month, $day, $h, $i, $tz);
    }

    private function parsePublished(?string $value): ?\DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $tz = new \DateTimeZone(self::TZ);

        try {
            // The constructor timezone only applies to offset-less values, so
            // the parse never depends on the php.ini default timezone.
            return (new \DateTimeImmutable($value, $tz))->setTimezone($tz);
        } catch (\Exception) {
            return null;
        }
    }

    private function deriveVenue(string $text): ?string
    {
        if (preg_match('/Veranstaltungsort:\s*([^\r\n]+)/u', $text, $m)) {
            $venue = trim($m[1]);
            // Source uses "|" / non-breaking separators and dashes for slugs.
            $venue = trim(preg_replace('/\s*\|\s*.*$/u', '', $venue) ?? $venue);
            $venue = str_replace('-', ' ', $venue);

            return $venue !== '' ? trim($venue) : null;
        }

        return null;
    }

    /** @param list<string> $categories */
    private function mapCategory(array $categories, array $config): ?string
    {
        $allowed = ['musik', 'party', 'buehne', 'kunst', 'familie', 'sport', 'markt', 'genuss', 'bildung', 'sonstiges'];
        $map = $config['categoryMap'] ?? [];

        foreach ($categories as $label) {
            $key = mb_strtolower(trim($label));
            if (isset($map[$key]) && in_array($map[$key], $allowed, true)) {
                return $map[$key];
            }
        }

        $default = $config['category'] ?? null;

        return is_string($default) && in_array($default, $allowed, true) ? $default : null;
    }

    private function htmlToText(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $text = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $text = preg_replace('#</(p|div|h[1-6]|li)>#i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse runs of whitespace but keep single newlines for line-based regex.
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{2,}/', "\n", $text) ?? $text;

        return trim($text);
    }

    private function shorten(string $text, int $max = 600): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max)).'…';
    }

    /** Folds umlauts/case so month lookups are robust ("März" => "marz"). */
    private function foldMonth(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return strtr($value, ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss']);
    }

    /** @param list<string> $values */
    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            $value = trim($value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
