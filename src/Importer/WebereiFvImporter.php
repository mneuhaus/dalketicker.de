<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke HTML importer for the "Förderverein Die Weberei e.V." (the support
 * association behind the Kulturzentrum), https://weberei-foerderverein.de/termine/.
 *
 * The page is a plain WordPress post with one hand-edited <table>: each <tr> has
 * a date cell ("DD.MM.YYYY") and a description cell. Most rows are *internal*
 * association business (AG/working-group meetings, Vorstandstreffen, the annual
 * general meeting). Only genuinely public events get a dedicated detail page,
 * linked via a "mehr Infos siehe hier" anchor.
 *
 * We therefore import a row only when it (a) carries a detail link AND (b) is not
 * caught by the internal-business blocklist (which also drops the linked but
 * member-only "Jahreshauptversammlung"). The public title is the bold run in the
 * cell, falling back to the cell text trimmed at the first time/notice token.
 */
#[AutoconfigureTag('app.source_importer')]
final class WebereiFvImporter implements SourceImporter
{
    private const DEFAULT_URL = 'https://weberei-foerderverein.de/termine/';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)';

    /** Whole-word markers of an internal/member-only entry to skip. */
    private const INTERNAL_MARKERS = [
        'ag', 'aktiventreffen', 'vorstand', 'mitgliederversammlung', 'mitglieder',
        'jahreshauptversammlung', 'finanzen', 'internes', 'glühweinschlürfen',
        'gemeinsames treffen',
    ];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'weberei_fv';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl() ?: self::DEFAULT_URL;
        $config = $source->getConfig();
        $city = $config['city'] ?? 'Gütersloh';

        $html = $this->http->request('GET', $url, [
            'headers' => ['User-Agent' => self::USER_AGENT],
            'timeout' => 30,
        ])->getContent();

        $crawler = new Crawler($html, $url);
        $tz = new \DateTimeZone('Europe/Berlin');

        foreach ($crawler->filter('tr') as $node) {
            $event = $this->mapRow(new Crawler($node, $url), $tz, $city);
            if ($event !== null) {
                yield $event;
            }
        }
    }

    private function mapRow(Crawler $row, \DateTimeZone $tz, string $city): ?ImportedEvent
    {
        $cells = $row->filter('td');
        if ($cells->count() < 2) {
            return null;
        }

        $dateText = trim($cells->eq(0)->text(''));
        if (!preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $dateText, $d)) {
            return null; // header or stray row
        }

        $cell = $cells->eq(1);
        $body = trim(preg_replace('/\s+/u', ' ', $cell->text('')) ?? '');

        // Public events always link to a detail page; internal meetings never do.
        $detailUrl = $this->detailUrl($cell);
        if ($detailUrl === null) {
            return null;
        }

        // Belt-and-suspenders: drop linked-but-internal rows (Jahreshauptversammlung).
        // Whole-word match so "AG" doesn't trip on "EhrenamtsTAG".
        foreach (self::INTERNAL_MARKERS as $marker) {
            if (preg_match('/\b'.preg_quote($marker, '/').'\b/iu', $body)) {
                return null;
            }
        }

        $title = $this->title($cell, $body);
        if ($title === '') {
            return null;
        }

        [$start, $end, $allDay] = $this->parseTimes($d[1], $d[2], $d[3], $body, $tz);
        if ($start === null) {
            return null;
        }

        $venue = $this->venue($body);

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: $end,
            allDay: $allDay,
            venueName: $venue,
            city: $city,
            locationText: $venue.', '.$city,
            sourceUrl: $detailUrl,
            externalId: $this->externalId($detailUrl),
            raw: ['dateText' => $dateText, 'body' => $body],
        );
    }

    private function detailUrl(Crawler $cell): ?string
    {
        $links = $cell->filter('a[href]');
        if ($links->count() === 0) {
            return null;
        }

        $href = trim((string) $links->first()->attr('href'));
        if ($href === '' || $href === '#') {
            return null;
        }

        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $base = $cell->getUri();

        return $base !== null ? UriResolver::resolve($href, $base) : null;
    }

    /** Title: the bold run if present, else the cell text up to the first time/notice. */
    private function title(Crawler $cell, string $body): string
    {
        $bold = $cell->filter('b, strong');
        if ($bold->count() > 0) {
            $t = trim(preg_replace('/\s+/u', ' ', $bold->first()->text('')) ?? '');
            if ($t !== '') {
                return $t;
            }
        }

        // Fall back to the plain text, cut before the time or the "mehr Infos" notice.
        $t = preg_split('/\s+(?:ab|ca\.|um)?\s*\d{1,2}\s*[:\-]?\s*\d{0,2}\s*Uhr|,?\s*mehr Infos/u', $body)[0] ?? $body;

        return trim(rtrim(trim($t), ','));
    }

    /**
     * @return array{0:?\DateTimeImmutable,1:?\DateTimeImmutable,2:bool}
     *               [start, end, allDay] — first time token in the cell wins as start.
     */
    private function parseTimes(string $day, string $month, string $year, string $body, \DateTimeZone $tz): array
    {
        // Leftmost of: "HH:MM Uhr" | "H-H Uhr" range | "H Uhr".
        if (preg_match('/(\d{1,2}):(\d{2})\s*Uhr|(\d{1,2})\s*-\s*(\d{1,2})\s*Uhr|(\d{1,2})\s*Uhr/u', $body, $m)) {
            if (($m[1] ?? '') !== '') {
                return [$this->at($day, $month, $year, $m[1], $m[2], $tz), null, false];
            }
            if (($m[3] ?? '') !== '') {
                return [
                    $this->at($day, $month, $year, $m[3], '00', $tz),
                    $this->at($day, $month, $year, $m[4], '00', $tz),
                    false,
                ];
            }

            return [$this->at($day, $month, $year, $m[5], '00', $tz), null, false];
        }

        // No time given — treat as an all-day entry.
        return [$this->at($day, $month, $year, '00', '00', $tz), null, true];
    }

    private function at(string $d, string $mo, string $y, string $h, string $i, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $dt = \DateTimeImmutable::createFromFormat(
            'd.m.Y H:i',
            sprintf('%s.%s.%s %02d:%s', $d, $mo, $y, (int) $h, str_pad($i, 2, '0', \STR_PAD_LEFT)),
            $tz,
        );

        return $dt ?: null;
    }

    private function venue(string $body): string
    {
        $h = mb_strtolower($body);
        if (str_contains($h, 'webereipark') || str_contains($h, 'weberei-park')) {
            return 'Weberei-Park';
        }

        return 'Die Weberei';
    }

    private function externalId(string $detailUrl): string
    {
        $slug = trim(parse_url($detailUrl, \PHP_URL_PATH) ?? '', '/');

        return 'weberei-fv-'.($slug !== '' ? $slug : md5($detailUrl));
    }
}
