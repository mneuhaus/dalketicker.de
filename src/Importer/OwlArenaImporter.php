<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke HTML scraper for the heristo-arena / OWL Arena ticket-events page in
 * Halle (Westf.) at https://www.heristo-arena.nrw/tickets-events/ (TYPO3 site,
 * no usable ICS/RSS/JSON-LD feed).
 *
 * The listing is a single server-rendered page; each event is one
 * `ul.listbase.events > li.tile`. Within a tile we read:
 *   - the detail link `a[href^="/tickets-events/show/event/"]` (relative),
 *   - the title from `h3`,
 *   - the start (and optional end) from `time[itemprop=startDate]@datetime` /
 *     `time[itemprop=endDate]@datetime` (schema.org microdata, ISO 8601),
 *   - the category from `b.tile-area` (Sport, Konzert, Sonstiges, ...),
 *   - the image from `img[itemprop=image]@src` (relative).
 *
 * Detail URLs and image src are made absolute against the host.
 */
#[AutoconfigureTag('app.source_importer')]
final class OwlArenaImporter implements SourceImporter
{
    private const BASE = 'https://www.heristo-arena.nrw';
    private const DEFAULT_LIST = self::BASE.'/tickets-events/';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.de)';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'owl_arena';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $city = $config['city'] ?? 'Halle (Westf.)';
        $url = $source->getUrl() ?: self::DEFAULT_LIST;
        $tz = new \DateTimeZone('Europe/Berlin');

        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => 20,
            ]);
            if ($response->getStatusCode() >= 400) {
                return;
            }
            $html = $response->getContent();
        } catch (\Throwable) {
            return;
        }

        $crawler = new Crawler($html, $url);
        $count = 0;

        foreach ($crawler->filter('ul.listbase.events > li.tile') as $node) {
            if ($count >= 200) {
                break;
            }

            try {
                $tile = new Crawler($node);

                $linkNodes = $tile->filter('a[href*="/tickets-events/show/event/"]');
                if ($linkNodes->count() === 0) {
                    continue;
                }
                $href = $linkNodes->first()->attr('href');
                if (!is_string($href) || $href === '') {
                    continue;
                }
                $detailUrl = $this->absolute($href);

                $title = $this->text($tile, 'h3');
                if ($title === null || $title === '') {
                    continue;
                }

                $start = $this->parseTime($tile, 'time[itemprop="startDate"]', $tz);
                if ($start === null) {
                    continue;
                }
                $end = $this->parseTime($tile, 'time[itemprop="endDate"]', $tz);
                if ($end !== null && $end < $start) {
                    $end = null;
                }

                // Midnight start with a multi-day or no time means an all-day event.
                $allDay = $start->format('H:i') === '00:00';

                $category = $this->mapCategory($this->text($tile, 'b.tile-area'), $title);
                $imageUrl = $this->absolute($this->attr($tile, 'img[itemprop="image"]', 'src'));
                $subtitle = $this->text($tile, '.tile-content p');

                ++$count;

                yield new ImportedEvent(
                    title: $title,
                    startsAt: $start,
                    endsAt: $end,
                    allDay: $allDay,
                    description: $subtitle,
                    venueName: 'OWL Arena',
                    city: $city,
                    locationText: 'OWL Arena, Halle (Westf.)',
                    categorySlug: $category,
                    sourceUrl: $detailUrl,
                    imageUrl: $imageUrl,
                    organizer: 'OWL Arena',
                    externalId: 'owl_arena:'.$this->slug($href),
                    raw: [
                        'href' => $href,
                        'detailUrl' => $detailUrl,
                    ],
                );
            } catch (\Throwable) {
                continue;
            }
        }
    }

    private function parseTime(Crawler $tile, string $selector, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $nodes = $tile->filter($selector);
        if ($nodes->count() === 0) {
            return null;
        }
        $datetime = $nodes->first()->attr('datetime');
        if (!is_string($datetime) || trim($datetime) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($datetime), $tz);
        } catch (\Throwable) {
            return null;
        }
    }

    private function mapCategory(?string $area, string $title): ?string
    {
        $needle = mb_strtolower(($area ?? '').' '.$title);

        return match (true) {
            str_contains($needle, 'konzert'),
            str_contains($needle, 'musik') => 'musik',

            str_contains($needle, 'sport'),
            str_contains($needle, 'cup'),
            str_contains($needle, 'open') => 'sport',

            str_contains($needle, 'comedy'),
            str_contains($needle, 'show'),
            str_contains($needle, 'theater'),
            str_contains($needle, 'musical') => 'buehne',

            str_contains($needle, 'messe'),
            str_contains($needle, 'markt') => 'markt',

            str_contains($needle, 'oktoberfest'),
            str_contains($needle, 'party') => 'party',

            default => 'sonstiges',
        };
    }

    private function slug(string $href): string
    {
        if (preg_match('#/event/([a-z0-9-]+)#i', $href, $m)) {
            return $m[1];
        }

        return trim($href, '/');
    }

    private function absolute(?string $href): ?string
    {
        if ($href === null || $href === '') {
            return null;
        }
        $href = trim($href);
        if (str_starts_with($href, 'http')) {
            return $href;
        }

        return self::BASE.'/'.ltrim($href, '/');
    }

    private function text(Crawler $node, string $selector): ?string
    {
        $sub = $node->filter($selector);
        if ($sub->count() === 0) {
            return null;
        }
        $text = trim(preg_replace('/\s+/', ' ', $sub->first()->text('')) ?? '');

        return $text !== '' ? $text : null;
    }

    private function attr(Crawler $node, string $selector, string $attr): ?string
    {
        $sub = $node->filter($selector);
        if ($sub->count() === 0) {
            return null;
        }

        return $sub->first()->attr($attr);
    }
}
