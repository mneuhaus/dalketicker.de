<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke HTML scraper for the Theater Gütersloh playbill at
 * https://www.theater-gt.de/spielplan (TYPO3 site, no ICS/RSS/JSON-LD feed).
 *
 * Each event row is a `.teaser` block carrying:
 *   - `.teaser-date` with `.date` ("29.") and `.month` ("Mai") — no year, so the
 *     year is derived from the current date and the chronological ordering of
 *     the list (the playbill is sorted ascending, so a month rollback means the
 *     next calendar year).
 *   - `.teaser-info-time` with a "HH.MM" time (dot separator).
 *   - `.teaser-info-location` with the venue (Studiobühne, Theatersaal, ...).
 *   - `.teaser-info-label a` linking to a `/rubrik/<slug>` category.
 *   - `.teaser-detail h3` with the title, wrapped in `/veranstaltung/<slug>`.
 *   - `.teaser-image img` with a processed thumbnail (relative `/fileadmin/...`).
 *
 * The processed thumbnail is hotlinked as-is; sourceUrl points at the detail page.
 */
#[AutoconfigureTag('app.source_importer')]
final class TheaterGtImporter implements SourceImporter
{
    private const BASE = 'https://www.theater-gt.de';
    private const DEFAULT_LIST = self::BASE.'/spielplan';

    /** German month abbreviations as rendered by the site. */
    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mär' => 3, 'maer' => 3, 'mrz' => 3, 'apr' => 4,
        'mai' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'okt' => 10,
        'nov' => 11, 'dez' => 12,
    ];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'theater_gt';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl() ?: self::DEFAULT_LIST;
        $config = $source->getConfig();
        $city = $config['city'] ?? 'Gütersloh';

        $html = $this->http->request('GET', $url, [
            'headers' => ['User-Agent' => 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)'],
            'timeout' => 30,
        ])->getContent();

        $crawler = new Crawler($html);

        $tz = new \DateTimeZone('Europe/Berlin');
        $now = new \DateTimeImmutable('now', $tz);
        $year = (int) $now->format('Y');
        $prevMonth = (int) $now->format('n');

        foreach ($crawler->filter('div.teaser') as $node) {
            $teaser = new Crawler($node);

            $day = $this->text($teaser, '.teaser-date .date');
            $monthRaw = $this->text($teaser, '.teaser-date .month');
            if ($day === null || $monthRaw === null) {
                continue;
            }

            $dayNum = (int) preg_replace('/\D+/', '', $day);
            $month = self::MONTHS[mb_strtolower(trim($monthRaw))] ?? null;
            if ($dayNum < 1 || $month === null) {
                continue;
            }

            // The playbill is sorted ascending; a month going backwards means we
            // crossed into the next calendar year.
            if ($month < $prevMonth) {
                ++$year;
            }
            $prevMonth = $month;

            // closest() throws on an empty node list, so a title-less teaser
            // (promo/notice block) must be skipped instead of killing the run.
            $h3 = $teaser->filter('.teaser-detail h3');
            $titleLink = $h3->count() > 0 ? $h3->closest('a') : null;
            $title = $this->text($teaser, '.teaser-detail h3');
            if ($title === null || $title === '') {
                continue;
            }

            $href = $titleLink !== null && $titleLink->count() > 0 ? $titleLink->attr('href') : null;
            $sourceUrl = $this->absolute($href);

            $start = $this->buildStart($year, $month, $dayNum, $this->text($teaser, '.teaser-info-time'), $tz);
            if ($start === null) {
                continue;
            }

            // One play slug appears on several dates, so qualify the external id
            // with the date+time to keep each performance a distinct event.
            $externalId = $this->externalIdFrom($href, $start);

            $venue = $this->text($teaser, '.teaser-info-location');
            $rubrikHref = $this->attr($teaser, '.teaser-info-label a', 'href');
            $rubrikLabel = $this->text($teaser, '.teaser-info-label a');

            // Per-event thumbnail lives in `.teaser-image img`; scope tightly so
            // the clock glyph `<img>` inside `.teaser-info-time` is never picked.
            $imageUrl = $this->absolute($this->attr($teaser, '.teaser-image img', 'src'));

            yield new ImportedEvent(
                title: $title,
                startsAt: $start,
                allDay: false,
                venueName: $venue,
                city: $city,
                locationText: $venue,
                categorySlug: $this->mapCategory($rubrikHref, $rubrikLabel),
                sourceUrl: $sourceUrl,
                imageUrl: $imageUrl,
                organizer: 'Theater Gütersloh',
                externalId: $externalId,
                raw: array_filter([
                    'date' => $day,
                    'month' => $monthRaw,
                    'time' => $this->text($teaser, '.teaser-info-time'),
                    'venue' => $venue,
                    'rubrik' => $rubrikHref,
                    'rubrikLabel' => $rubrikLabel,
                    'href' => $href,
                ], static fn ($v) => $v !== null && $v !== ''),
            );
        }
    }

    private function buildStart(int $year, int $month, int $day, ?string $time, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $hour = 0;
        $minute = 0;
        if ($time !== null && preg_match('/(\d{1,2})[.:](\d{2})/', $time, $m)) {
            $hour = (int) $m[1];
            $minute = (int) $m[2];
        }

        $date = \DateTimeImmutable::createFromFormat(
            'Y-n-j H:i',
            sprintf('%d-%d-%d %d:%02d', $year, $month, $day, $hour, $minute),
            $tz,
        );

        return $date ?: null;
    }

    private function mapCategory(?string $rubrikHref, ?string $label): ?string
    {
        $needle = mb_strtolower(($rubrikHref ?? '').' '.($label ?? ''));
        if ($needle === ' ') {
            return null;
        }

        return match (true) {
            str_contains($needle, 'kinder'),
            str_contains($needle, 'jugend'),
            str_contains($needle, 'familie'),
            str_contains($needle, 'taschentheater'),
            str_contains($needle, 'mitmachen') => 'familie',

            str_contains($needle, 'jazz'),
            str_contains($needle, 'lied'),
            str_contains($needle, 'philharmon'),
            str_contains($needle, 'klangkosmos'),
            str_contains($needle, 'weltmusik'),
            str_contains($needle, 'swing'),
            str_contains($needle, 'panoramamusik'),
            str_contains($needle, 'musik') && str_contains($needle, 'tanz') === false => 'musik',

            str_contains($needle, 'schauspiel'),
            str_contains($needle, 'musiktheater'),
            str_contains($needle, 'tanz'),
            str_contains($needle, 'theater') => 'buehne',

            default => 'buehne',
        };
    }

    private function externalIdFrom(?string $href, \DateTimeImmutable $start): ?string
    {
        if ($href === null) {
            return null;
        }
        if (preg_match('#/veranstaltung/([a-z0-9-]+)#i', $href, $m)) {
            return 'theater_gt:'.$m[1].':'.$start->format('Y-m-d-Hi');
        }

        return null;
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

        return $sub->first()->attr($attr);
    }
}
