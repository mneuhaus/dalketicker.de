<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke HTML scraper for the Gartenschaupark Rietberg event calendar
 * (https://www.gartenschaupark-rietberg.de/veranstaltungen/...), a TYPO3 site
 * running the "cyt-eventcalendar" extension. No ICS/RSS/JSON-LD feed exists, but
 * each list row carries schema.org/Event microdata, which makes parsing robust.
 *
 * Row selector: `ul.cyt-eventcalendar-list > li.cyt-eventcalendar-list-item`.
 *   - Date: `div.cyt-eventcalendar-list-item-startdate[itemprop=startDate]` has a
 *     machine-readable `content="YYYY-MM-DDTHH:MM"` ISO 8601 attribute. An end
 *     date may appear via `[itemprop=endDate]`. A placeholder "01:00" time marks
 *     all-day entries; a real time (e.g. T10:00) marks timed events.
 *   - Title: `div.cyt-eventcalendar-list-item-title` (text of its inner <a>),
 *     also as `[itemprop=name]`.
 *   - Detail link: first <a href> in the row (relative, prepend the domain).
 *   - Image: `div.cyt-eventcalendar-list-item-image img[itemprop=image]` (src is
 *     a relative `/fileadmin/...` path).
 *   - Location: `div.cyt-eventcalendar-list-item-location [itemprop=name]` plus
 *     an address block; category label in `-category`.
 *
 * The default listing already shows upcoming events sorted ascending by date.
 */
#[AutoconfigureTag('app.source_importer')]
final class GartenschauparkImporter implements SourceImporter
{
    private const BASE = 'https://www.gartenschaupark-rietberg.de';
    private const DEFAULT_LIST = self::BASE.'/veranstaltungen/veranstaltungen-konzerte-feste-etc.html';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.de)';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'gartenschaupark';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $city = $config['city'] ?? 'Rietberg';
        $maxEvents = (int) ($config['maxEvents'] ?? 200);

        $listUrl = $source->getUrl() ?: self::DEFAULT_LIST;
        $html = $this->fetch($listUrl);
        if ($html === null) {
            return;
        }

        $tz = new \DateTimeZone('Europe/Berlin');
        $crawler = new Crawler($html, $listUrl);
        $count = 0;

        foreach ($crawler->filter('ul.cyt-eventcalendar-list > li.cyt-eventcalendar-list-item') as $node) {
            if ($count >= $maxEvents) {
                break;
            }

            try {
                $event = $this->parseRow(new Crawler($node), $tz, $city);
            } catch (\Throwable) {
                continue;
            }
            if ($event === null) {
                continue;
            }

            ++$count;
            yield $event;
        }
    }

    private function parseRow(Crawler $row, \DateTimeZone $tz, string $city): ?ImportedEvent
    {
        $start = $this->parseIso($this->attr($row, '.cyt-eventcalendar-list-item-startdate', 'content'), $tz);
        if ($start === null) {
            return null;
        }
        $end = $this->parseIso($this->attr($row, '[itemprop="endDate"]', 'content'), $tz);
        if ($end !== null && $end <= $start) {
            $end = null;
        }

        $title = $this->text($row, '.cyt-eventcalendar-list-item-title');
        if ($title === null || $title === '') {
            return null;
        }

        $href = $this->detailHref($row);
        $sourceUrl = $this->absolute($href);
        if ($sourceUrl === null) {
            return null;
        }

        // The "01:00" placeholder marks all-day entries (no real start time).
        $allDay = $start->format('H:i') === '01:00' && $end === null;

        $venueName = $this->text($row, '.cyt-eventcalendar-list-item-location [itemprop="name"]');
        $locationText = $this->locationText($row);
        $category = $this->text($row, '.cyt-eventcalendar-list-item-category');
        $imageUrl = $this->absolute($this->attr($row, '.cyt-eventcalendar-list-item-image img', 'src'));

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: $end,
            allDay: $allDay,
            venueName: $venueName,
            city: $city,
            locationText: $locationText,
            categorySlug: $this->mapCategory($category, $title),
            sourceUrl: $sourceUrl,
            imageUrl: $imageUrl,
            organizer: 'Gartenschaupark Rietberg',
            externalId: $this->externalIdFrom($href),
            raw: array_filter([
                'href' => $href,
                'category' => $category,
                'startContent' => $this->attr($row, '.cyt-eventcalendar-list-item-startdate', 'content'),
            ], static fn ($v) => $v !== null && $v !== ''),
        );
    }

    private function parseIso(?string $value, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);

        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, $tz);
            if ($date instanceof \DateTimeImmutable) {
                return $format === 'Y-m-d' ? $date->setTime(0, 0) : $date;
            }
        }

        return null;
    }

    private function detailHref(Crawler $row): ?string
    {
        $links = $row->filter('a[href]');
        if ($links->count() === 0) {
            return null;
        }

        return $links->first()->attr('href');
    }

    private function locationText(Crawler $row): ?string
    {
        $block = $row->filter('.cyt-eventcalendar-list-item-location');
        if ($block->count() === 0) {
            return null;
        }
        $text = trim(preg_replace('/\s+/', ' ', $block->first()->text('')) ?? '');
        // Drop the leading "Veranstaltungsort:" label the markup carries.
        $text = trim(preg_replace('/^Veranstaltungsort:\s*/u', '', $text) ?? $text);

        return $text !== '' ? $text : null;
    }

    private function mapCategory(?string $category, string $title): ?string
    {
        $needle = mb_strtolower(($category ?? '').' '.$title);

        return match (true) {
            str_contains($needle, 'konzert'),
            str_contains($needle, 'musik') => 'musik',

            str_contains($needle, 'party'),
            str_contains($needle, 'fest') => 'party',

            str_contains($needle, 'theater'),
            str_contains($needle, 'bühne'),
            str_contains($needle, 'kabarett'),
            str_contains($needle, 'comedy'),
            str_contains($needle, 'lesung') => 'buehne',

            str_contains($needle, 'kunst'),
            str_contains($needle, 'ausstellung') => 'kunst',

            str_contains($needle, 'kinder'),
            str_contains($needle, 'familie'),
            str_contains($needle, 'hüpfburg') => 'familie',

            str_contains($needle, 'sport'),
            str_contains($needle, 'lauf'),
            str_contains($needle, 'wander') => 'sport',

            str_contains($needle, 'markt') => 'markt',

            str_contains($needle, 'kulinar'),
            str_contains($needle, 'genuss'),
            str_contains($needle, 'wein') => 'genuss',

            str_contains($needle, 'führung'),
            str_contains($needle, 'vortrag'),
            str_contains($needle, 'natur'),
            str_contains($needle, 'umwelt') => 'bildung',

            default => 'sonstiges',
        };
    }

    private function externalIdFrom(?string $href): ?string
    {
        if ($href === null) {
            return null;
        }
        if (preg_match('#/event/([a-z0-9-]+)\.html#i', $href, $m)) {
            return 'gartenschaupark:'.$m[1];
        }

        return null;
    }

    private function fetch(string $url): ?string
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => 20,
            ]);
            if ($response->getStatusCode() >= 400) {
                return null;
            }

            return $response->getContent();
        } catch (\Throwable) {
            return null;
        }
    }

    private function absolute(?string $href): ?string
    {
        if ($href === null || $href === '') {
            return null;
        }
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
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
