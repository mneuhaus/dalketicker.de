<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Scraper for the LWL Kloster Dalheim event calendar. */
#[AutoconfigureTag('app.source_importer')]
final class KlosterDalheimImporter implements SourceImporter
{
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.de)';
    private const VENUE = 'Stiftung Kloster Dalheim';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'kloster_dalheim';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl();
        if (!$url) {
            throw new \RuntimeException('kloster_dalheim source has no URL.');
        }

        $config = $source->getConfig();
        $fetchDetails = ($config['fetchDetails'] ?? true) !== false;
        $html = $this->fetch($url);
        $crawler = new Crawler($html, $url);
        $seen = [];

        foreach ($crawler->filter('.event-element')->each(fn (Crawler $node) => $node) as $entry) {
            $event = $this->mapEntry($entry, $url, $fetchDetails);
            if ($event === null) {
                continue;
            }
            $key = ($event->externalId ?? $event->dedupKey()).'|'.$event->startsAt->format('Y-m-d H:i');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

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

    private function mapEntry(Crawler $entry, string $listUrl, bool $fetchDetails): ?ImportedEvent
    {
        $title = $this->firstText($entry, '.event-title');
        $dateText = $this->firstText($entry, '.event-date');
        $timeText = $this->firstText($entry, '.event-time');
        if ($title === '' || $dateText === '') {
            return null;
        }

        [$start, $end, $allDay] = $this->parseDates($dateText, $timeText);
        if ($start === null) {
            return null;
        }

        // attr() throws on an empty node list, so a card without a link must
        // be tolerated (null detail URL) instead of aborting the whole run.
        $link = $entry->filter('a[href]');
        $detailUrl = $link->count() > 0 ? $this->absoluteUrl($link->first()->attr('href'), $listUrl) : null;
        $description = $this->descriptionFromList($entry);
        $imageUrl = $this->imageUrl($entry, $listUrl);
        $venue = self::VENUE;
        $categoryText = $this->firstText($entry, '.event-type');

        if ($fetchDetails && $detailUrl !== null) {
            $details = $this->fetchDetails($detailUrl);
            if ($details['description'] !== null) {
                $description = $details['description'];
            }
            if ($details['venue'] !== null) {
                $venue = $details['venue'];
            }
        }

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: $end,
            allDay: $allDay,
            description: $description,
            venueName: $venue,
            city: 'Lichtenau',
            locationText: $venue.', Am Kloster 9, 33165 Lichtenau-Dalheim',
            categorySlug: $this->mapCategory($categoryText, $title.' '.$description),
            sourceUrl: $detailUrl,
            imageUrl: $imageUrl,
            price: null,
            organizer: self::VENUE,
            externalId: 'kloster_dalheim:'.$this->idFromUrl($detailUrl ?? $title).':'.$start->format('Y-m-d H:i'),
            raw: [
                'dateText' => $dateText,
                'timeText' => $timeText,
                'categoryText' => $categoryText,
            ],
        );
    }

    /** @return array{description: ?string, venue: ?string} */
    private function fetchDetails(string $url): array
    {
        try {
            $crawler = new Crawler($this->fetch($url), $url);
        } catch (\Throwable) {
            return ['description' => null, 'venue' => null];
        }

        $description = $this->firstText($crawler, '.event-body .description, .event-body');
        if ($description !== '' && mb_strlen($description) > 2000) {
            $description = mb_substr($description, 0, 1997).'...';
        }

        $venue = null;
        foreach ($crawler->filter('.event-info .row')->each(fn (Crawler $node) => $node) as $row) {
            if (!str_contains($this->firstText($row, '.head'), 'Ort')) {
                continue;
            }
            $venue = $this->firstLineFromHtml($row->filter('.col-xs-10, .col-sm-11')->last()->html(''));
            break;
        }

        return [
            'description' => $description !== '' ? $description : null,
            'venue' => $venue !== '' ? $venue : null,
        ];
    }

    private function descriptionFromList(Crawler $entry): ?string
    {
        $parts = [];
        foreach (['.event-subtitle', '.event-description'] as $selector) {
            $text = $this->firstText($entry, $selector);
            if ($text !== '' && !\in_array($text, $parts, true)) {
                $parts[] = $text;
            }
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable, 2: bool}
     */
    private function parseDates(string $dateText, string $timeText): array
    {
        if (!preg_match_all('/(\d{1,2})\.(\d{1,2})\.(\d{4})?/', $dateText, $matches, \PREG_SET_ORDER)) {
            return [null, null, false];
        }

        $endMatch = $matches[\count($matches) - 1];
        $year = (int) (($endMatch[3] ?? '') ?: (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y'));
        $startYear = (int) (($matches[0][3] ?? '') ?: $year);
        $time = $this->parseTime($timeText);
        $start = SafeDate::create($startYear, (int) $matches[0][2], (int) $matches[0][1], $time[0], $time[1], new \DateTimeZone('Europe/Berlin'));
        if ($start === null) {
            return [null, null, false];
        }

        $allDay = $timeText === '';
        $end = null;
        if (\count($matches) > 1) {
            $end = SafeDate::create($year, (int) $endMatch[2], (int) $endMatch[1], $allDay ? 23 : $time[0], $allDay ? 59 : $time[1], new \DateTimeZone('Europe/Berlin'));
        }
        if ($end !== null && $end <= $start) {
            $end = null;
        }

        return [$start, $end, $allDay];
    }

    /** @return array{0:int,1:int} */
    private function parseTime(string $timeText): array
    {
        if (preg_match('/(\d{1,2}):(\d{2})/', $timeText, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        if (preg_match('/(\d{1,2})\s*Uhr/u', $timeText, $m)) {
            return [(int) $m[1], 0];
        }

        return [0, 0];
    }

    private function mapCategory(string $categoryText, string $text): ?string
    {
        $haystack = mb_strtolower($categoryText.' '.$text);
        $map = [
            'kunst' => ['ausstellung', 'kunst'],
            'musik' => ['konzert', 'musik', 'lieder', 'cello'],
            'buehne' => ['theater', 'komödie', 'schauspiel', 'zauberflöte', 'lesung'],
            'familie' => ['familie', 'kinder', 'zauberflöte', 'gartenparty'],
            'bildung' => ['führung', 'fuehrung', 'workshop', 'vortrag', 'kochen'],
            'markt' => ['advent', 'markt'],
        ];

        foreach ($map as $slug => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $slug;
                }
            }
        }

        return null;
    }

    private function imageUrl(Crawler $entry, string $baseUrl): ?string
    {
        $img = $entry->filter('.event-image img[src], img[src]')->first();
        if ($img->count() === 0) {
            return null;
        }
        $src = $this->clean((string) $img->attr('src'));

        return $src !== '' ? $this->absoluteUrl($src, $baseUrl) : null;
    }

    private function idFromUrl(string $url): string
    {
        $query = (string) parse_url($url, \PHP_URL_QUERY);
        parse_str($query, $params);
        $id = $params['id'] ?? null;
        if (\is_scalar($id) && (string) $id !== '') {
            return (string) $id;
        }

        return substr(sha1($url), 0, 16);
    }

    private function firstText(Crawler $scope, string $selector): string
    {
        $nodes = $scope->filter($selector);
        if ($nodes->count() === 0) {
            return '';
        }

        return $this->clean($nodes->first()->text(''));
    }

    private function firstLineFromHtml(string $html): ?string
    {
        $html = preg_replace('~<br\s*/?>~i', "\n", $html) ?? $html;
        $text = trim(strip_tags($html));
        foreach (preg_split('/\R+/', $text) ?: [] as $line) {
            $line = $this->clean($line);
            if ($line !== '' && !str_contains($line, 'Ort:')) {
                return mb_substr($line, 0, 150);
            }
        }

        return null;
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
}
