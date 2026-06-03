<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke HTML scraper for the events of the Isselhorster Werbegemeinschaft
 * (https://www.gt-isselhorst.de/veranstaltungen/) — Isselhorst is a district
 * of Gütersloh.
 *
 * The site runs TYPO3 with the "gbevents" extension and renders server-side
 * HTML (no ICS/RSS/JSON-LD feed). The listing page shows each upcoming event
 * as a `div.row.events-list` block carrying the date, organizer, title with a
 * detail link and a teaser. Time, full location, description and organizer
 * only live on the detail page, so we follow the detail link per event.
 *
 * Detail page (`div.gbevents`):
 *   - `<h1>` event title
 *   - label/value rows; the date and time values carry class `.time`
 *     ("DD.MM.YYYY", "14.00 Uhr"), the rest are matched by their label
 *     ("Ort:", "Beschreibung:")
 *   - longer body text in `p.bodytext`
 *   - organizer in `div.organizer` ("Veranstalter: …")
 *   - image in `.event-img img`
 *
 * Optional source config:
 *   - city:       default city (defaults to "Gütersloh")
 *   - maxEvents:  cap on number of events (default 100)
 */
#[AutoconfigureTag('app.source_importer')]
final class GtIsselhorstImporter implements SourceImporter
{
    // The site has no valid HTTPS certificate for this host, so we stay on http.
    private const BASE = 'http://www.gt-isselhorst.de';
    private const DEFAULT_LIST = self::BASE.'/veranstaltungen/';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.de)';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'gt_isselhorst';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $tz = new \DateTimeZone('Europe/Berlin');
        $city = $config['city'] ?? 'Gütersloh';
        $maxEvents = (int) ($config['maxEvents'] ?? 100);

        $listUrl = $source->getUrl() ?: self::DEFAULT_LIST;
        $html = $this->fetch($listUrl);
        if ($html === null) {
            return;
        }

        $crawler = new Crawler($html, $listUrl);
        $seen = [];
        $count = 0;

        foreach ($crawler->filter('div.row.events-list') as $node) {
            if ($count >= $maxEvents) {
                break;
            }
            $entry = new Crawler($node);

            // The teaser block's title anchor is the canonical detail link.
            $link = $entry->filter('p.events-list a');
            if ($link->count() === 0) {
                continue;
            }
            $link = $link->first();
            $listTitle = trim($link->text(''));
            $href = $link->attr('href') ?? '';
            if ($href === '') {
                continue;
            }
            $detailUrl = $this->absoluteUrl($href);

            $externalId = 'gt_isselhorst:'.$this->eventId($detailUrl, $detailUrl);
            if (isset($seen[$externalId])) {
                continue;
            }
            $seen[$externalId] = true;

            // Organizer + image are already on the list; the rest needs the
            // detail page.
            $listOrganizer = $this->listOrganizer($entry);
            $listImage = $this->imageUrl($entry);

            $detail = $this->parseDetail($detailUrl, $tz);
            if ($detail === null || $detail['start'] === null) {
                continue;
            }
            ++$count;

            $title = $detail['title'] !== '' ? $detail['title'] : $listTitle;
            $start = $detail['start'];
            $end = $detail['end'];
            $allDay = $start->format('H:i') === '00:00'
                && ($end === null || $end->format('H:i') === '00:00');

            $location = $detail['location'];
            $description = $detail['description'];
            $organizer = $detail['organizer'] !== '' ? $detail['organizer'] : $listOrganizer;

            yield new ImportedEvent(
                title: $title,
                startsAt: $start,
                endsAt: $end,
                allDay: $allDay,
                description: $description !== '' ? $description : null,
                venueName: $location !== '' ? $location : null,
                city: $city,
                locationText: $location !== '' ? $location : 'Isselhorst',
                categorySlug: $this->mapCategory($title, $description),
                sourceUrl: $detailUrl,
                imageUrl: $detail['image'] ?? $listImage,
                organizer: $organizer !== '' ? $organizer : null,
                externalId: $externalId,
                raw: [
                    'detailUrl' => $detailUrl,
                    'listUrl' => $listUrl,
                ],
            );
        }
    }

    /**
     * Fetch + parse one detail page.
     *
     * @return array{title:string, start:?\DateTimeImmutable, end:?\DateTimeImmutable, location:string, description:string, organizer:string, image:?string}|null
     */
    private function parseDetail(string $url, \DateTimeZone $tz): ?array
    {
        $html = $this->fetch($url);
        if ($html === null) {
            return null;
        }

        $crawler = new Crawler($html, $url);
        $scope = $crawler->filter('div.gbevents');
        if ($scope->count() === 0) {
            return null;
        }
        $scope = $scope->first();

        $title = $this->firstText($scope, 'h1');

        // The two `.time` value cells are date and time, in that order.
        $times = $scope->filter('.time');
        $dateText = $times->count() > 0 ? trim($times->eq(0)->text('')) : '';
        $timeText = $times->count() > 1 ? trim($times->eq(1)->text('')) : '';
        [$start, $end] = $this->dates($dateText, $timeText, $tz);

        $labels = $this->labelMap($scope);
        $location = $labels['ort'] ?? '';

        // Short "Beschreibung:" value plus any longer body-text paragraphs.
        $descParts = [];
        if (($labels['beschreibung'] ?? '') !== '') {
            $descParts[] = $labels['beschreibung'];
        }
        foreach ($scope->filter('p.bodytext') as $p) {
            $text = trim(preg_replace('/\s+/', ' ', (new Crawler($p))->text('')) ?? '');
            if ($text !== '' && !\in_array($text, $descParts, true)) {
                $descParts[] = $text;
            }
        }
        $description = trim(implode("\n\n", $descParts));

        $organizer = $this->firstText($scope, '.organizer');
        $organizer = trim(preg_replace('/^Veranstalter:\s*/u', '', $organizer) ?? $organizer);

        $image = $this->detailImage($scope);

        return [
            'title' => $title,
            'start' => $start,
            'end' => $end,
            'location' => $location,
            'description' => $description,
            'organizer' => $organizer,
            'image' => $image,
        ];
    }

    /**
     * Build a lower-cased label => value map from the detail rows. Labels are
     * the cells ending in ":"; their value is the next cell in the same row.
     *
     * @return array<string, string>
     */
    private function labelMap(Crawler $scope): array
    {
        $map = [];
        foreach ($scope->children('div.row') as $rowNode) {
            $cols = (new Crawler($rowNode))->children('div');
            $texts = [];
            foreach ($cols as $col) {
                $texts[] = trim(preg_replace('/\s+/', ' ', (new Crawler($col))->text('')) ?? '');
            }
            for ($i = 0; $i < \count($texts) - 1; ++$i) {
                if (str_ends_with($texts[$i], ':')) {
                    $key = mb_strtolower(rtrim($texts[$i], ': '));
                    $value = $texts[$i + 1];
                    if ($value !== '' && !isset($map[$key])) {
                        $map[$key] = $value;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Parse start/end from the detail page's date + time cells. The date cell
     * may carry a "DD.MM.YYYY – DD.MM.YYYY" range; the time cell a leading
     * "HH.MM" (or "HH:MM").
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function dates(string $dateText, string $timeText, \DateTimeZone $tz): array
    {
        if (!preg_match_all('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $dateText, $m, PREG_SET_ORDER) || $m === []) {
            return [null, null];
        }

        [$h, $i] = [0, 0];
        if (preg_match('/(\d{1,2})[.:](\d{2})/', $timeText, $tm)) {
            $h = (int) $tm[1];
            $i = (int) $tm[2];
        }

        $first = $m[0];
        $start = (new \DateTimeImmutable('now', $tz))
            ->setDate((int) $first[3], (int) $first[2], (int) $first[1])
            ->setTime($h, $i);

        $end = null;
        if (\count($m) > 1) {
            $last = $m[\count($m) - 1];
            $endDay = (new \DateTimeImmutable('now', $tz))
                ->setDate((int) $last[3], (int) $last[2], (int) $last[1])
                ->setTime($h, $i);
            if ($endDay > $start) {
                $end = $endDay;
            }
        }

        return [$start, $end];
    }

    private function listOrganizer(Crawler $entry): string
    {
        $p = $entry->filter('p.events-list');
        if ($p->count() === 0) {
            return '';
        }
        // Layout: <span>date</span><br> organizer <br> <a>title</a> …
        $html = $p->first()->html('');
        if (preg_match('#</span>\s*<br\s*/?>(.*?)<br#is', $html, $mm)) {
            return trim(preg_replace('/\s+/', ' ', strip_tags($mm[1])) ?? '');
        }

        return '';
    }

    private function imageUrl(Crawler $entry): ?string
    {
        return $this->imgSrc($entry, 'img.events-img');
    }

    private function detailImage(Crawler $scope): ?string
    {
        return $this->imgSrc($scope, '.event-img img');
    }

    private function imgSrc(Crawler $scope, string $selector): ?string
    {
        $nodes = $scope->filter($selector);
        if ($nodes->count() === 0) {
            return null;
        }
        $src = $nodes->first()->attr('src');
        $src = is_string($src) ? trim($src) : '';
        if ($src === '' || str_starts_with($src, 'data:')) {
            return null;
        }

        return $this->absoluteUrl($src);
    }

    private function eventId(string $detailUrl, string $fallback): string
    {
        $query = (string) parse_url($detailUrl, PHP_URL_QUERY);
        $query = html_entity_decode($query, ENT_QUOTES | ENT_HTML5);
        parse_str($query, $params);
        $id = $params['tx_gbevents_main']['event'] ?? null;

        return is_scalar($id) && (string) $id !== '' ? (string) $id : sha1($fallback);
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
            'musik' => ['konzert', 'musik', 'chor', 'band', 'orchester', 'jazz', 'klavier', 'singen', 'platzkonzert'],
            'party' => ['party', 'disco', 'tanzabend', 'feier', 'oktoberfest'],
            'buehne' => ['theater', 'kabarett', 'comedy', 'bühne', 'lesung', 'schauspiel', 'oper', 'musical'],
            'kunst' => ['ausstellung', 'kunst', 'galerie', 'vernissage', 'museum'],
            'familie' => ['kinder', 'familie', 'spielenachmittag', 'basteln', 'jugend', 'eltern', 'martinszug', 'osterfeuer'],
            'sport' => ['sport', 'lauf', 'turnier', 'fußball', 'fussball', 'fitness', 'wanderung', 'radeln', 'radfahren', 'radtour'],
            'markt' => ['markt', 'flohmarkt', 'basar', 'trödel', 'kirmes', 'weihnachtsmarkt', 'fest', 'schützen'],
            'genuss' => ['kulinarisch', 'wein', 'kochen', 'genuss', 'kaffee', 'café', 'cafe', 'grünkohl', 'frühschoppen'],
            'bildung' => ['vortrag', 'seminar', 'workshop', 'kurs', 'führung', 'fuehrung', 'infoabend', 'bildung', 'klön'],
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
