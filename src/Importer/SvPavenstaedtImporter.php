<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke scraper for the SV Pavenstädt (a Gütersloh district Schützenverein)
 * "Termine" article. Punycode host xn--sv-pavenstdt-pcb.de = sv-pavenstädt.de.
 *
 * Joomla article (com_content) with no ICS/RSS/JSON-LD feed. The body lists
 * one or more year blocks, each introduced by a heading "Termine JJJJ":
 *   - older years are rendered as an HTML `<table>` (date | title | time | place)
 *   - the current year is rendered as `<p>` paragraphs where the columns are
 *     separated by runs of whitespace: "DD.MM. Titel   HH:MM   Ort".
 *
 * We split the HTML on the year headings, attach that block's year to every
 * row inside it (the rows themselves only carry "DD.MM."), parse both table
 * rows and paragraph rows, and drop anything already in the past. Being
 * year-aware means next season's "Termine JJJJ+1" block is picked up without
 * a code change. Many entries are other clubs' Schützenfeste — kept, since
 * they are regional events; cross-source dedup merges duplicates.
 *
 * Only factual fields exist (no foreign description/image), so this is a plain
 * primary source, not an aggregator.
 *
 * Optional source config:
 *   - city:       default city (defaults to "Gütersloh")
 *   - maxEvents:  cap on number of events (default 200)
 */
