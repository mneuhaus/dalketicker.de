<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Shared scraper for Weblication-style municipal calendars rendering
 * `listEntryObject-eventMulti` list entries. Used by several Kreis Paderborn
 * municipalities and museums.
 */
#[AutoconfigureTag('app.source_importer')]
final class KommunalEventsImporter implements SourceImporter
{
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.de)';
    private const DEFAULT_MAX_EVENTS = 800;

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'kommunal_events';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl();
        if (!$url) {
            throw new \RuntimeException('kommunal_events source has no URL.');
        }

        $config = $source->getConfig();
        $city = isset($config['city']) && \is_string($config['city']) ? $config['city'] : null;
        $maxEvents = (int) ($config['maxEvents'] ?? self::DEFAULT_MAX_EVENTS);
        $html = $this->fetch($url);
        $crawler = new Crawler($html, $url);

        $seen = [];
        $count = 0;
        foreach ($crawler->filter('li.listEntryObject-eventMulti')->each(fn (Crawler $node) => $node) as $entry) {
            if ($count >= $maxEvents) {
                break;
            }

            $event = $this->mapEntry($entry, $source, $url, $city);
            if ($event === null) {
                continue;
            }

            $key = ($event->externalId ?? $event->dedupKey()).'|'.$event->startsAt->format('Y-m-d H:i');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            ++$count;

            yield $event;
        }
    }

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

    private function mapEntry(Crawler $entry, Source $source, string $listUrl, ?string $city): ?ImportedEvent
    {
        $link = $entry->filter('.listEntryTitle a[href], h3 a[href], h4 a[href]')->first();
        if ($link->count() === 0) {
            return null;
        }

        $title = $this->clean($link->text(''));
        $href = $link->attr('href');
        $detailUrl = $this->absoluteUrl($href, $listUrl);
        if ($title === '' || $detailUrl === null) {
            return null;
        }

        [$start, $end, $allDay] = $this->dates($entry);
        if ($start === null) {
            return null;
        }

        $venue = $this->firstText($entry, '.listEntryLocation');
        if ($venue === '') {
            $venue = $this->firstText($entry, '.listEntryCategoryText');
        }
        $description = $this->firstText($entry, '.listEntryDescription');
        $imageUrl = $this->imageUrl($entry, $listUrl);
        $externalId = $source->getKey().':'.$this->idFromUrl($detailUrl).':'.$start->format('Y-m-d');

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: $end,
            allDay: $allDay,
            description: $description !== '' ? $description : null,
            venueName: $venue !== '' ? $venue : null,
            city: $city,
            locationText: $venue !== '' ? $venue : null,
            categorySlug: $this->mapCategory($title, $description.' '.$venue),
            sourceUrl: $detailUrl,
            imageUrl: $imageUrl,
            price: null,
            organizer: $source->getName(),
            externalId: $externalId,
            raw: [
                'listUrl' => $listUrl,
                'detailUrl' => $detailUrl,
            ],
        );
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable, 2: bool}
     */
    private function dates(Crawler $entry): array
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $dateBlock = $entry->filter('.listEntryDate')->first();
        if ($dateBlock->count() === 0) {
            return [null, null, false];
        }

        $fromDate = $this->firstText($dateBlock, '.dayFrom.daydate, .dayFrom.dayDate');
        $toDate = $this->firstText($dateBlock, '.dayTo.daydate, .dayTo.dayDate');
        $fromTime = $this->firstText($dateBlock, '.timeFrom');
        $toTime = $this->firstText($dateBlock, '.timeTo');
        $timeText = $this->firstText($dateBlock, '.time');

        if ($fromDate === '') {
            $fromDate = $this->firstText($dateBlock, '.day');
        }
        if ($toDate === '' && preg_match('/\d{1,2}\.\d{1,2}\.\d{4}/', $timeText)) {
            $toDate = $timeText;
        }
        if ($fromTime === '' && $timeText !== '' && !preg_match('/\d{1,2}\.\d{1,2}\.\d{4}/', $timeText)) {
            $fromTime = $timeText;
        }

        $start = $this->parseDate($fromDate, $this->parseTime($fromTime), $tz);
        if ($start === null) {
            return [null, null, false];
        }

        $hasStartTime = $this->parseTime($fromTime) !== null;
        $end = null;
        if ($toDate !== '') {
            $endTime = $this->parseTime($toTime) ?? ($hasStartTime ? null : [23, 59]);
            $end = $this->parseDate($toDate, $endTime, $tz);
        } elseif ($toTime !== '') {
            $end = $this->parseDate($fromDate, $this->parseTime($toTime), $tz);
        }
        if ($end !== null && $end < $start) {
            $end = null;
        }

        return [$start, $end, !$hasStartTime];
    }

    /** @param array{0:int,1:int}|null $time */
    private function parseDate(string $date, ?array $time, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $date = $this->clean($date);
        if ($date === '') {
            return null;
        }

        $months = [
            'januar' => 1, 'februar' => 2, 'märz' => 3, 'maerz' => 3,
            'april' => 4, 'mai' => 5, 'juni' => 6, 'juli' => 7,
            'august' => 8, 'september' => 9, 'oktober' => 10,
            'november' => 11, 'dezember' => 12,
        ];

        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $date, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];
        } elseif (preg_match('/(\d{1,2})\.?\s+([A-Za-zäöüÄÖÜ]+)\s+(\d{4})/u', $date, $m)) {
            $month = $months[mb_strtolower($m[2])] ?? null;
            if ($month === null) {
                return null;
            }
            $day = (int) $m[1];
            $year = (int) $m[3];
        } else {
            return null;
        }

        [$hour, $minute] = $time ?? [0, 0];

        return SafeDate::create($year, $month, $day, $hour, $minute, $tz);
    }

    /** @return array{0:int,1:int}|null */
    private function parseTime(string $time): ?array
    {
        if (!preg_match('/(\d{1,2}):(\d{2})/', $time, $m)) {
            return null;
        }

        return [(int) $m[1], (int) $m[2]];
    }

    private function imageUrl(Crawler $entry, string $baseUrl): ?string
    {
        $img = $entry->filter('img.listEntryThumbnail[src], img[src]')->first();
        if ($img->count() === 0) {
            return null;
        }
        $src = trim((string) $img->attr('src'));
        if ($src === '' || str_starts_with($src, 'data:')) {
            return null;
        }

        return $this->absoluteUrl($src, $baseUrl);
    }

    private function idFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = basename($path, '.php');

        return $base !== '' ? $base : substr(sha1($url), 0, 16);
    }

    private function firstText(Crawler $scope, string $selector): string
    {
        $nodes = $scope->filter($selector);
        if ($nodes->count() === 0) {
            return '';
        }

        return $this->clean($nodes->first()->text(''));
    }

    private function clean(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\x{00a0}/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function absoluteUrl(?string $href, string $baseUrl): ?string
    {
        if ($href === null || trim($href) === '') {
            return null;
        }
        $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('#^https?://#i', $href)) {
            return $href;
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

    private function mapCategory(string $title, string $text): ?string
    {
        $haystack = mb_strtolower($title.' '.$text);
        $map = [
            'musik' => ['konzert', 'musik', 'chor', 'band', 'orchester', 'jazz', 'klavier', 'singen', 'lieder'],
            'party' => ['party', 'disco', 'tanzabend'],
            'buehne' => ['theater', 'kabarett', 'comedy', 'bühne', 'lesung', 'schauspiel', 'oper', 'musical'],
            'kunst' => ['ausstellung', 'kunst', 'galerie', 'vernissage', 'museum'],
            'familie' => ['kinder', 'familie', 'basteln', 'jugend', 'eltern'],
            'sport' => ['sport', 'lauf', 'turnier', 'fußball', 'fussball', 'wanderung', 'radtour', 'radfahren'],
            'markt' => ['markt', 'flohmarkt', 'basar', 'trödel', 'stadtfest', 'schützenfest', 'schuetzenfest'],
            'genuss' => ['kulinarisch', 'wein', 'kochen', 'genuss', 'brunch', 'essen'],
            'bildung' => ['vortrag', 'seminar', 'workshop', 'kurs', 'führung', 'fuehrung', 'stadtführung', 'bildung'],
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
