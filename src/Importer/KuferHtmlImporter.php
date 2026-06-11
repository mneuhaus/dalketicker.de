<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use App\Enum\BookingStatus;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTML importer for VHS course catalogues running on the Kufer Software GmbH
 * "KuferWEB" platform (TYPO3 + kuferweb extension). Several adult-education
 * centres in the Kreis Gütersloh share this CMS, so this importer is written
 * generically and reused across them.
 *
 * The course list at the source URL (e.g. https://www.vhs-re.de/programm) is
 * rendered server-side: each course is a `div.row.kw-table-row` whose
 * `a.kw-kurstitel` wraps the title, date ("Wann:"), place ("Wo:") and course
 * number ("Nr.:"). Pagination is classic server-side via
 * `?browse=forward&kathaupt=1&knr=<nr>&cHash=<hash>` links in
 * `.kw-paginationleiste`.
 *
 * Optional source config:
 *   - city:        default city (defaults to the source's commune)
 *   - category:    default category slug
 *   - maxPages:    pagination safety cap (default 30)
 */
#[AutoconfigureTag('app.source_importer')]
final class KuferHtmlImporter implements SourceImporter
{
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)';
    private const DEFAULT_MAX_PAGES = 30;

    /** robots.txt requests Crawl-delay 5; be polite between page fetches. */
    private const CRAWL_DELAY_SECONDS = 5;

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'vhs_re';
    }

    public function import(Source $source): iterable
    {
        $startUrl = $source->getUrl();
        if (!$startUrl) {
            throw new \RuntimeException('vhs_re source has no URL.');
        }

        $config = $source->getConfig();
        $base = $this->baseUrl($startUrl);
        $maxPages = (int) ($config['maxPages'] ?? self::DEFAULT_MAX_PAGES);

        $seenPageUrls = [];
        $seenExternalIds = [];
        $url = $startUrl;
        $pages = 0;

        while ($url !== null && $pages < $maxPages) {
            if (isset($seenPageUrls[$url])) {
                break;
            }
            $seenPageUrls[$url] = true;
            ++$pages;

            // The first page failing means the source is down → fail the run;
            // a broken follow-up page only ends pagination early.
            $html = $pages === 1 ? $this->fetch($url) : $this->tryFetch($url);
            if ($html === null) {
                break;
            }

            $crawler = new Crawler($html, $url);

            foreach ($crawler->filter('div.kw-table-row, tr.kw-table-row, div.h-box')->each(fn (Crawler $node) => $node) as $row) {
                $event = $this->mapRow($row, $base, $config);
                if ($event === null) {
                    continue;
                }
                // Same course number can repeat across pages; skip duplicates.
                if ($event->externalId !== null) {
                    if (isset($seenExternalIds[$event->externalId])) {
                        continue;
                    }
                    $seenExternalIds[$event->externalId] = true;
                }
                yield $event;
            }

            $next = $this->nextPageUrl($crawler, $base);
            $url = ($next !== null && !isset($seenPageUrls[$next])) ? $next : null;

            if ($url !== null) {
                sleep(self::CRAWL_DELAY_SECONDS);
            }
        }
    }

    /** Fetch a URL or throw, so a dead source surfaces as a failed run. */
    private function fetch(string $url): string
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => 30,
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

    /** Tolerant variant for follow-up pages: one broken page must not kill the run. */
    private function tryFetch(string $url): ?string
    {
        try {
            return $this->fetch($url);
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function mapRow(Crawler $row, string $base, array $config): ?ImportedEvent
    {
        if ($row->matches('tr.kw-table-row')) {
            return $this->mapTableRow($row, $base, $config);
        }

        $link = $row->filter('a.kw-kurstitel');
        if ($link->count() === 0) {
            return null;
        }

        $href = $link->attr('href');
        $sourceUrl = $this->absoluteUrl($href, $base);

        // The title cell is the first column div inside the title anchor.
        $title = '';
        $titleCol = $link->filter('div')->first();
        if ($titleCol->count() > 0) {
            $title = $this->clean($titleCol->text(''));
        }
        if ($title === '') {
            $title = $this->clean($link->text(''));
        }
        if ($title === '') {
            return null;
        }

        // Labelled cells: "Wann:" (date), "Wo:" (place), "Nr.:" (course no.).
        $whenText = $this->valueAfterLabel($link, 'Wann');
        $whereText = $this->valueAfterLabel($link, 'Wo');
        $courseNo = $this->valueAfterLabel($link, 'Nr');
        $price = null;

        if ($whenText === null && $row->matches('div.h-box')) {
            $properties = $this->propertyItems($row);
            foreach ($properties as $property) {
                if ($whenText === null && preg_match('/\d{1,2}\.\d{1,2}\.\d{4}/', $property)) {
                    $whenText = $property;
                    continue;
                }
                if ($price === null && str_contains($property, '€')) {
                    $price = $property;
                }
            }

            $first = $properties[0] ?? '';
            if ($courseNo === null && preg_match('/^([^|]+)(?:\||$)/', $first, $m)) {
                $courseNo = trim($m[1]);
            }
            if ($whereText === null && str_contains($first, '|')) {
                $candidate = trim(substr($first, (int) strpos($first, '|') + 1));
                if ($candidate !== '' && mb_strtolower($candidate) !== 'überregional') {
                    $whereText = $candidate;
                }
            }
        }
        if ($whenText === null && $row->matches('div.kw-table-row')) {
            $meta = $this->clean($row->filter('.text-right-md')->first()->text(''));
            if (preg_match('/\d{1,2}\.\d{1,2}\.\d{2,4}/', $meta)) {
                $whenText = $meta;
            }
            if ($whereText === null && preg_match('/Kursort:\s*([^.,]+(?:,\s*[^.,]+)?)/u', $meta, $m)) {
                $whereText = trim($m[1]);
            }
            if ($courseNo === null && \is_string($href) && preg_match('/[?&]knr=([^&#]+)/', $href, $m)) {
                $courseNo = urldecode($m[1]);
            }
        }

        $start = $whenText !== null ? $this->parseGermanDate($whenText) : null;
        if ($start === null) {
            return null;
        }

        $externalId = $courseNo !== null && $courseNo !== ''
            ? $courseNo
            : $this->idFromUrl($sourceUrl ?? $href);

        $city = $config['city'] ?? null;
        $venueName = ($whereText !== null && $whereText !== '') ? $whereText : null;

        // Booking status: KuferWEB usually renders a ".kw_ampel" span; some
        // installs only label it "Status:". Best-effort, null when absent.
        $statusText = null;
        $ampel = $row->filter('.kw_ampel');
        if ($ampel->count() > 0) {
            $statusText = $this->clean($ampel->first()->text(''));
        }
        $statusText ??= $this->valueAfterLabel($link, 'Status');

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: null,
            allDay: false,
            description: null,
            venueName: $venueName,
            city: $city,
            locationText: $venueName,
            categorySlug: $this->mapCategory($title, $config),
            sourceUrl: $sourceUrl,
            imageUrl: null,
            price: $price,
            organizer: null,
            externalId: $externalId,
            raw: [
                'when' => $whenText,
                'where' => $whereText ?? '',
                'nr' => $courseNo ?? '',
            ],
            isCourse: true,
            bookingStatus: BookingStatus::fromText($statusText),
        );
    }

    /** @param array<string, mixed> $config */
    private function mapTableRow(Crawler $row, string $base, array $config): ?ImportedEvent
    {
        $link = $row->filter('td[headers="kue-columnheader2"] a[href], a[href]')->first();
        if ($link->count() === 0) {
            return null;
        }

        $href = $link->attr('href') ?: $row->attr('data-href');
        $sourceUrl = $this->absoluteUrl($href, $base);
        $title = $this->clean($link->text(''));
        if ($title === '') {
            return null;
        }

        $whenText = $this->cellText($row, 'kue-columnheader3');
        $whereText = $this->cellText($row, 'kue-columnheader4');
        $courseNo = $this->cellText($row, 'kue-columnheader5');
        $start = $whenText !== '' ? $this->parseGermanDate($whenText) : null;
        if ($start === null) {
            return null;
        }

        $statusText = null;
        $ampel = $row->filter('.ampelicon');
        if ($ampel->count() > 0) {
            $statusText = $this->clean($ampel->first()->attr('title') ?? $ampel->first()->text(''));
        }

        $venueName = $whereText !== '' ? $whereText : null;

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: null,
            allDay: false,
            description: null,
            venueName: $venueName,
            city: $config['city'] ?? null,
            locationText: $venueName,
            categorySlug: $this->mapCategory($title, $config),
            sourceUrl: $sourceUrl,
            imageUrl: null,
            price: null,
            organizer: null,
            externalId: $courseNo !== '' ? $courseNo : $this->idFromUrl($sourceUrl ?? $href),
            raw: [
                'when' => $whenText,
                'where' => $whereText,
                'nr' => $courseNo,
            ],
            isCourse: true,
            bookingStatus: BookingStatus::fromText($statusText),
        );
    }

    /**
     * KuferWEB renders, per labelled field, two sibling divs: a label div
     * (class `kw-table-label`, e.g. "Wann:") immediately followed by the value
     * div. We locate the label by text and read the next div's content.
     */
    private function valueAfterLabel(Crawler $scope, string $label): ?string
    {
        $needle = mb_strtolower($label);

        foreach ($scope->filter('div.kw-table-label')->each(fn (Crawler $n) => $n) as $labelNode) {
            $text = mb_strtolower($this->clean($labelNode->text('')));
            if (!str_starts_with($text, $needle)) {
                continue;
            }

            $value = $labelNode->nextAll()->first();
            if ($value->count() > 0) {
                return $this->clean($value->text(''));
            }
        }

        return null;
    }

    /** @return list<string> */
    private function propertyItems(Crawler $row): array
    {
        $items = [];
        foreach ($row->filter('ul.kw-kurs-properties > li')->each(fn (Crawler $node) => $node) as $item) {
            $text = $this->clean($item->text(''));
            if ($text !== '') {
                $items[] = $text;
            }
        }

        return $items;
    }

    private function cellText(Crawler $row, string $header): string
    {
        $cell = $row->filter(sprintf('td[headers="%s"]', $header))->first();

        return $cell->count() > 0 ? $this->clean($cell->text('')) : '';
    }

    /**
     * Parse the KuferWEB date format, e.g. "Fr. 29.05.2026, 19.30 Uhr"
     * (abbreviated weekday + DD.MM.YYYY + HH.MM with a dot separator). The
     * weekday prefix and time are optional. Some installations abbreviate the
     * year ("05.03.26").
     */
    private function parseGermanDate(string $text): ?\DateTimeImmutable
    {
        $tz = new \DateTimeZone('Europe/Berlin');

        if (!preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})/', $text, $d)) {
            return null;
        }
        $day = (int) $d[1];
        $month = (int) $d[2];
        $year = (int) $d[3];
        if ($year < 100) {
            $year += 2000;
        }

        $hour = 0;
        $minute = 0;
        if (preg_match('/,\s*(\d{1,2})[.:](\d{2})/u', $text, $t)) {
            $hour = (int) $t[1];
            $minute = (int) $t[2];
        }
        // Time follows the date: "19.30 Uhr" or "19:30 Uhr".
        elseif (preg_match('/(\d{1,2})[.:](\d{2})\s*Uhr/u', $text, $t)) {
            $hour = (int) $t[1];
            $minute = (int) $t[2];
        }

        return SafeDate::create($year, $month, $day, $hour, $minute, $tz);
    }

    /**
     * Best-effort mapping of a course title to one of the allowed category
     * slugs. KuferWEB course nrs/titles do not carry a clean category, so we
     * keyword-match and otherwise fall back to "bildung" (VHS courses) or the
     * configured default.
     */
    private function mapCategory(string $title, array $config): ?string
    {
        $haystack = mb_strtolower($title);

        $rules = [
            'musik' => ['gitarre', 'gesang', 'chor', 'klavier', 'ukulele', 'musik', 'singen'],
            'sport' => ['yoga', 'pilates', 'sup', 'paddl', 'kanu', 'fitness', 'wandern', 'gymnastik', 'rücken', 'lauf', 'schwimm', 'tanz'],
            'kunst' => ['malen', 'zeichn', 'aquarell', 'fotografie', 'töpfer', 'toepfer', 'keramik', 'kreativ', 'nähen', 'naehen'],
            'genuss' => ['kochen', 'kochkurs', 'backen', 'wein', 'kulinar', 'mediterran'],
            'buehne' => ['theater', 'poetry slam', 'lesung', 'kabarett'],
            'familie' => ['kinder', 'eltern', 'familie', 'junge vhs'],
        ];

        foreach ($rules as $slug => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($haystack, $kw)) {
                    return $slug;
                }
            }
        }

        return $config['category'] ?? 'bildung';
    }

    private function nextPageUrl(Crawler $crawler, string $base): ?string
    {
        $forward = $crawler->filter('.kw-paginationleiste .forward a');
        if ($forward->count() === 0) {
            return null;
        }

        $href = $forward->first()->attr('href');
        if ($href === null || $href === '') {
            return null;
        }

        return $this->absoluteUrl($href, $base);
    }

    private function idFromUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        // .../kurs/<slug>/<courseNr>  or .../kurssuche/kurs/<courseNr>
        // ~ delimiter: the character class contains '#', which would end a #-delimited pattern.
        if (preg_match('~/kurs/[^/]+/([^/#?]+)~', $url, $m)) {
            return $m[1];
        }
        if (preg_match('~/kurs/([^/#?]+)~', $url, $m)) {
            return $m[1];
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

    private function absoluteUrl(?string $href, string $base): ?string
    {
        if ($href === null || $href === '') {
            return null;
        }
        $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Strip in-page anchor.
        $href = preg_replace('/#.*$/', '', $href) ?? $href;

        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }
        if (str_starts_with($href, '/')) {
            return $base.$href;
        }

        return $base.'/'.$href;
    }

    private function baseUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return rtrim($url, '/');
        }
        $base = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $base .= ':'.$parts['port'];
        }

        return $base;
    }
}
