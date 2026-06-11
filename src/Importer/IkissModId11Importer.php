<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Scraper for municipal IKISS calendars using ModID=11. The list page contains
 * lightweight rows; detail pages expose label/value blocks with date, time,
 * organizer, place and description.
 */
#[AutoconfigureTag('app.source_importer')]
final class IkissModId11Importer implements SourceImporter
{
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.de)';
    private const DEFAULT_MAX_EVENTS = 250;
    private const DEFAULT_MAX_PAGES = 8;

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'ikiss_modid11';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl();
        if (!$url) {
            throw new \RuntimeException('ikiss_modid11 source has no URL.');
        }

        $config = $source->getConfig();
        $maxEvents = (int) ($config['maxEvents'] ?? self::DEFAULT_MAX_EVENTS);
        $maxPages = (int) ($config['maxPages'] ?? self::DEFAULT_MAX_PAGES);
        $html = $this->fetch($url);
        $baseUrl = $this->origin($url) ?? $url;
        $pageUrls = [$url => true];
        foreach ($this->paginationUrls($html, $baseUrl) as $pageUrl) {
            if (\count($pageUrls) >= $maxPages) {
                break;
            }
            $pageUrls[$pageUrl] = true;
        }

        $seen = [];
        $count = 0;
        foreach (array_keys($pageUrls) as $pageUrl) {
            $pageHtml = $pageUrl === $url ? $html : ($this->tryFetch($pageUrl) ?? '');
            if ($pageHtml === '') {
                continue;
            }

            foreach ($this->listEntries($pageHtml) as $entry) {
                if ($count >= $maxEvents) {
                    break 2;
                }

                $detailUrl = $this->absoluteUrl($entry['href'], $baseUrl);
                if ($detailUrl === null || isset($seen[$detailUrl])) {
                    continue;
                }
                $seen[$detailUrl] = true;

                $detailHtml = $this->tryFetch($detailUrl) ?? '';
                $event = $detailHtml !== ''
                    ? $this->mapDetail($detailHtml, $detailUrl, $source, $entry['title'])
                    : $this->mapListFallback($entry, $detailUrl, $source);
                if ($event === null) {
                    continue;
                }

                ++$count;
                yield $event;
            }
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

        return $this->ensureUtf8($content);
    }

    private function tryFetch(string $url): ?string
    {
        try {
            return $this->fetch($url);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * @return list<array{date:string,href:string,title:string}>
     */
    private function listEntries(string $html): array
    {
        if (!preg_match_all(
            '~<a\s+name="nr_\d+"></a>\s*([^<]+?)<br\s*/>\s*<a\s+href="([^"]+)"[^>]*>(.*?)</a>~isu',
            $html,
            $matches,
            \PREG_SET_ORDER
        )) {
            return [];
        }

        $entries = [];
        foreach ($matches as $m) {
            $title = $this->clean(strip_tags($m[3]));
            if ($title === '') {
                continue;
            }
            $entries[] = [
                'date' => $this->clean($m[1]),
                'href' => html_entity_decode($m[2], \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
                'title' => $title,
            ];
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function paginationUrls(string $html, string $baseUrl): array
    {
        if (!preg_match_all('~<a\s+href="([^"]+)"[^>]+class="[^"]*\bpn_step\b[^"]*"~isu', $html, $matches)) {
            return [];
        }

        $urls = [];
        foreach ($matches[1] as $href) {
            $url = $this->absoluteUrl($href, $baseUrl);
            if ($url !== null) {
                $urls[$url] = true;
            }
        }

        return array_keys($urls);
    }

    private function mapDetail(string $html, string $detailUrl, Source $source, string $fallbackTitle): ?ImportedEvent
    {
        $crawler = new Crawler($html, $detailUrl);
        $title = $this->firstText($crawler, 'h4.mtp_header') ?: $fallbackTitle;
        if ($title === '') {
            return null;
        }

        $dateText = $this->labelValue($html, 'Datum');
        [$start, $end, $allDay] = $this->parseDateAndTime($dateText, $this->labelValue($html, 'Uhrzeit'));
        if ($start === null) {
            return null;
        }

        $city = $this->labelValue($html, 'Ortschaft') ?: ($source->getConfig()['city'] ?? null);
        $organizer = $this->organizer($html);
        $description = $this->firstText($crawler, '.mtp_f_text');
        $description = preg_replace('/^[-\s]*$/u', '', $description) ? $description : null;
        $price = $this->labelValue($html, 'Kosten') ?: null;
        $externalId = $this->externalId($detailUrl, $start);

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: $end,
            allDay: $allDay,
            description: $description,
            venueName: null,
            city: $city,
            locationText: $city,
            categorySlug: $this->mapCategory($title.' '.($description ?? '')),
            sourceUrl: $detailUrl,
            imageUrl: null,
            price: $price,
            organizer: $organizer,
            externalId: $externalId,
            raw: [
                'date' => $dateText,
                'time' => $this->labelValue($html, 'Uhrzeit'),
            ],
        );
    }

    /**
     * @param array{date:string,href:string,title:string} $entry
     */
    private function mapListFallback(array $entry, string $detailUrl, Source $source): ?ImportedEvent
    {
        [$start, $end, $allDay] = $this->parseDateAndTime($entry['date'], '');
        if ($start === null) {
            return null;
        }

        return new ImportedEvent(
            title: $entry['title'],
            startsAt: $start,
            endsAt: $end,
            allDay: $allDay,
            city: $source->getConfig()['city'] ?? null,
            sourceUrl: $detailUrl,
            externalId: $this->externalId($detailUrl, $start),
        );
    }

    private function labelValue(string $html, string $label): string
    {
        if (!preg_match(
            '~<div class="mtp_dl">\s*'.preg_quote($label, '~').':\s*</div>\s*<div class="mtp_dr">(.*?)</div>~isu',
            $html,
            $m
        )) {
            return '';
        }

        return $this->clean(strip_tags($m[1]));
    }

    private function organizer(string $html): ?string
    {
        if (!preg_match('~<div class="mtp_adr_sd">\s*(.*?)</div>~isu', $html, $m)) {
            return null;
        }

        $lines = preg_split('/\s*\R\s*/u', trim(str_replace(['<br />', '<br>', '<br/>'], "\n", $m[1]))) ?: [];
        foreach ($lines as $line) {
            $text = $this->clean(strip_tags($line));
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable, 2: bool}
     */
    private function parseDateAndTime(string $dateText, string $timeText): array
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        if (!preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $dateText, $first)) {
            return [null, null, false];
        }

        $second = null;
        if (preg_match('/bis\s+(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $dateText, $m)) {
            $second = $m;
        }

        $times = [];
        if (preg_match_all('/(\d{1,2}):(\d{2})\s*Uhr/u', $timeText, $tm, \PREG_SET_ORDER)) {
            foreach ($tm as $match) {
                $times[] = [(int) $match[1], (int) $match[2]];
            }
        }

        $startTime = $times[0] ?? [0, 0];
        $start = SafeDate::create((int) $first[3], (int) $first[2], (int) $first[1], $startTime[0], $startTime[1], $tz);
        $end = null;
        if ($second !== null) {
            $endTime = $times[1] ?? ($times === [] ? [23, 59] : [0, 0]);
            $end = SafeDate::create((int) $second[3], (int) $second[2], (int) $second[1], $endTime[0], $endTime[1], $tz);
        } elseif (isset($times[1])) {
            $end = SafeDate::create((int) $first[3], (int) $first[2], (int) $first[1], $times[1][0], $times[1][1], $tz);
        }
        if ($end !== null && $end <= $start) {
            $end = null;
        }

        return [$start, $end, $times === []];
    }

    private function externalId(string $detailUrl, \DateTimeImmutable $start): string
    {
        $fid = '';
        parse_str((string) parse_url($detailUrl, \PHP_URL_QUERY), $query);
        if (\is_string($query['FID'] ?? null)) {
            $fid = $query['FID'];
        }

        return 'ikiss:'.($fid !== '' ? $fid : substr(sha1($detailUrl), 0, 16)).':'.$start->format('Y-m-d-H-i');
    }

    private function mapCategory(string $text): string
    {
        $haystack = mb_strtolower($text);
        $rules = [
            'musik' => ['konzert', 'musik', 'chor', 'klang'],
            'sport' => ['sport', 'radtour', 'lauf', 'schwimm', 'stadtradeln'],
            'kunst' => ['kunst', 'ausstellung', 'galerie'],
            'markt' => ['markt', 'fest', 'sommer'],
            'familie' => ['familie', 'kinder'],
            'bildung' => ['lesung', 'vortrag', 'führung', 'workshop', 'klima'],
        ];
        foreach ($rules as $slug => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $slug;
                }
            }
        }

        return 'sonstiges';
    }

    private function firstText(Crawler $crawler, string $selector): string
    {
        $nodes = $crawler->filter($selector);

        return $nodes->count() > 0 ? $this->clean($nodes->first()->text('')) : '';
    }

    private function absoluteUrl(string $href, string $baseUrl): ?string
    {
        $href = html_entity_decode($href, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $origin = $this->origin($baseUrl);
        if ($origin === null) {
            return null;
        }
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        return rtrim($origin, '/').'/'.$href;
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private function clean(string $value): string
    {
        $value = html_entity_decode($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\x{00a0}/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function ensureUtf8(string $content): string
    {
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        return mb_convert_encoding($content, 'UTF-8', 'Windows-1252,ISO-8859-1,UTF-8');
    }
}
