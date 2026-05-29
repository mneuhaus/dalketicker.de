<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke importer for the "Kulturort Harsewinkel" / Wilhalm site
 * (https://www.wilhalm.de/). The public site is a single-page app that fetches
 * its data from a custom JSON feed at /api/events.php; that endpoint is fetched
 * directly here (no browser/JS needed, /api/ is not robots-disallowed).
 *
 * The feed is NOT schema.org JSON-LD but a small custom JSON array, hence this
 * dedicated parser. Each entry carries ISO dates/times, a Quill HTML
 * description, a location and a category name.
 *
 * Optional source config:
 *   - feedUrl:  overrides the default API URL
 *   - city:     default city (defaults to "Harsewinkel")
 *   - siteUrl:  public page used as sourceUrl (no per-event deep links exist)
 */
#[AutoconfigureTag('app.source_importer')]
final class WilhalmImporter implements SourceImporter
{
    private const DEFAULT_FEED = 'https://www.wilhalm.de/api/events.php';
    private const DEFAULT_SITE = 'https://www.wilhalm.de/';
    private const DEFAULT_CITY = 'Harsewinkel';

    /** Map the feed's category slugs/names onto the Dalketicker category set. */
    private const CATEGORY_MAP = [
        'konzert' => 'musik',
        'party' => 'party',
        'comedy' => 'buehne',
        'kunst' => 'kunst',
        'kids' => 'familie',
        'workshop' => 'bildung',
        'caf' => 'genuss',
        'cafe' => 'genuss',
        'café' => 'genuss',
    ];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'wilhalm';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $feedUrl = $config['feedUrl'] ?? $source->getUrl() ?: self::DEFAULT_FEED;
        $siteUrl = $config['siteUrl'] ?? self::DEFAULT_SITE;
        $city = $config['city'] ?? self::DEFAULT_CITY;

        $body = $this->http->request('GET', $feedUrl, [
            'headers' => ['User-Agent' => 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)'],
            'timeout' => 30,
        ])->getContent();

        $data = json_decode($body, true);
        if (!\is_array($data)) {
            throw new \RuntimeException('Wilhalm feed did not return a JSON array.');
        }

        foreach ($data as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $event = $this->mapEvent($row, $siteUrl, $city);
            if ($event !== null) {
                yield $event;
            }
        }
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row, string $siteUrl, string $city): ?ImportedEvent
    {
        if (($row['published'] ?? null) !== '1') {
            return null;
        }

        $title = trim((string) ($row['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $start = $this->parseDateTime($row['event_date'] ?? null, $row['event_time'] ?? null);
        if ($start === null) {
            return null;
        }

        // Multi-day events list an end_date; same-day events only an end_time.
        $end = $this->parseDateTime(
            ($row['end_date'] ?? null) ?: ($row['event_date'] ?? null),
            $row['end_time'] ?? null,
        );
        if ($end !== null && $end <= $start) {
            $end = null;
        }

        // All-day when neither a start time nor an end time is given.
        $allDay = $this->blank($row['event_time'] ?? null) && $this->blank($row['end_time'] ?? null);

        $location = trim((string) ($row['location'] ?? ''));

        $externalId = $this->blank($row['id'] ?? null) ? null : 'wilhalm-'.$row['id'];

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: $end,
            allDay: $allDay,
            description: $this->cleanDescription((string) ($row['description'] ?? '')),
            venueName: $location !== '' ? $location : null,
            city: $city,
            locationText: $location !== '' ? $location : null,
            categorySlug: $this->mapCategory($row),
            sourceUrl: $siteUrl,
            imageUrl: null, // no foreign images, per policy
            organizer: $this->blank($row['contact_person'] ?? null) ? null : trim((string) $row['contact_person']),
            externalId: $externalId,
            raw: [
                'id' => $row['id'] ?? null,
                'event_date' => $row['event_date'] ?? null,
                'event_time' => $row['event_time'] ?? null,
                'end_date' => $row['end_date'] ?? null,
                'end_time' => $row['end_time'] ?? null,
                'category_name' => $row['category_name'] ?? null,
                'tickets_url' => $row['tickets_url'] ?? null,
            ],
        );
    }

    /** @param array<string, mixed> $row */
    private function mapCategory(array $row): ?string
    {
        foreach ([$row['category_slug'] ?? null, $row['category_name'] ?? null] as $candidate) {
            if ($this->blank($candidate)) {
                continue;
            }
            $key = mb_strtolower(trim((string) $candidate));
            if (isset(self::CATEGORY_MAP[$key])) {
                return self::CATEGORY_MAP[$key];
            }
        }

        return null;
    }

    private function parseDateTime(mixed $date, mixed $time): ?\DateTimeImmutable
    {
        $date = trim((string) ($date ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        $tz = new \DateTimeZone('Europe/Berlin');
        $timeStr = $this->blank($time) ? '00:00:00' : substr((string) $time, 0, 8);

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date.' '.$timeStr, $tz);

        return $dt ?: null;
    }

    /** Strip Quill/HTML markup down to a plain, trimmed snippet. */
    private function cleanDescription(string $html): ?string
    {
        if (trim($html) === '') {
            return null;
        }

        // Turn block-level tags into newlines so paragraphs stay readable.
        $text = preg_replace('#</(p|div|h[1-6]|li|br)\s*>#i', "\n", $html) ?? $html;
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = trim($text);

        return $text !== '' ? $text : null;
    }

    private function blank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }
}
