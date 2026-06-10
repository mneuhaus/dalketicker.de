<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke HTML scraper for the official city of Versmold event calendar
 * (https://www.versmold.de/de/veranstaltungen/).
 *
 * Weblication CMS, server-rendered HTML, no ICS/RSS/JSON-LD feed. The listing
 * page carries every upcoming event in full, so a single GET is enough — no
 * pagination and no per-detail fetch needed (title, detail link, start/end
 * date+time, thumbnail, venue and a short teaser all live in the list entry).
 *
 * Each entry is a `li.listEntry.listEntryObject-eventMulti` with:
 *   - title + detail link: `h3.listEntryTitle a[href]` (relative .php path; the
 *     outer `<li>` uses data-url+onclick, so the title anchor is the canonical
 *     link).
 *   - thumbnail: `img.listEntryThumbnail[src]` (relative).
 *   - dates: inside `p.listEntrySubline > span.listEntryDate`:
 *       start date `span.daydate.dayFrom` (DD.MM.YYYY), start time
 *       `span.timeFrom` (", HH:MM"); optional end date `span.dayTo.daydate` and
 *       end time `span.timeTo` for multi-day spans. Day-of-week names are
 *       decorative.
 *   - venue: `span.listEntryLocation`.
 *   - teaser: `div.listEntryDescription`.
 */
#[AutoconfigureTag('app.source_importer')]
final class StadtVersmoldImporter implements SourceImporter
{
    private const BASE = 'https://www.versmold.de';
    private const LIST_URL = self::BASE.'/de/veranstaltungen/';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.de)';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'stadt_versmold';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $tz = new \DateTimeZone('Europe/Berlin');
        $city = $config['city'] ?? 'Versmold';
        $maxEvents = (int) ($config['maxEvents'] ?? 200);

        $listUrl = $source->getUrl() ?: self::LIST_URL;
        $html = $this->fetch($listUrl);

        $crawler = new Crawler($html, $listUrl);
        $seen = [];
        $count = 0;

        foreach ($crawler->filter('li.listEntryObject-eventMulti') as $node) {
            if ($count >= $maxEvents) {
                break;
            }

            $entry = new Crawler($node);

            $link = $entry->filter('h3.listEntryTitle a');
            if ($link->count() === 0) {
                continue;
            }
            $link = $link->first();
            $title = trim($link->text(''));
            $href = $link->attr('href') ?? '';
            if ($title === '' || $href === '') {
                continue;
            }
            $detailUrl = $this->absoluteUrl($href);

            [$start, $end] = $this->dates($entry, $tz);
            if ($start === null) {
                continue;
            }

            $externalId = 'stadt_versmold:'.basename((string) parse_url($detailUrl, PHP_URL_PATH), '.php');
            $dedup = $externalId.'|'.$start->format('Y-m-d H:i');
            if (isset($seen[$dedup])) {
                continue;
            }
            $seen[$dedup] = true;
            ++$count;

            $venue = $this->firstText($entry, 'span.listEntryLocation');
            $description = $this->firstText($entry, 'div.listEntryDescription');
            $imageUrl = $this->imageUrl($entry);

            $allDay = $start->format('H:i') === '00:00'
                && ($end === null || $end->format('H:i') === '00:00');

            yield new ImportedEvent(
                title: $title,
                startsAt: $start,
                endsAt: $end,
                allDay: $allDay,
                description: $description !== '' ? $description : null,
                venueName: $venue !== '' ? $venue : null,
                city: $city,
                locationText: $venue !== '' ? $venue : null,
                categorySlug: $this->mapCategory($title, $description),
                sourceUrl: $detailUrl,
                imageUrl: $imageUrl,
                organizer: 'Stadt Versmold',
                externalId: $externalId,
                raw: [
                    'detailUrl' => $detailUrl,
                    'listUrl' => $listUrl,
                ],
            );
        }
    }

    /** Fetch a URL or throw, so a dead source surfaces as a failed run. */
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

    /**
     * Parse start/end from the list entry's date block.
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function dates(Crawler $entry, \DateTimeZone $tz): array
    {
        $dateBlock = $entry->filter('span.listEntryDate');
        if ($dateBlock->count() === 0) {
            return [null, null];
        }

        $fromDate = $this->firstText($dateBlock, 'span.daydate.dayFrom');
        $fromTime = $this->firstText($dateBlock, 'span.timeFrom');
        $toDate = $this->firstText($dateBlock, 'span.dayTo.daydate');
        $toTime = $this->firstText($dateBlock, 'span.timeTo');

        $start = $this->buildDate($fromDate, $fromTime, $tz);
        if ($start === null) {
            return [null, null];
        }

        $end = $this->buildDate($toDate !== '' ? $toDate : '', $toTime, $tz);
        if ($end !== null && $end < $start) {
            $end = null;
        }

        return [$start, $end];
    }

    private function buildDate(string $date, string $time, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $date, $m)) {
            return null;
        }
        [$h, $i] = [0, 0];
        if (preg_match('/(\d{1,2}):(\d{2})/', $time, $tm)) {
            $h = (int) $tm[1];
            $i = (int) $tm[2];
        }

        return SafeDate::create((int) $m[3], (int) $m[2], (int) $m[1], $h, $i, $tz);
    }

    private function imageUrl(Crawler $entry): ?string
    {
        $imgNodes = $entry->filter('img.listEntryThumbnail');
        if ($imgNodes->count() === 0) {
            return null;
        }
        $src = $imgNodes->first()->attr('src');
        $src = is_string($src) ? trim($src) : '';
        if ($src === '' || str_starts_with($src, 'data:')) {
            return null;
        }

        return $this->absoluteUrl($src);
    }

    private function absoluteUrl(string $href): string
    {
        $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5);
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        return self::BASE.'/'.ltrim($href, '/');
    }

    private function firstText(Crawler $scope, string $selector): string
    {
        $nodes = $scope->filter($selector);
        if ($nodes->count() === 0) {
            return '';
        }

        return trim(preg_replace('/\s+/', ' ', $nodes->first()->text('')) ?? '');
    }

    private function mapCategory(string $title, string $description): ?string
    {
        $haystack = mb_strtolower($title.' '.$description);

        $map = [
            'musik' => ['konzert', 'musik', 'chor', 'band', 'orchester', 'jazz', 'klavier', 'singen'],
            'party' => ['party', 'disco', 'tanzabend', 'feier'],
            'buehne' => ['theater', 'kabarett', 'comedy', 'bühne', 'lesung', 'schauspiel', 'oper', 'musical'],
            'kunst' => ['ausstellung', 'kunst', 'galerie', 'vernissage', 'museum'],
            'familie' => ['kinder', 'familie', 'spielenachmittag', 'basteln', 'jugend', 'eltern'],
            'sport' => ['sport', 'lauf', 'turnier', 'fußball', 'fussball', 'fitness', 'wanderung', 'radeln', 'radfahren', 'radtour', 'boule', 'waldbaden'],
            'markt' => ['markt', 'flohmarkt', 'basar', 'trödel', 'spargelmarkt'],
            'genuss' => ['kulinarisch', 'wein', 'kochen', 'genuss', 'kaffee', 'café', 'cafe', 'essen', 'spargel'],
            'bildung' => ['vortrag', 'seminar', 'workshop', 'kurs', 'führung', 'fuehrung', 'stadtführung', 'infoabend', 'bildung', 'lesekreis', 'sprechstunde'],
        ];

        foreach ($map as $slug => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return $slug;
                }
            }
        }

        return null;
    }
}
