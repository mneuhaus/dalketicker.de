<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke HTML scraper for the Burg Ravensberg event calendar
 * (https://burg-ravensberg.de/veranstaltungskalender/).
 *
 * WordPress site using the Simple Calendar (simcal) plugin, fully server-rendered
 * with schema.org microdata. No machine-readable events feed exists (the site RSS
 * is blog posts only, there is no JSON-LD Event block, no tribe/destination.one
 * endpoint, and ?ical=1 is not a usable events ICS). HTML is clean and carries ISO
 * datetimes via `content` attributes.
 *
 * Each event is a `div.simcal-event-details` block carrying:
 *   - `.simcal-event-title[itemprop=name]` — the title (may hold HTML entities).
 *   - `.simcal-event-start-date[itemprop=startDate]` with a reliable ISO 8601
 *     `content` attribute (e.g. "2026-05-31T12:00:00+02:00").
 *   - `.simcal-event-end-time[itemprop=endDate]` with an ISO `content` attribute.
 *   - `.simcal-event-description[itemprop=description]` — HTML paragraphs.
 *
 * There are NO per-event detail URLs (the only anchors are HTML-commented-out
 * Google Calendar links), so every event points at the calendar page itself.
 * Recurring events (e.g. "Burgführungen im Stundentakt") repeat with distinct
 * dates; each occurrence becomes its own ImportedEvent, deduped by title+start.
 */
#[AutoconfigureTag('app.source_importer')]
final class BurgRavensbergImporter implements SourceImporter
{
    private const DEFAULT_URL = 'https://burg-ravensberg.de/veranstaltungskalender/';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.de)';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'burg_ravensberg';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $url = $source->getUrl() ?: self::DEFAULT_URL;
        $city = $config['city'] ?? 'Borgholzhausen';
        $tz = new \DateTimeZone('Europe/Berlin');

        $html = $this->fetch($url);

        $crawler = new Crawler($html, $url);
        $seen = [];
        $count = 0;

        foreach ($crawler->filter('div.simcal-event-details') as $node) {
            if ($count >= 200) {
                break;
            }

            try {
                $event = new Crawler($node);

                $title = $this->text($event, '.simcal-event-title');
                if ($title === null || $title === '') {
                    continue;
                }

                $start = $this->isoDate($event, '.simcal-event-start-date', $tz);
                if ($start === null) {
                    continue;
                }
                $end = $this->isoDate($event, '.simcal-event-end-time', $tz);
                if ($end !== null && $end < $start) {
                    $end = null;
                }

                $dedup = mb_strtolower($title).'|'.$start->format('Y-m-d-Hi');
                if (isset($seen[$dedup])) {
                    continue;
                }
                $seen[$dedup] = true;
                ++$count;

                $description = $this->text($event, '.simcal-event-description');

                yield new ImportedEvent(
                    title: $title,
                    startsAt: $start,
                    endsAt: $end,
                    allDay: false,
                    description: $description,
                    venueName: 'Burg Ravensberg',
                    city: $city,
                    locationText: 'Burg Ravensberg, Borgholzhausen',
                    categorySlug: $this->mapCategory($title, $description),
                    sourceUrl: $url,
                    organizer: 'Stiftung Burg Ravensberg',
                    externalId: 'burg_ravensberg:'.$start->format('Y-m-d-Hi').':'.substr(sha1($title), 0, 8),
                    raw: [
                        'sourceUrl' => $url,
                    ],
                );
            } catch (\Throwable) {
                continue;
            }
        }
    }

    /** Fetch the calendar page or throw, so a dead source surfaces as a failed run. */
    private function fetch(string $url): string
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => self::USER_AGENT],
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

    private function isoDate(Crawler $event, string $selector, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $sub = $event->filter($selector);
        if ($sub->count() === 0) {
            return null;
        }
        $iso = $sub->first()->attr('content');
        if (!is_string($iso) || trim($iso) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable(trim($iso)))->setTimezone($tz);
        } catch (\Throwable) {
            return null;
        }
    }

    private function text(Crawler $event, string $selector): ?string
    {
        $sub = $event->filter($selector);
        if ($sub->count() === 0) {
            return null;
        }
        $text = trim(preg_replace('/\s+/u', ' ', $sub->first()->text('')) ?? '');

        return $text !== '' ? $text : null;
    }

    private function mapCategory(string $title, ?string $description): ?string
    {
        $haystack = mb_strtolower($title.' '.($description ?? ''));

        $map = [
            'musik' => ['konzert', 'musik', 'chor', 'band', 'orchester', 'jazz'],
            'buehne' => ['theater', 'kabarett', 'comedy', 'lesung', 'schauspiel'],
            'kunst' => ['ausstellung', 'kunst', 'galerie', 'vernissage', 'museum'],
            'familie' => ['kinder', 'familie', 'ostern', 'weihnacht'],
            'sport' => ['lauf', 'turnier', 'fitness'],
            'markt' => ['markt', 'flohmarkt', 'basar', 'trödel'],
            'genuss' => ['wein', 'kulinarisch', 'kochen', 'genuss', 'grill'],
            'bildung' => ['führung', 'fuehrung', 'vortrag', 'wanderung', 'seminar', 'workshop', 'lesekreis', 'geschichte'],
        ];

        foreach ($map as $slug => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return $slug;
                }
            }
        }

        return 'sonstiges';
    }
}
