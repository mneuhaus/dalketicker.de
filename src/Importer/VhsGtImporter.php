<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Importer for the VHS Gütersloh course catalogue (https://www.vhs-gt.de).
 *
 * The site is a TYPO3 install driven by KuferWeb course management. The course
 * list at /kurssuche/liste is rendered server-side as a plain HTML table
 * (no JS required); each row links to a detail page /kurssuche/kurs/<slug>/<knr>
 * and carries the German date string "Do. 28.05.2026, 10.00 Uhr".
 *
 * There is no aggregated feed and no JSON-LD, but every course exposes a valid
 * per-course ICS export at /fileadmin/kuferweb/webbasys/ics.php?knr=<KNR> which
 * we use opportunistically to fill in a precise end time when one is missing
 * from the list.
 *
 * Optional source config:
 *   - maxPages:   how many list pages to walk (default 8, hard cap 40)
 *   - enrichIcs:  whether to fetch the per-course ICS for end times (default true)
 *   - category:   fallback category slug (default "bildung")
 */
#[AutoconfigureTag('app.source_importer')]
final class VhsGtImporter implements SourceImporter
{
    private const BASE = 'https://www.vhs-gt.de';
    private const LIST_PATH = '/kurssuche/liste';
    private const ICS_PATH = '/fileadmin/kuferweb/webbasys/ics.php';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)';
    private const PAGE_CAP = 40;

    /** German weekday-abbreviation prefixes we strip from the date string. */
    private const WEEKDAY_PREFIX = '/^(?:Mo|Di|Mi|Do|Fr|Sa|So)\.\s*/u';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'vhs_gt';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $maxPages = max(1, min(self::PAGE_CAP, (int) ($config['maxPages'] ?? 8)));
        $enrichIcs = (bool) ($config['enrichIcs'] ?? true);
        $fallbackCategory = $config['category'] ?? 'bildung';
        $city = $config['city'] ?? 'Gütersloh';
        $tz = new \DateTimeZone('Europe/Berlin');

        $listUrl = ($source->getUrl() ?: self::BASE.self::LIST_PATH);

        $seen = [];
        for ($page = 0; $page < $maxPages; ++$page) {
            try {
                $body = $this->fetch($listUrl);
            } catch (\Throwable) {
                break;
            }

            $crawler = new Crawler($body, $listUrl);
            $rows = $crawler->filter('tr.kw-table-row');
            if ($rows->count() === 0) {
                break;
            }

            $lastKnr = null;
            foreach ($rows as $node) {
                $row = new Crawler($node);
                $event = $this->mapRow($row, $tz, $city, $fallbackCategory, $enrichIcs);
                if ($event === null) {
                    continue;
                }
                $lastKnr = $event->externalId ?? $lastKnr;
                if ($event->externalId !== null) {
                    if (isset($seen[$event->externalId])) {
                        continue;
                    }
                    $seen[$event->externalId] = true;
                }
                yield $event;
            }

            // KuferWeb paginates by passing the last visible course number with
            // browse=forward. Stop when there is no further marker.
            if ($lastKnr === null) {
                break;
            }
            $next = self::BASE.self::LIST_PATH.'?knr='.rawurlencode($lastKnr).'&browse=forward';
            if ($next === $listUrl) {
                break;
            }
            $listUrl = $next;
        }
    }

    private function mapRow(
        Crawler $row,
        \DateTimeZone $tz,
        string $city,
        ?string $fallbackCategory,
        bool $enrichIcs,
    ): ?ImportedEvent {
        $titleNode = $row->filter('th a, td a')->first();
        $title = $titleNode->count() > 0 ? $this->clean($titleNode->text('')) : '';

        $href = $row->attr('data-href') ?? ($titleNode->count() > 0 ? $titleNode->attr('href') : null);
        $sourceUrl = $this->absolute($href);

        $dateText = $this->cellText($row, 'columnheader-datum');
        $ort = $this->cellText($row, 'columnheader-ort');
        $knr = $this->cleanKnr($this->cellText($row, 'columnheader-nummer'));

        if ($title === '' || $dateText === '') {
            return null;
        }

        $start = $this->parseGermanDateTime($dateText, $tz);
        if ($start === null) {
            return null;
        }
        $allDay = !$this->hasTime($dateText);

        $end = null;
        $location = $ort !== '' ? $ort : null;
        if ($enrichIcs && $knr !== null) {
            $detail = $this->fetchIcsDetail($knr, $tz);
            if ($detail !== null) {
                $start = $detail['start'] ?? $start;
                $end = $detail['end'] ?? null;
                if (($detail['location'] ?? '') !== '') {
                    $location = $detail['location'];
                }
                if ($detail['start'] !== null) {
                    $allDay = false;
                }
            }
        }

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: $end,
            allDay: $allDay,
            description: null,
            venueName: $location,
            city: $city,
            locationText: $location,
            categorySlug: $this->mapCategory($title, $sourceUrl, $fallbackCategory),
            sourceUrl: $sourceUrl,
            imageUrl: null,
            price: null,
            organizer: 'VHS Gütersloh',
            externalId: $knr,
            raw: [
                'knr' => $knr,
                'date' => $dateText,
                'ort' => $ort,
            ],
        );
    }

    /** Extract the trimmed text of the cell whose `headers` references the given column id. */
    private function cellText(Crawler $row, string $columnId): string
    {
        $cells = $row->filter('td');
        foreach ($cells as $node) {
            $cell = new Crawler($node);
            $headers = $cell->attr('headers') ?? '';
            if (!str_contains($headers, $columnId)) {
                continue;
            }
            // Drop the mobile-only inline label ("Wann:", "Wo:", "Nr.:").
            $cell->filter('.kw-table-label')->each(static function (Crawler $label): void {
                $domNode = $label->getNode(0);
                $domNode?->parentNode?->removeChild($domNode);
            });

            return $this->clean($cell->text(''));
        }

        return '';
    }

    /**
     * Parse "Do. 28.05.2026, 10.00 Uhr" (weekday abbr + DD.MM.YYYY + HH.MM Uhr).
     * The weekday and the time are both optional.
     */
    private function parseGermanDateTime(string $text, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $text = preg_replace(self::WEEKDAY_PREFIX, '', $text) ?? $text;

        if (!preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $text, $m)) {
            return null;
        }
        $day = (int) $m[1];
        $month = (int) $m[2];
        $year = (int) $m[3];

        $hour = 0;
        $minute = 0;
        if (preg_match('/(\d{1,2})[.:](\d{2})\s*Uhr/u', $text, $t)) {
            $hour = (int) $t[1];
            $minute = (int) $t[2];
        }

        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $hour > 23 || $minute > 59) {
            return null;
        }

        $date = (new \DateTimeImmutable('now', $tz))->setDate($year, $month, $day)->setTime($hour, $minute);
        // checkdate-style guard against e.g. 31.02.
        if ((int) $date->format('d') !== $day || (int) $date->format('n') !== $month) {
            return null;
        }

        return $date;
    }

    private function hasTime(string $text): bool
    {
        return (bool) preg_match('/\d{1,2}[.:]\d{2}\s*Uhr/u', $text);
    }

    /**
     * Fetch the per-course ICS export and pull out start/end/location.
     *
     * @return array{start: ?\DateTimeImmutable, end: ?\DateTimeImmutable, location: string}|null
     */
    private function fetchIcsDetail(string $knr, \DateTimeZone $tz): ?array
    {
        try {
            $ics = $this->fetch(self::BASE.self::ICS_PATH.'?knr='.rawurlencode($knr));
        } catch (\Throwable) {
            return null;
        }
        if (!str_contains($ics, 'BEGIN:VEVENT')) {
            return null;
        }

        $ics = preg_replace('/\r\n[ \t]/', '', str_replace(["\r\n", "\r"], "\n", $ics)) ?? $ics;
        if (!preg_match('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $vm)) {
            return null;
        }
        $block = $vm[1];

        $start = $this->parseIcsDate($this->icsField($block, 'DTSTART'), $tz);
        $end = $this->parseIcsDate($this->icsField($block, 'DTEND'), $tz);
        $location = $this->clean($this->icsField($block, 'LOCATION'));
        // KuferWeb pads LOCATION with trailing ", , " separators — tidy them.
        $location = trim(preg_replace('/(,\s*)+$/', '', $location) ?? $location);

        return ['start' => $start, 'end' => $end, 'location' => $location];
    }

    private function icsField(string $block, string $name): string
    {
        if (preg_match('/^'.preg_quote($name, '/').'(?:;[^:\n]*)?:(.*)$/m', $block, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    private function parseIcsDate(string $value, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{8})T(\d{6})Z$/', $value, $m)) {
            return (new \DateTimeImmutable($m[1].'T'.$m[2], new \DateTimeZone('UTC')))->setTimezone($tz);
        }
        if (preg_match('/^\d{8}T\d{6}$/', $value)) {
            return \DateTimeImmutable::createFromFormat('Ymd\THis', $value, $tz) ?: null;
        }
        if (preg_match('/^\d{8}$/', $value)) {
            return (\DateTimeImmutable::createFromFormat('Ymd', $value, $tz) ?: null)?->setTime(0, 0);
        }

        return null;
    }

    /**
     * Rough category mapping for the allowed slug set. The VHS catalogue is
     * overwhelmingly adult education, so we default to "bildung" and only
     * override on clear keyword signals.
     */
    private function mapCategory(string $title, ?string $url, ?string $fallback): ?string
    {
        $haystack = mb_strtolower($title.' '.($url ?? ''));

        $rules = [
            'musik' => ['gitarre', 'klavier', 'gesang', 'chor', 'musik', 'konzert', 'band', 'ukulele'],
            'kunst' => ['malen', 'malerei', 'zeichnen', 'fotografie', 'foto', 'kunst', 'keramik', 'töpfer', 'aquarell'],
            'buehne' => ['theater', 'schauspiel', 'tanz', 'tango', 'salsa', 'ballett'],
            'sport' => ['yoga', 'pilates', 'fitness', 'rücken', 'wandern', 'schwimm', 'gymnastik', 'qigong', 'tai chi', 'wirbelsäule', 'lauf'],
            'genuss' => ['kochen', 'koch', 'backen', 'wein', 'küche', 'buffet', 'grill', 'menü'],
            'familie' => ['kinder', 'eltern', 'baby', 'familie', 'jugend', 'stillcaf'],
        ];

        foreach ($rules as $slug => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $slug;
                }
            }
        }

        return $fallback;
    }

    private function fetch(string $url): string
    {
        return $this->http->request('GET', $url, [
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml,text/calendar,*/*',
                'Accept-Language' => 'de-DE,de;q=0.9',
            ],
            'timeout' => 30,
            'max_redirects' => 5,
        ])->getContent();
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

    private function cleanKnr(string $raw): ?string
    {
        if (preg_match('/[A-Za-z]?\d[A-Za-z0-9]*/', $raw, $m)) {
            return $m[0];
        }

        return null;
    }

    private function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
