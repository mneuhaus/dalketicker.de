<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Scrapes the season table on anno-events.de (Datum | Titel | Ort). ANNO runs
 * medieval markets all over Germany, so we keep only the ones in the Kreis
 * Gütersloh (Isselhorst / Rittergut Kruse) — e.g. the Rittermarkt and the
 * Wyhnacht market. Dates are real ranges from the table, not RSS post dates.
 */
#[AutoconfigureTag('app.source_importer')]
final class AnnoEventsImporter implements SourceImporter
{
    private const MONTHS = [
        'januar' => 1, 'februar' => 2, 'märz' => 3, 'maerz' => 3, 'april' => 4, 'mai' => 5, 'juni' => 6,
        'juli' => 7, 'august' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'dezember' => 12,
    ];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'anno_events';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl() ?: 'https://anno-events.de/';
        $html = $this->http->request('GET', $url, [
            'headers' => ['User-Agent' => 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)'],
            'timeout' => 30,
        ])->getContent();

        if (!preg_match('/<table.*?<\/table>/si', $html, $m)) {
            return;
        }

        $tz = new \DateTimeZone('Europe/Berlin');
        $today = new \DateTimeImmutable('today', $tz);
        preg_match_all('/<tr.*?<\/tr>/si', $m[0], $rows);

        foreach ($rows[0] as $row) {
            preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/si', $row, $cells);
            $cols = array_map(fn ($c) => $this->clean($c), $cells[1]);
            if (count($cols) < 3) {
                continue;
            }
            [$dateRaw, $title, $location] = [$cols[0], $cols[1], $cols[2]];
            if ($title === '' || $dateRaw === '') {
                continue;
            }
            // Kreis Gütersloh only.
            if (!preg_match('/gütersloh|guetersloh|isselhorst/iu', $location)) {
                continue;
            }

            [$start, $end] = $this->parseRange($dateRaw, $today, $tz);
            if ($start === null) {
                continue;
            }

            $venueName = $this->venueFrom($location);

            yield new ImportedEvent(
                title: 'Mittelaltermarkt: '.$title,
                startsAt: $start,
                endsAt: $end,
                allDay: true,
                description: trim($title.' – historischer Markt von ANNO-EVENTS in '.$location.'.'),
                venueName: $venueName,
                city: 'Gütersloh',
                locationText: $location,
                categorySlug: 'markt',
                sourceUrl: $url,
                organizer: 'ANNO-EVENTS',
                externalId: 'anno-'.$start->format('Y-m-d').'-'.substr(md5($title), 0, 8),
                raw: ['date' => $dateRaw, 'title' => $title, 'location' => $location],
            );
        }
    }

    /**
     * Parse a same-month German range like "04. – 07. Juni" into [start, end].
     * The year is inferred (next year if the start would be well in the past).
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function parseRange(string $raw, \DateTimeImmutable $today, \DateTimeZone $tz): array
    {
        $raw = str_replace("\u{00a0}", ' ', $raw);
        if (!preg_match('/(\d{1,2})\.\s*[–\-]\s*(\d{1,2})\.\s*([A-Za-zäöüÄÖÜ]+)/u', $raw, $mm)) {
            return [null, null];
        }
        $month = self::MONTHS[mb_strtolower($mm[3])] ?? null;
        if ($month === null) {
            return [null, null];
        }
        $d1 = (int) $mm[1];
        $d2 = (int) $mm[2];
        $year = (int) $today->format('Y');

        $start = $this->makeDate($year, $month, $d1, $tz);
        if ($start === null) {
            return [null, null];
        }
        // Roll over to next year only around the year boundary: the season
        // table keeps past rows online for months, and an unconditional bump
        // would fabricate next-year dates the organizer never announced. A
        // rolled date more than six months ahead is such a phantom — keep the
        // past date instead (past events are simply not displayed).
        if ($start < $today->modify('-40 days')) {
            $rolled = $this->makeDate($year + 1, $month, $d1, $tz);
            if ($rolled !== null && $rolled <= $today->modify('+6 months')) {
                ++$year;
                $start = $rolled;
            }
        }
        $end = $this->makeDate($year, $month, max($d1, $d2), $tz)?->setTime(23, 59);

        return [$start->setTime(0, 0), $end];
    }

    private function makeDate(int $year, int $month, int $day, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if ($day < 1 || $day > 31) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-n-j', sprintf('%d-%d-%d', $year, $month, $day), $tz);

        return $d ?: null;
    }

    private function venueFrom(string $location): ?string
    {
        // "Gütersloh-Isselhorst – Rittergut Kruse" -> "Rittergut Kruse"
        if (preg_match('/[–-]\s*(.+)$/u', $location, $m)) {
            $v = trim($m[1]);
            if ($v !== '') {
                return $v;
            }
        }

        return $location ?: null;
    }

    private function clean(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00a0}", ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