#[AutoconfigureTag('app.source_importer')]
final class SvPavenstaedtImporter implements SourceImporter
{
    private const BASE = 'https://www.xn--sv-pavenstdt-pcb.de';
    private const DEFAULT_URL = self::BASE.'/index.php?option=com_content&view=article&id=147&Itemid=118';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.de)';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ClockInterface $clock,
    ) {
    }

    public static function getKey(): string
    {
        return 'sv_pavenstaedt';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $tz = new \DateTimeZone('Europe/Berlin');
        $city = $config['city'] ?? 'Gütersloh';
        $maxEvents = (int) ($config['maxEvents'] ?? 200);

        $url = $source->getUrl() ?: self::DEFAULT_URL;
        $html = $this->fetch($url);

        // Keep only today and later — the page carries whole past seasons.
        $today = $this->clock->now()->setTimezone($tz)->setTime(0, 0);

        $seen = [];
        $count = 0;
        foreach ($this->yearSegments($html) as [$year, $segment]) {
            foreach ($this->rows($segment) as [$day, $month, $title, $time, $location]) {
                if ($count >= $maxEvents) {
                    return;
                }

                $allDay = $time === null;
                $start = SafeDate::create($year, $month, $day, $time[0] ?? 0, $time[1] ?? 0, $tz);
                if ($start === null || $start < $today) {
                    continue;
                }

                $externalId = sprintf('sv_pavenstaedt:%04d-%02d-%02d-%s', $year, $month, $day, $this->slug($title));
                if (isset($seen[$externalId])) {
                    continue;
                }
                $seen[$externalId] = true;
                ++$count;

                yield new ImportedEvent(
                    title: $title,
                    startsAt: $start,
                    allDay: $allDay,
                    venueName: $location !== '' ? $location : null,
                    city: $city,
                    locationText: $location !== '' ? $location : 'Pavenstädt',
                    categorySlug: $this->mapCategory($title),
                    sourceUrl: $url,
                    externalId: $externalId,
                    raw: ['year' => $year],
                );
            }
        }
    }

    /**
     * Split the HTML into [year, segmentHtml] blocks at each "Termine JJJJ"
     * heading. A segment runs from its heading to the next heading (or EOF).
     *
     * @return list<array{0:int, 1:string}>
     */
    private function yearSegments(string $html): array
    {
        if (!preg_match_all('/Termine\s*(20\d{2})/u', $html, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $segments = [];
        $count = \count($m[0]);
        for ($i = 0; $i < $count; ++$i) {
            $year = (int) $m[1][$i][0];
            $start = (int) $m[0][$i][1];
            $end = $i + 1 < $count ? (int) $m[0][$i + 1][1] : \strlen($html);
            $segments[] = [$year, substr($html, $start, $end - $start)];
        }

        return $segments;
    }

    /**
     * Extract event rows from one year segment, from both `<p>` paragraphs and
     * `<table>` rows. Each yielded row is [day, month, title, time|null, place].
     *
     * @return list<array{0:int, 1:int, 2:string, 3:?array{0:int,1:int}, 4:string}>
     */
    private function rows(string $segment): array
    {
        $rows = [];

        // Paragraph layout: whitespace-separated columns within one <p>.
        if (preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $segment, $ps)) {
            foreach ($ps[1] as $inner) {
                $row = $this->parseTextRow($this->plain($inner));
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        // Table layout: each <tr> has separate <td> cells.
        if (preg_match_all('#<tr\b[^>]*>(.*?)</tr>#is', $segment, $trs)) {
            foreach ($trs[1] as $tr) {
                if (!preg_match_all('#<t[dh]\b[^>]*>(.*?)</t[dh]>#is', $tr, $cells)) {
                    continue;
                }
                $vals = array_map(fn (string $c) => $this->plain($c), $cells[1]);
                $row = $this->parseCells($vals);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Parse a paragraph row "DD.MM. Titel   HH:MM   Ort". Columns are runs of
     * 2+ spaces; the title may itself contain single spaces.
     *
     * @return array{0:int, 1:int, 2:string, 3:?array{0:int,1:int}, 4:string}|null
     */
    private function parseTextRow(string $text): ?array
    {
        if (!preg_match('/^\s*(\d{1,2})\.\s*(\d{1,2})\.?\s+(.+)$/u', $text, $m)) {
            return null;
        }
        $cols = preg_split('/\s{2,}/u', trim($m[3])) ?: [];
        $cols = array_values(array_filter(array_map('trim', $cols), static fn (string $c) => $c !== ''));

        return $this->assemble((int) $m[1], (int) $m[2], $cols);
    }

    /**
     * Parse a table row whose cells are [date, title, time, place].
     *
     * @param list<string> $cells
     *
     * @return array{0:int, 1:int, 2:string, 3:?array{0:int,1:int}, 4:string}|null
     */
    private function parseCells(array $cells): ?array
    {
        if ($cells === [] || !preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.?$/', trim($cells[0]), $m)) {
            return null;
        }

        return $this->assemble((int) $m[1], (int) $m[2], \array_slice($cells, 1));
    }

    /**
     * Turn the non-date columns into [day, month, title, time, place]. The time
     * column is whichever column reads as HH:MM once its spaces are stripped
     * ("1 9:30" → 19:30); everything before it is the title, everything after
     * is the place.
     *
     * @param list<string> $cols
     *
     * @return array{0:int, 1:int, 2:string, 3:?array{0:int,1:int}, 4:string}|null
     */
    private function assemble(int $day, int $month, array $cols): ?array
    {
        if ($cols === [] || $day < 1 || $day > 31 || $month < 1 || $month > 12) {
            return null;
        }

        $time = null;
        $timeIdx = null;
        foreach ($cols as $i => $col) {
            $compact = preg_replace('/\s+/', '', $col) ?? $col;
            if (preg_match('/^(\d{1,2})[:.](\d{2})$/', $compact, $tm)) {
                $h = (int) $tm[1];
                $min = (int) $tm[2];
                if ($h < 24 && $min < 60) {
                    $time = [$h, $min];
                    $timeIdx = $i;
                    break;
                }
            }
        }

        if ($timeIdx === null) {
            $title = trim($cols[0]);
            $place = trim(implode(' ', \array_slice($cols, 1)));
        } else {
            $title = trim(implode(' ', \array_slice($cols, 0, $timeIdx)));
            $place = trim(implode(' ', \array_slice($cols, $timeIdx + 1)));
        }

        if ($title === '') {
            return null;
        }

        return [$day, $month, $title, $time, $place];
    }

    private function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = str_replace("\xc2\xa0", ' ', $text); // non-breaking space → space

        return trim($text);
    }

    private function slug(string $title): string
    {
        $s = preg_replace('/[^a-z0-9]+/', '', mb_strtolower($title)) ?? '';

        return substr($s, 0, 40);
    }

    /** Fetch the calendar page or throw, so a dead source surfaces as a failed run. */
    private function fetch(string $url): string
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => 20,
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

    private function mapCategory(string $title): ?string
    {
        $t = mb_strtolower($title);

        $map = [
            'sport' => ['schießen', 'meisterschaft', 'bogen', 'sportversammlung', 'triathlon'],
            'familie' => ['kinderfest', 'kinder', 'jugend', 'osterfeuer'],
            'party' => ['ball', 'winterfest', 'fotofrühschoppen', 'frühschoppen'],
            'markt' => ['schützenfest', 'schützentag', 'kirmes', 'weihnachtsmarkt', 'markt', 'kuhfladen', 'fest', 'schützen'],
            'genuss' => ['grünkohl'],
        ];

        foreach ($map as $slug => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($t, $keyword)) {
                    return $slug;
                }
            }
        }

        // Versammlungen, Putz-Aktionen etc. — let the AI pass decide.
        return null;
    }
}
