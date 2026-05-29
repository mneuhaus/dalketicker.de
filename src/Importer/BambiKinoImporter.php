<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke importer for the Bambi Kino programme page in Gütersloh.
 *
 * The site is WordPress with the "Theater for WordPress" plugin. The
 * /programm/ page renders every upcoming screening server-side (no JS), each
 * inside a `.wp_theatre_event` block carrying the linked film title, a date
 * (`DD.MM.YYYY`) and a time (`HH:MM`) in two `.wp_theatre_event_datetime`
 * elements, plus production-category tags.
 */
#[AutoconfigureTag('app.source_importer')]
final class BambiKinoImporter implements SourceImporter
{
    private const DEFAULT_URL = 'https://www.bambikino.de/programm/';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)';

    /** Maps "Theater for WordPress" production categories to allowed slugs. */
    private const CATEGORY_MAP = [
        'kinderkino' => 'familie',
        'filmkonzert' => 'musik',
        'dokumentarfilme' => 'bildung',
        'literaturkino' => 'bildung',
        'seniorenkino' => 'sonstiges',
    ];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'bambi_kino';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl() ?: self::DEFAULT_URL;
        $config = $source->getConfig();
        $city = $config['city'] ?? 'Gütersloh';
        $venue = $config['venue'] ?? 'Bambi Kino';

        $html = $this->http->request('GET', $url, [
            'headers' => ['User-Agent' => self::USER_AGENT],
            'timeout' => 30,
        ])->getContent();

        $crawler = new Crawler($html);
        $events = $crawler->filter('.wp_theatre_event');

        foreach ($events as $node) {
            $event = $this->mapEvent(new Crawler($node), $city, $venue, $url);
            if ($event !== null) {
                yield $event;
            }
        }
    }

    private function mapEvent(Crawler $node, string $city, string $venue, string $baseUrl): ?ImportedEvent
    {
        $titleLink = $node->filter('.wp_theatre_event_title a');
        if ($titleLink->count() === 0) {
            return null;
        }
        $title = trim($titleLink->text(''));
        if ($title === '') {
            return null;
        }
        $href = trim($titleLink->attr('href') ?? '');

        $start = $this->parseDateTime($node);
        if ($start === null) {
            return null;
        }

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            allDay: false,
            venueName: $venue,
            city: $city,
            locationText: $venue.', '.$city,
            categorySlug: $this->mapCategory($node),
            sourceUrl: $href !== '' ? $href : null,
            imageUrl: $this->extractImage($node, $baseUrl), // hotlink to the poster, never hosted
            organizer: $venue,
            externalId: $this->externalId($href, $start),
            raw: [
                'title' => $title,
                'href' => $href,
                'start' => $start->format('c'),
            ],
        );
    }

    /**
     * The two `.wp_theatre_event_datetime` blocks hold date ("DD.MM.YYYY")
     * and time ("HH:MM"). Fall back to scanning the block text for a combined
     * "DD.MM.YYYY HH:MM" string if the structure differs.
     */
    private function parseDateTime(Crawler $node): ?\DateTimeImmutable
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $date = null;
        $time = null;

        $node->filter('.wp_theatre_event_datetime')->each(function (Crawler $c) use (&$date, &$time): void {
            $text = trim($c->text(''));
            if ($date === null && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $text, $m)) {
                $date = $m[3].'-'.$m[2].'-'.$m[1];
            }
            if ($time === null && preg_match('/(\d{1,2}):(\d{2})/', $text, $m)) {
                $time = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
            }
        });

        if ($date === null || $time === null) {
            $whole = $node->text('');
            if ($date === null && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $whole, $m)) {
                $date = $m[3].'-'.$m[2].'-'.$m[1];
            }
            if ($time === null && preg_match('/(\d{1,2}):(\d{2})/', $whole, $m)) {
                $time = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
            }
        }

        if ($date === null) {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $date.' '.($time ?? '00:00'), $tz);

        return $dt ?: null;
    }

    private function mapCategory(Crawler $node): ?string
    {
        $items = $node->filter('.wpt_production_categories li.wpt_production_category');
        if ($items->count() === 0) {
            return null;
        }

        foreach ($items as $li) {
            $label = mb_strtolower(trim((new Crawler($li))->text('')));
            $label = str_replace([' ', 'ä', 'ö', 'ü', 'ß'], ['', 'a', 'o', 'u', 'ss'], $label);
            foreach (self::CATEGORY_MAP as $needle => $slug) {
                if (str_contains($label, $needle)) {
                    return $slug;
                }
            }
        }

        // A cinema screening that didn't match a specific theme.
        return 'sonstiges';
    }

    /**
     * Each `.wp_theatre_event` block opens with a `<figure><img>` carrying the
     * film poster (already absolute, but we still resolve to be safe). The
     * `src` is the small list thumbnail; nothing to download, we just hotlink.
     */
    private function extractImage(Crawler $node, string $baseUrl): ?string
    {
        $img = $node->filter('figure img, img.wp-post-image, img');
        if ($img->count() === 0) {
            return null;
        }
        $src = trim($img->first()->attr('src') ?? '');
        if ($src === '') {
            return null;
        }

        return $this->absolutize($src, $baseUrl);
    }

    private function absolutize(string $href, string $baseUrl): ?string
    {
        $href = html_entity_decode($href, \ENT_QUOTES | \ENT_HTML5);
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME);

            return (\is_string($scheme) && $scheme !== '' ? $scheme : 'https').':'.$href;
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

    /** Per-screening id: film slug + start, so repeated screenings stay distinct. */
    private function externalId(string $href, \DateTimeImmutable $start): ?string
    {
        $slug = $href !== '' ? basename(trim((string) parse_url($href, PHP_URL_PATH), '/')) : '';
        if ($slug === '') {
            return null;
        }

        return 'bambi-'.$slug.'-'.$start->format('YmdHi');
    }
}
