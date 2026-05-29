<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke HTML importer for "Die Weberei" (Kulturzentrum Gütersloh),
 * https://www.die-weberei.de/.
 *
 * The site is a WordPress install using the Divi theme. Events are exposed as
 * server-rendered "portfolio" tiles on the start page (no JS, no ICS/RSS/JSON-LD).
 * Each tile carries:
 *   - a detail link (.et_pb_portfolio_item > a[href])
 *   - a title (h2.et_pb_module_header a)
 *   - category tags (p.post-meta a)
 *   - a date line (p.event-meta) in the form "DD.MM.YYYY | HH:MM Uhr"
 *
 * Tiles without an event-meta date (e.g. evergreen posts like vouchers) are
 * skipped. No images are imported; sourceUrl always points at the detail page.
 */
#[AutoconfigureTag('app.source_importer')]
final class WebereiImporter implements SourceImporter
{
    private const DEFAULT_URL = 'https://www.die-weberei.de/';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)';

    /** Maps the site's Divi project categories onto our category slugs. */
    private const CATEGORY_MAP = [
        'comedy-kabarett' => 'buehne',
        'theater-lesungen' => 'buehne',
        'live-on-stage' => 'musik',
        'dancefloor' => 'party',
        // "highlights" is purely editorial, no real category => fall through
    ];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'weberei';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl() ?: self::DEFAULT_URL;
        $config = $source->getConfig();
        $city = $config['city'] ?? 'Gütersloh';
        $venue = $config['venue'] ?? 'Die Weberei';

        $html = $this->http->request('GET', $url, [
            'headers' => ['User-Agent' => self::USER_AGENT],
            'timeout' => 30,
        ])->getContent();

        $crawler = new Crawler($html, $url);
        $tz = new \DateTimeZone('Europe/Berlin');

        foreach ($crawler->filter('.et_pb_portfolio_item') as $node) {
            $event = $this->mapItem(new Crawler($node, $url), $tz, $city, $venue);
            if ($event !== null) {
                yield $event;
            }
        }
    }

    private function mapItem(Crawler $item, \DateTimeZone $tz, string $city, string $venue): ?ImportedEvent
    {
        $dateNode = $item->filter('.event-meta');
        if ($dateNode->count() === 0) {
            return null; // tile without a concrete date (evergreen post)
        }

        $start = $this->parseDate($dateNode->text(''), $tz);
        if ($start === null) {
            return null;
        }

        $titleNode = $item->filter('h2.et_pb_module_header a');
        if ($titleNode->count() === 0) {
            $titleNode = $item->filter('h2.et_pb_module_header');
        }
        $title = $titleNode->count() > 0 ? trim($titleNode->text('')) : '';
        if ($title === '') {
            return null;
        }

        $detailUrl = $this->detailUrl($item);

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            allDay: false,
            venueName: $venue,
            city: $city,
            locationText: $venue.', '.$city,
            categorySlug: $this->mapCategory($item),
            sourceUrl: $detailUrl,
            imageUrl: null,
            externalId: $this->externalId($item, $detailUrl),
            raw: [
                'dateText' => trim($dateNode->text('')),
                'categories' => $this->categorySlugs($item),
            ],
        );
    }

    private function detailUrl(Crawler $item): ?string
    {
        // Prefer the title link, fall back to the wrapping image link.
        foreach (['h2.et_pb_module_header a', 'a[href]'] as $selector) {
            $links = $item->filter($selector);
            if ($links->count() > 0) {
                $href = trim((string) $links->first()->attr('href'));
                if ($href !== '' && $href !== '#') {
                    return $href;
                }
            }
        }

        return null;
    }

    /** Parse "29.05.2026 | 18:00 Uhr" (whitespace/newlines tolerated). */
    private function parseDate(string $text, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!preg_match('/(\d{2})\.(\d{2})\.(\d{4})\s*\|\s*(\d{2}):(\d{2})/', $text, $m)) {
            // Date without time still useful (default to midnight, not all-day).
            if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $text, $d)) {
                return $this->build($d[1], $d[2], $d[3], '00', '00', $tz);
            }

            return null;
        }

        return $this->build($m[1], $m[2], $m[3], $m[4], $m[5], $tz);
    }

    private function build(string $d, string $mo, string $y, string $h, string $i, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $dt = \DateTimeImmutable::createFromFormat(
            'd.m.Y H:i',
            sprintf('%s.%s.%s %s:%s', $d, $mo, $y, $h, $i),
            $tz,
        );

        return $dt ?: null;
    }

    private function mapCategory(Crawler $item): ?string
    {
        foreach ($this->categorySlugs($item) as $slug) {
            if (isset(self::CATEGORY_MAP[$slug])) {
                return self::CATEGORY_MAP[$slug];
            }
        }

        return null;
    }

    /** @return list<string> Divi project-category slugs from the tile's classes. */
    private function categorySlugs(Crawler $item): array
    {
        $classes = (string) $item->attr('class');
        if (!preg_match_all('/project_category-([a-z0-9-]+)/', $classes, $m)) {
            return [];
        }

        return array_values(array_unique($m[1]));
    }

    private function externalId(Crawler $item, ?string $detailUrl): ?string
    {
        $id = trim((string) $item->attr('id'));
        if ($id !== '') {
            return 'weberei-'.$id; // e.g. "weberei-post-66290"
        }

        if ($detailUrl !== null) {
            $slug = trim(parse_url($detailUrl, \PHP_URL_PATH) ?? '', '/');
            if ($slug !== '') {
                return 'weberei-'.$slug;
            }
        }

        return null;
    }
}
