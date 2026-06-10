<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Importer for "GTV 1879" (https://gtv1879.de/termine/), a Gütersloh sports
 * club whose calendar includes club events such as the Dalkeman triathlon.
 *
 * The site runs WordPress with the "Events Manager" plugin and renders the
 * event list server-side as a list of `.em-event.em-item` cards. There is no
 * JSON-LD and no public REST feed, so we scrape the list markup directly:
 *
 *   .em-item-title a            -> title + permalink (detail page)
 *   .em-event-date              -> German date, e.g. "30. Mai 2026"
 *   .em-event-time              -> "17:30" or "17:30 - 22:00"
 *   .em-event-location a        -> venue name
 *   .em-event-categories a      -> plugin categories (mapped to our slugs)
 *   .em-item-image img[src]     -> absolute thumbnail URL (hot-linked)
 *   .em-item-desc               -> short teaser
 *
 * Legally safe: imageUrl points at the original (absolute) thumbnail URL on the
 * club's own server and is never downloaded; sourceUrl is the detail permalink.
 *
 * Optional source config:
 *   - city:        default city when no town can be derived (default "Gütersloh")
 *   - category:    fallback category slug when none of the plugin categories map
 */
#[AutoconfigureTag('app.source_importer')]
final class Gtv1879Importer implements SourceImporter
{
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)';
    private const DEFAULT_URL = 'https://gtv1879.de/termine/';
    private const DEFAULT_CITY = 'Gütersloh';

    private const MONTHS = [
        'januar' => 1, 'februar' => 2, 'märz' => 3, 'maerz' => 3, 'april' => 4,
        'mai' => 5, 'juni' => 6, 'juli' => 7, 'august' => 8, 'september' => 9,
        'oktober' => 10, 'november' => 11, 'dezember' => 12,
    ];

    /** Events-Manager category (lowercased) => internal slug. */
    private const CATEGORY_MAP = [
        'triathlon' => 'sport',
        'leichtathletik' => 'sport',
        'fußball' => 'sport',
        'fussball' => 'sport',
        'badminton' => 'sport',
        'tennis' => 'sport',
        'handball' => 'sport',
        'volleyball' => 'sport',
        'turnen' => 'sport',
        'schwimmen' => 'sport',
        'darts' => 'sport',
        'lauf' => 'sport',
        'wettkampf' => 'sport',
        'mitgliederversammlungen' => 'sonstiges',
        'mitgliederversammlung' => 'sonstiges',
        'versammlung' => 'sonstiges',
        'feier' => 'party',
        'fest' => 'party',
        'konzert' => 'musik',
        'chor' => 'musik',
        'musik' => 'musik',
    ];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'gtv1879';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl() ?: self::DEFAULT_URL;
        $config = $source->getConfig();
        $defaultCity = (string) ($config['city'] ?? self::DEFAULT_CITY);
        $fallbackCategory = isset($config['category']) && \is_string($config['category'])
            ? $config['category']
            : 'sport';

        $html = $this->fetch($url);

        $tz = new \DateTimeZone('Europe/Berlin');
        $crawler = new Crawler($html, $url);
        $items = $crawler->filter('.em-event.em-item');
        if ($items->count() === 0) {
            return;
        }

        $seen = [];
        foreach ($items as $node) {
            $event = $this->mapItem(new Crawler($node, $url), $defaultCity, $fallbackCategory, $tz);
            if ($event === null) {
                continue;
            }
            $key = $event->externalId ?? $event->dedupKey();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            yield $event;
        }
    }

    private function mapItem(Crawler $item, string $defaultCity, string $fallbackCategory, \DateTimeZone $tz): ?ImportedEvent
    {
        $titleNode = $item->filter('.em-item-title a');
        if ($titleNode->count() === 0) {
            return null;
        }
        $title = $this->clean($titleNode->first()->text(''));
        $sourceUrl = trim((string) $titleNode->first()->attr('href')) ?: null;
        if ($title === '' || $sourceUrl === null) {
            return null;
        }

        $dateText = $this->nodeText($item, '.em-event-date');
        $start = $this->parseGermanDate($dateText, $tz);
        if ($start === null) {
            return null;
        }

        $timeText = $this->nodeText($item, '.em-event-time');
        [$startTime, $endTime] = $this->parseTimeRange($timeText);
        $allDay = $startTime === null;
        if ($startTime !== null) {
            $start = $start->setTime($startTime[0], $startTime[1]);
        } else {
            $start = $start->setTime(0, 0);
        }

        $end = null;
        if ($endTime !== null) {
            $end = $start->setTime($endTime[0], $endTime[1]);
            if ($end <= $start) {
                $end = null;
            }
        }

        $venueName = $this->nodeText($item, '.em-event-location a');
        $venueName = $venueName !== '' ? $venueName : null;

        $description = $this->nodeText($item, '.em-item-desc');
        $description = $this->shorten($this->clean($description));
        $description = $description !== '' ? $description : null;

        $categorySlug = $this->mapCategories($item) ?? $fallbackCategory;

        $imageUrl = null;
        $img = $item->filter('.em-item-image img');
        if ($img->count() > 0) {
            $src = trim((string) $img->first()->attr('src'));
            if ($src !== '' && preg_match('#^https?://#i', $src)) {
                $imageUrl = $src;
            }
        }

        $externalId = $this->externalId($sourceUrl, $start);

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: $end,
            allDay: $allDay,
            description: $description,
            venueName: $venueName,
            city: $defaultCity,
            locationText: $venueName,
            categorySlug: $categorySlug,
            sourceUrl: $sourceUrl,
            imageUrl: $imageUrl,
            price: null,
            organizer: 'GTV 1879',
            externalId: $externalId,
            raw: [
                'date' => $dateText,
                'time' => $timeText,
                'url' => $sourceUrl,
            ],
        );
    }

    private function mapCategories(Crawler $item): ?string
    {
        $links = $item->filter('.em-event-categories a');
        if ($links->count() === 0) {
            return null;
        }
        foreach ($links as $node) {
            $name = mb_strtolower(trim((new Crawler($node))->text('')));
            if ($name === '') {
                continue;
            }
            foreach (self::CATEGORY_MAP as $needle => $slug) {
                if (str_contains($name, $needle)) {
                    return $slug;
                }
            }
        }

        return null;
    }

    /** Parse "30. Mai 2026" (also tolerates "30.05.2026"). */
    private function parseGermanDate(string $value, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $value = $this->clean($value);
        if ($value === '') {
            return null;
        }

        // "30. Mai 2026"
        if (preg_match('#(\d{1,2})\.?\s+([A-Za-zäöüÄÖÜ]+)\s+(\d{4})#u', $value, $m)) {
            $month = self::MONTHS[mb_strtolower($m[2])] ?? null;
            if ($month !== null) {
                return $this->makeDate((int) $m[3], $month, (int) $m[1], $tz);
            }
        }

        // "30.05.2026"
        if (preg_match('#(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})#', $value, $m)) {
            return $this->makeDate((int) $m[3], (int) $m[2], (int) $m[1], $tz);
        }

        return null;
    }

    private function makeDate(int $year, int $month, int $day, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        return SafeDate::create($year, $month, $day, 0, 0, $tz);
    }

    /**
     * Parse a time cell into [start, end] tuples of [hour, minute].
     * Handles "17:30", "17:30 - 22:00", "17.30 Uhr".
     *
     * @return array{0: ?array{0:int,1:int}, 1: ?array{0:int,1:int}}
     */
    private function parseTimeRange(string $value): array
    {
        $value = $this->clean($value);
        if ($value === '') {
            return [null, null];
        }

        if (!preg_match_all('#(\d{1,2})[:.](\d{2})#', $value, $m, \PREG_SET_ORDER)) {
            return [null, null];
        }

        $start = [(int) $m[0][1], (int) $m[0][2]];
        $end = isset($m[1]) ? [(int) $m[1][1], (int) $m[1][2]] : null;

        if ($start[0] > 23 || $start[1] > 59) {
            return [null, null];
        }
        if ($end !== null && ($end[0] > 23 || $end[1] > 59)) {
            $end = null;
        }

        return [$start, $end];
    }

    private function externalId(string $sourceUrl, \DateTimeImmutable $start): string
    {
        $slug = trim((string) parse_url($sourceUrl, \PHP_URL_PATH), '/');
        $slug = $slug !== '' ? basename($slug) : $sourceUrl;

        return 'gtv1879:'.$slug.':'.$start->format('Y-m-d');
    }

    private function nodeText(Crawler $item, string $selector): string
    {
        $node = $item->filter($selector);

        return $node->count() > 0 ? $this->clean($node->first()->text('')) : '';
    }

    private function clean(string $text): string
    {
        $text = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5);
        // Collapse NBSP and runs of whitespace.
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('#\s+#u', ' ', $text) ?? $text;

        return trim($text);
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
