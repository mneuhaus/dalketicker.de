<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke importer for the events of the Stadtbibliothek Gütersloh
 * (children's library, readings, workshops, chess club, conversation circles,
 * ...) hosted in the easy2book.de booking system at
 * https://stadtbibliothek-guetersloh.easy2book.de/veranstaltungen/.
 *
 * The /veranstaltungen/ landing page is a fully JS-rendered FullCalendar widget
 * ("bitte warten ...") with no server-side dates, no ICS/RSS and no
 * schema.org JSON-LD. The calendar feeds itself via the same backend AJAX
 * endpoint we hit here directly:
 *
 *   GET/POST /index.php?option=com_samedi&task=eventcalendar.events&start=&end=
 *
 * which returns clean JSON: {"success":true,"data":[{id,title,descr,link,
 * location,start,end,worker,...}, ...]}. Dates are ISO-8601 with offset, the
 * "worker" field is the event series (Kinderbibliothek, Vortrag/Lesung, ...)
 * which we map onto our category slugs. The "link" is the public detail page
 * used as sourceUrl. No images are imported.
 *
 * Optional source config:
 *   - city:        defaults to "Gütersloh"
 *   - venue:       default venue when an event carries none
 *   - monthsAhead: how far into the future to query (default 13)
 *   - monthsBack:  how far into the past to include (default 1, for running events)
 */
#[AutoconfigureTag('app.source_importer')]
final class StadtbibGtImporter implements SourceImporter
{
    private const DEFAULT_BASE = 'https://stadtbibliothek-guetersloh.easy2book.de/';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)';

    /** Maps the easy2book "worker" / event-series labels onto our category slugs. */
    private const CATEGORY_MAP = [
        'veranstaltungen der kinderbibliothek' => 'familie',
        'kinderbibliothek' => 'familie',
        'vortrag/lesung' => 'bildung',
        'vortrag' => 'bildung',
        'lesung' => 'bildung',
        'workshop' => 'bildung',
        'gesprächskreis' => 'bildung',
        'sprechstunde' => 'sonstiges',
        'sonderveranstaltung' => 'sonstiges',
    ];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'stadtbib_gt';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $city = $config['city'] ?? 'Gütersloh';
        $venue = $config['venue'] ?? 'Stadtbibliothek Gütersloh';
        $monthsAhead = max(1, (int) ($config['monthsAhead'] ?? 13));
        $monthsBack = max(0, (int) ($config['monthsBack'] ?? 1));

        $base = rtrim($source->getUrl() ?: self::DEFAULT_BASE, '/').'/';
        // The landing page may be configured as the source URL; reduce to host root.
        $base = preg_replace('#/veranstaltungen/?$#', '/', $base) ?? $base;

        $tz = new \DateTimeZone('Europe/Berlin');
        $now = new \DateTimeImmutable('now', $tz);
        $start = $now->modify(sprintf('-%d months', $monthsBack))->format('Y-m-d');
        $end = $now->modify(sprintf('+%d months', $monthsAhead))->format('Y-m-d');

        $endpoint = $base.'index.php';

        try {
            $response = $this->http->request('POST', $endpoint, [
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Accept' => 'application/json, text/javascript, */*; q=0.01',
                ],
                'query' => ['option' => 'com_samedi'],
                'body' => [
                    'task' => 'eventcalendar.events',
                    'start' => $start,
                    'end' => $end,
                ],
                'timeout' => 30,
            ]);
            $payload = $response->getContent();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to fetch easy2book events: '.$e->getMessage(), 0, $e);
        }

        $data = json_decode($payload, true);
        if (!is_array($data) || empty($data['success']) || !isset($data['data']) || !is_array($data['data'])) {
            throw new \RuntimeException('Unexpected easy2book response (no event data).');
        }

        foreach ($data['data'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $event = $this->mapRow($row, $tz, $city, $venue);
            if ($event !== null) {
                yield $event;
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row, \DateTimeZone $tz, string $city, string $venue): ?ImportedEvent
    {
        $title = trim((string) ($row['title'] ?? ''));
        $startRaw = trim((string) ($row['start'] ?? ''));
        if ($title === '' || $startRaw === '') {
            return null;
        }

        $start = $this->parseDate($startRaw, $tz);
        if ($start === null) {
            return null;
        }
        $end = isset($row['end']) ? $this->parseDate(trim((string) $row['end']), $tz) : null;

        $allDay = !empty($row['allDay']);

        $location = trim((string) ($row['location'] ?? ''));
        $venueName = $location !== '' ? $location : $venue;

        $detailUrl = trim((string) ($row['link'] ?? '')) ?: null;
        $worker = trim((string) ($row['worker'] ?? ''));

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: ($end !== null && $end > $start) ? $end : null,
            allDay: $allDay,
            description: $this->cleanDescription((string) ($row['descr'] ?? '')),
            venueName: $venueName,
            city: $city,
            locationText: trim($venueName.', '.$city, ', '),
            categorySlug: $this->mapCategory($worker),
            sourceUrl: $detailUrl,
            imageUrl: null,
            organizer: 'Stadtbibliothek Gütersloh',
            externalId: $this->externalId($row, $detailUrl),
            raw: [
                'id' => $row['id'] ?? null,
                'worker' => $worker !== '' ? $worker : null,
                'location' => $location !== '' ? $location : null,
                'start' => $startRaw,
            ],
        );
    }

    /** Parse ISO-8601 with offset (e.g. "2026-06-12T15:30:00+02:00"), tolerant. */
    private function parseDate(string $value, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone($tz);
        } catch (\Exception) {
            return null;
        }
    }

    private function mapCategory(string $worker): ?string
    {
        $key = mb_strtolower(trim($worker));
        if ($key === '') {
            return null;
        }

        return self::CATEGORY_MAP[$key] ?? null;
    }

    /** Strip HTML and collapse whitespace; null when empty. */
    private function cleanDescription(string $html): ?string
    {
        if (trim($html) === '') {
            return null;
        }
        $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        // Remove zero-width and other invisible characters left by the CMS editor.
        $text = preg_replace('/[\x{200B}\x{FEFF}]/u', '', $text) ?? $text;
        $text = trim(preg_replace('/[ \t]+/', ' ', preg_replace('/\n{2,}/', "\n", trim($text))) ?? $text);

        return $text !== '' ? mb_substr($text, 0, 500) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function externalId(array $row, ?string $detailUrl): ?string
    {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id !== '') {
            return 'stadtbib_gt-'.$id;
        }
        if ($detailUrl !== null) {
            $path = trim((string) (parse_url($detailUrl, \PHP_URL_PATH) ?? ''), '/');
            if ($path !== '') {
                return 'stadtbib_gt-'.$path;
            }
        }

        return null;
    }
}
