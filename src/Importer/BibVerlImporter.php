<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke HTML scraper for the Stadtbibliothek Verl reading-session calendar at
 * https://bibliothek.verl.de/de/aktuelles/index-vorlesetermine.php (Weblication
 * CMS, no ICS/RSS/JSON-LD feed).
 *
 * Each event is a `ul.listDefault > li.listEntry` row carrying:
 *   - `data-url` (and `h4.listEntryTitle a[href]`) with a relative detail link
 *     whose basename starts with the ISO date, e.g.
 *     `2026-06-02VorlesenKG.php` -> 2026-06-02.
 *   - `h4.listEntryTitle a` with the title (incl. a German "am 02. Juni" suffix).
 *   - `p.listEntryDescription` with a short blurb.
 *   - `img.listEntryThumbnail` with a relative thumbnail (`data-srcmin` higher-res).
 *
 * The list page carries no time, so the start time is enriched from the detail
 * page body ("Von 16.00 Uhr bis 16.45 Uhr"). Failures to fetch a detail page
 * degrade gracefully to an all-day event.
 */
#[AutoconfigureTag('app.source_importer')]
final class BibVerlImporter implements SourceImporter
{
    private const BASE = 'https://bibliothek.verl.de';
    private const DEFAULT_LIST = self::BASE.'/de/aktuelles/index-vorlesetermine.php';
    private const UA = 'Dalketicker/1.0 (+https://dalketicker.de)';
    private const MAX_ITEMS = 200;

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'bib_verl';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl() ?: self::DEFAULT_LIST;
        $config = $source->getConfig();
        $city = $config['city'] ?? 'Verl';

        $html = $this->fetch($url);

        $crawler = new Crawler($html);
        $tz = new \DateTimeZone('Europe/Berlin');
        $count = 0;

        foreach ($crawler->filter('li.listEntry') as $node) {
            if ($count >= self::MAX_ITEMS) {
                break;
            }

            $event = $this->parseRow(new Crawler($node), $city, $tz);
            if ($event !== null) {
                ++$count;
                yield $event;
            }
        }
    }

    private function parseRow(Crawler $row, string $city, \DateTimeZone $tz): ?ImportedEvent
    {
        try {
            $href = $this->attr($row, 'h4.listEntryTitle a', 'href')
                ?? ($row->count() > 0 ? $row->attr('data-url') : null);
            if ($href === null || $href === '') {
                return null;
            }
            $sourceUrl = $this->absolute($href);

            $isoDate = $this->dateFromHref($href);
            if ($isoDate === null) {
                return null;
            }

            $title = $this->text($row, 'h4.listEntryTitle a');
            if ($title === null || $title === '') {
                return null;
            }

            $description = $this->text($row, 'p.listEntryDescription');
            $imageUrl = $this->absolute(
                $this->attr($row, 'img.listEntryThumbnail', 'data-srcmin')
                ?? $this->attr($row, 'img.listEntryThumbnail', 'src'),
            );

            [$hour, $minute, $endHour, $endMinute] = $this->timeFromDetail($sourceUrl);

            $start = \DateTimeImmutable::createFromFormat(
                'Y-m-d H:i',
                sprintf('%s %02d:%02d', $isoDate, $hour ?? 0, $minute ?? 0),
                $tz,
            );
            if ($start === false) {
                return null;
            }

            $allDay = $hour === null;
            $end = null;
            if ($endHour !== null) {
                $end = \DateTimeImmutable::createFromFormat(
                    'Y-m-d H:i',
                    sprintf('%s %02d:%02d', $isoDate, $endHour, $endMinute ?? 0),
                    $tz,
                ) ?: null;
            }

            return new ImportedEvent(
                title: $title,
                startsAt: $start,
                endsAt: $end,
                allDay: $allDay,
                description: $description,
                venueName: 'Stadtbibliothek Verl',
                city: $city,
                locationText: 'Stadtbibliothek Verl',
                categorySlug: 'familie',
                sourceUrl: $sourceUrl,
                imageUrl: $imageUrl,
                organizer: 'Stadtbibliothek Verl',
                externalId: 'bib_verl:'.$isoDate.':'.basename($href),
                raw: array_filter([
                    'href' => $href,
                    'date' => $isoDate,
                    'description' => $description,
                ], static fn ($v) => $v !== null && $v !== ''),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /** Parse the leading ISO date from a detail link basename like 2026-06-02VorlesenKG.php. */
    private function dateFromHref(string $href): ?string
    {
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', basename($href), $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Fetch the detail page and pull start (and optional end) time from
     * "Von 16.00 Uhr bis 16.45 Uhr". Returns [h, m, endH, endM] with null on miss.
     *
     * @return array{0:?int,1:?int,2:?int,3:?int}
     */
    private function timeFromDetail(?string $url): array
    {
        if ($url === null) {
            return [null, null, null, null];
        }

        $html = $this->tryFetch($url);
        if ($html === null) {
            return [null, null, null, null];
        }

        if (preg_match('/Von\s+(\d{1,2})\.(\d{2})\s*Uhr(?:\s*bis\s*(\d{1,2})\.(\d{2})\s*Uhr)?/u', $html, $m)) {
            return [
                (int) $m[1],
                (int) $m[2],
                isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null,
                isset($m[4]) && $m[4] !== '' ? (int) $m[4] : null,
            ];
        }

        return [null, null, null, null];
    }

    /** Fetch a URL or throw, so a dead source surfaces as a failed run. */
    private function fetch(string $url): string
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => self::UA],
                'timeout' => 20,
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

    private function absolute(?string $href): ?string
    {
        if ($href === null || $href === '') {
            return null;
        }
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
        $text = trim($sub->first()->text(''));

        return $text !== '' ? $text : null;
    }

    private function attr(Crawler $node, string $selector, string $attr): ?string
    {
        $sub = $node->filter($selector);
        if ($sub->count() === 0) {
            return null;
        }

        $value = $sub->first()->attr($attr);

        return $value !== null && $value !== '' ? $value : null;
    }
}
