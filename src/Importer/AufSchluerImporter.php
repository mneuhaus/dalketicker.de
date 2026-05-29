<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke HTML importer for "Auf Schlür", the central Gütersloh event calendar
 * operated by Gütersloh Marketing GmbH. The events live on
 * https://veranstaltungen-gt.de/ (not auf-schluer.de).
 *
 * The list page /times?type=what is fully server-side rendered (custom CMS,
 * CakePHP-style URLs), no JS required. Each event is a `.media` block carrying
 * machine-readable `<time datetime="Y-m-d H:i:s">` start/end stamps, a detail
 * link `/events/view/{ID}`, the title, optional subtitle, location and category
 * tags. We parse those directly, no per-event ICS fetch needed.
 *
 * Per the project rules we never copy foreign images (imageUrl stays null) and
 * always link back to the original detail page via sourceUrl.
 */
#[AutoconfigureTag('app.source_importer')]
final class AufSchluerImporter implements SourceImporter
{
    private const BASE_URL = 'https://veranstaltungen-gt.de';
    private const LIST_URL = self::BASE_URL.'/times?type=what';

    /** Maps the site's category tag labels onto our allowed category slugs. */
    private const CATEGORY_MAP = [
        'kunst und kultur' => 'kunst',
        'konzerte und parties' => 'musik',
        'kinder und familie' => 'familie',
        'freizeit' => 'sonstiges',
        '(stadt)feste' => 'markt',
        'stadtfeste' => 'markt',
        'feste' => 'markt',
        'sonstiges' => 'sonstiges',
        'sport' => 'sport',
    ];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'auf_schluer';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl() ?: self::LIST_URL;
        $config = $source->getConfig();
        $city = $config['city'] ?? 'Gütersloh';

        $html = $this->http->request('GET', $url, [
            'headers' => ['User-Agent' => 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)'],
            'timeout' => 30,
        ])->getContent();

        $crawler = new Crawler($html, $url);

        foreach ($crawler->filter('div.media div.media-row') as $node) {
            $row = new Crawler($node);
            $event = $this->mapRow($row, $city);
            if ($event !== null) {
                yield $event;
            }
        }
    }

    private function mapRow(Crawler $row, string $city): ?ImportedEvent
    {
        $titleLink = $row->filter('h2.media-heading a');
        if ($titleLink->count() === 0) {
            return null;
        }

        $title = $this->text($titleLink->text(''));
        if ($title === '') {
            return null;
        }

        $href = $titleLink->attr('href') ?? '';
        $sourceUrl = $this->absoluteUrl($href);
        $externalId = $this->extractEventId($href);

        [$start, $end] = $this->parseTimes($row);
        if ($start === null) {
            return null;
        }

        // 08:00 with both date stamps spanning months is the site's marker for
        // multi-day / open-ended runs; treat midnight-aligned ones as all-day.
        $allDay = $start->format('H:i') === '00:00' && ($end === null || $end->format('H:i') === '00:00');

        $subtitle = $this->firstText($row, 'h3');

        $venueName = null;
        $locationParts = [];
        $locationLink = $row->filter('p.p-location a');
        if ($locationLink->count() > 0) {
            $venueName = $this->text($locationLink->first()->text(''));
            if ($venueName !== '') {
                $locationParts[] = $venueName;
            }
        }
        $sublocation = $this->firstText($row, 'p.p-location span.sublocation');
        if ($sublocation !== '') {
            $locationParts[] = $sublocation;
        }
        $locationText = $locationParts !== [] ? implode(', ', $locationParts) : null;

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: $end,
            allDay: $allDay,
            description: $subtitle !== '' ? $subtitle : null,
            venueName: $venueName ?: null,
            city: $city,
            locationText: $locationText,
            categorySlug: $this->mapCategory($row),
            sourceUrl: $sourceUrl,
            imageUrl: null,
            externalId: $externalId,
            raw: [
                'href' => $href,
                'title' => $title,
                'subtitle' => $subtitle,
                'location' => $locationText,
            ],
        );
    }

    /**
     * Parses the `.g-date` block. Start always carries a full datetime stamp;
     * the optional end may carry only a time (same calendar day) in which case
     * we inherit the start date.
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function parseTimes(Crawler $row): array
    {
        $times = $row->filter('div.g-date time');
        if ($times->count() === 0) {
            return [null, null];
        }

        $tz = new \DateTimeZone('Europe/Berlin');
        $start = $this->parseTimeNode($times->first(), null, $tz);
        if ($start === null) {
            return [null, null];
        }

        $end = null;
        if ($times->count() > 1) {
            $end = $this->parseTimeNode($times->eq(1), $start, $tz);
        }

        return [$start, $end];
    }

    private function parseTimeNode(Crawler $node, ?\DateTimeImmutable $fallbackDate, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $datetime = trim((string) $node->attr('datetime'));

        // Full stamp: "2026-05-29 14:30:00".
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/', $datetime, $m)) {
            return \DateTimeImmutable::createFromFormat('Y-m-d H:i', $m[1].' '.$m[2], $tz) ?: null;
        }

        // Time-only end on the same day (the datetime attr may be absent there);
        // fall back to the visible "HH:MM" combined with the start date.
        if ($fallbackDate !== null) {
            $timeText = $this->firstText($node, 'span.time');
            if ($timeText === '') {
                $timeText = $this->text($node->text(''));
            }
            if (preg_match('/(\d{1,2}):(\d{2})/', $timeText, $m)) {
                return $fallbackDate->setTime((int) $m[1], (int) $m[2]);
            }
        }

        return null;
    }

    private function mapCategory(Crawler $row): ?string
    {
        foreach ($row->filter('div.event-tags a span') as $span) {
            $label = mb_strtolower(trim((new Crawler($span))->text('')));
            if (isset(self::CATEGORY_MAP[$label])) {
                return self::CATEGORY_MAP[$label];
            }
        }

        return null;
    }

    private function extractEventId(string $href): ?string
    {
        if (preg_match('#/events/view/(\d+)#', $href, $m)) {
            return 'auf_schluer:'.$m[1];
        }

        return null;
    }

    private function absoluteUrl(string $href): ?string
    {
        if ($href === '') {
            return null;
        }
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        return self::BASE_URL.'/'.ltrim($href, '/');
    }

    /** Safe text of the first match for a selector, or '' if absent. */
    private function firstText(Crawler $crawler, string $selector): string
    {
        $node = $crawler->filter($selector);

        return $node->count() > 0 ? $this->text($node->first()->text('')) : '';
    }

    private function text(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
