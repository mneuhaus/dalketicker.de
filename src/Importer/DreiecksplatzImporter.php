<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Importer for the "Kulturgemeinschaft Dreiecksplatz" Gütersloh
 * (https://www.dreiecksplatz-gt.de/).
 *
 * The site is a Jimdo page. Its top-level "/termine/" overview only carries a
 * tiny, stale summary table with year-less, range-style dates ("19.08.-23.08.")
 * that cannot be turned into concrete calendar events. The real, dated programme
 * lives on the "Freitag 18" season page (/events/freitag-18/<year>/): an open-air
 * concert/Kleinkunst series every Friday from spring to late summer on the
 * Dreiecksplatz.
 *
 * That page renders server-side as a Jimdo "hgrid" matrix: one row per Friday,
 * left column a text module with `<strong>8. Mai</strong>` (+ optional time),
 * right column the act title (bold) plus a description and a poster image. We
 * parse those rows directly. The year comes from the page URL/heading, so each
 * concrete Friday becomes a dated {@see ImportedEvent}.
 *
 * Cancelled acts (marked "*** ABGESAGT ***" in the body) are skipped.
 *
 * Optional source config:
 *   - programUrl: override the Freitag-18 season page (default: current /events/freitag-18/<year>/)
 *   - year:       override the season year (default: derived from the URL or "now")
 *   - city:       default city (default: "Gütersloh")
 *   - venue:      default venue (default: "Dreiecksplatz")
 *   - defaultTime: HH:MM used when a row carries no explicit time (default: "18:00")
 */
#[AutoconfigureTag('app.source_importer')]
final class DreiecksplatzImporter implements SourceImporter
{
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)';
    private const BASE = 'https://www.dreiecksplatz-gt.de';
    private const DEFAULT_VENUE = 'Dreiecksplatz';
    private const DEFAULT_CITY = 'Gütersloh';
    private const DEFAULT_TIME = '18:00';

    private const MONTHS = [
        'januar' => 1, 'februar' => 2, 'märz' => 3, 'maerz' => 3, 'april' => 4,
        'mai' => 5, 'juni' => 6, 'juli' => 7, 'august' => 8, 'september' => 9,
        'oktober' => 10, 'november' => 11, 'dezember' => 12,
    ];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'dreiecksplatz';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $tz = new \DateTimeZone('Europe/Berlin');

        $year = isset($config['year']) ? (int) $config['year'] : 0;
        $programUrl = isset($config['programUrl']) && \is_string($config['programUrl'])
            ? $config['programUrl']
            : self::BASE.'/events/freitag-18/'.($year > 0 ? $year : (int) (new \DateTimeImmutable('now', $tz))->format('Y')).'/';

        // Derive the season year from the URL when not configured explicitly.
        if ($year <= 0 && preg_match('#/freitag-18/(\d{4})/#', $programUrl, $m)) {
            $year = (int) $m[1];
        }

        $venue = (string) ($config['venue'] ?? self::DEFAULT_VENUE);
        $city = (string) ($config['city'] ?? self::DEFAULT_CITY);
        $defaultTime = (string) ($config['defaultTime'] ?? self::DEFAULT_TIME);

        $html = $this->fetch($programUrl);

        // Fall back to the year embedded in the page heading if still unknown.
        if ($year <= 0 && preg_match('#Programm\s+(\d{4})#', strip_tags($html), $m)) {
            $year = (int) $m[1];
        }
        if ($year <= 0) {
            $year = (int) (new \DateTimeImmutable('now', $tz))->format('Y');
        }

        yield from $this->parseProgram($html, $programUrl, $year, $venue, $city, $defaultTime, $tz);
    }

    /**
     * @return iterable<ImportedEvent>
     */
    private function parseProgram(
        string $html,
        string $url,
        int $year,
        string $venue,
        string $city,
        string $defaultTime,
        \DateTimeZone $tz,
    ): iterable {
        $crawler = new Crawler($html, $url);
        $seen = [];

        foreach ($crawler->filter('div.j-hgrid')->each(static fn (Crawler $n): Crawler => $n) as $row) {
            $columns = $row->filter('.cc-m-hgrid-column');
            if ($columns->count() < 2) {
                continue;
            }

            $dateModule = $columns->eq(0);
            [$day, $month] = $this->parseDayMonth($dateModule->text(''));
            if ($day === null || $month === null) {
                continue;
            }

            try {
                $time = $this->parseTime($dateModule->text('')) ?? $defaultTime;
                [$h, $i] = array_map('intval', explode(':', $time));
                $start = (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), $tz))->setTime($h, $i);
            } catch (\Exception) {
                continue;
            }

            $body = $columns->eq(1);
            $title = $this->extractTitle($body);
            if ($title === null) {
                continue;
            }

            $rawText = $this->collapse($body->text(''));
            if (stripos($rawText, 'ABGESAGT') !== false) {
                continue; // cancelled act
            }

            $key = $start->format('Y-m-d').'|'.ImportedEvent::normalize($title);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            yield new ImportedEvent(
                title: $title,
                startsAt: $start,
                endsAt: null,
                allDay: false,
                description: $this->extractDescription($body, $title),
                venueName: $venue,
                city: $city,
                locationText: $venue.', '.$city,
                categorySlug: 'musik',
                sourceUrl: $url,
                imageUrl: $this->extractImage($body),
                price: null,
                organizer: 'Kulturgemeinschaft Dreiecksplatz',
                externalId: 'dreiecksplatz:freitag18:'.$start->format('Y-m-d'),
                raw: [
                    'date' => $start->format('Y-m-d H:i'),
                    'title' => $title,
                ],
            );
        }
    }

    /**
     * @return array{0: int|null, 1: int|null} [day, month]
     */
    private function parseDayMonth(string $text): array
    {
        if (preg_match('#(\d{1,2})\.\s*([A-Za-zäöüÄÖÜ]+)#u', $text, $m)) {
            $month = self::MONTHS[mb_strtolower($m[2])] ?? null;
            if ($month !== null) {
                return [(int) $m[1], $month];
            }
        }

        return [null, null];
    }

    private function parseTime(string $text): ?string
    {
        if (preg_match('#(\d{1,2})[:.](\d{2})#', $text, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }
        if (preg_match('#(\d{1,2})\s*Uhr#u', $text, $m)) {
            return sprintf('%02d:00', (int) $m[1]);
        }

        return null;
    }

    /**
     * The act title is the leading run of bold lines (`<b>`/`<strong>`) in the
     * content column, before the prose description.
     */
    private function extractTitle(Crawler $body): ?string
    {
        $parts = [];
        foreach ($body->filter('b, strong')->each(static fn (Crawler $n): string => $n->text('')) as $bold) {
            $bold = $this->collapse($bold);
            if ($bold === '') {
                continue;
            }
            // Bold time/date fragments are not part of the title.
            if (preg_match('#^\d{1,2}([:.]\d{2}|\.\s|\s*Uhr)#u', $bold)) {
                continue;
            }
            $parts[] = $bold;
        }

        $title = $this->collapse(implode(' ', $parts));
        $title = trim($title, " *");

        return $title !== '' ? $title : null;
    }

    private function extractDescription(Crawler $body, string $title): ?string
    {
        $text = $this->collapse($body->text(''));
        // Drop Word/Office paste artefacts that Jimdo leaves in the markup
        // (e.g. "Normal 0 21 false false false DE X-NONE"). Only the leading
        // bare "0 21" counter and the keyword tokens are removed; numbers in
        // the prose itself (years, counts) are preserved.
        $text = preg_replace('#\b(Normal|MicrosoftInternetExplorer4)\b\s*\d*(\s+\d+)*#u', ' ', $text) ?? $text;
        $text = preg_replace('#\b(false|true|DE|X-NONE)\b#u', ' ', $text) ?? $text;
        // Remove the title prefix so the description starts at the prose.
        if (stripos($text, $title) === 0) {
            $text = substr($text, \strlen($title));
        }
        $text = $this->collapse($text);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > 400) {
            $text = mb_substr($text, 0, 397).'...';
        }

        return $text;
    }

    private function extractImage(Crawler $body): ?string
    {
        $img = $body->filter('img');
        if ($img->count() === 0) {
            return null;
        }
        // Prefer the largest candidate from the srcset, else the plain src.
        $srcset = $img->eq(0)->attr('srcset') ?? '';
        $best = null;
        $bestW = 0;
        foreach (explode(',', $srcset) as $cand) {
            $cand = trim($cand);
            if (preg_match('#(\S+)\s+(\d+)w#', $cand, $m) && (int) $m[2] > $bestW) {
                $bestW = (int) $m[2];
                $best = $m[1];
            }
        }
        $url = $best ?? $img->eq(0)->attr('data-src') ?? $img->eq(0)->attr('src');
        if (!\is_string($url) || $url === '') {
            return null;
        }
        $url = html_entity_decode($url, \ENT_QUOTES | \ENT_HTML5);

        return str_starts_with($url, 'http') ? $url : null;
    }

    /** Fetch the program page or throw, so a dead source surfaces as a failed run. */
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

    private function collapse(string $text): string
    {
        $text = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5);
        $text = preg_replace('#\s+#u', ' ', $text) ?? $text;

        return trim($text);
    }
}
