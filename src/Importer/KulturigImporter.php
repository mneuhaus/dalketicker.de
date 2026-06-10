<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Importer for kulturig e.V. (Rietberg) — a TYPO3 "cyt-eventcalendar" list.
 * Each .cyt-eventcalendar-list-item carries clean fields (title, subtitle/
 * artist, a "Fr. 05.06.26" date, "Beginn: HH:MM Uhr", venue + address), so we
 * read them straight from the markup. (The page has no JSON-LD, which is why the
 * generic jsonld importer found nothing.)
 */
#[AutoconfigureTag('app.source_importer')]
final class KulturigImporter implements SourceImporter
{
    private const DEFAULT_URL = 'https://www.kulturig.de/events-tickets/eventkalender.html';
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private const CATEGORY_RULES = [
        'party' => 'party', 'tribute' => 'party', 'disco' => 'party', 'nacht' => 'party',
        'konzert' => 'musik', 'live' => 'musik', 'musik' => 'musik', 'band' => 'musik', 'chor' => 'musik',
        'theater' => 'buehne', 'comedy' => 'buehne', 'kabarett' => 'buehne', 'show' => 'buehne', 'bühne' => 'buehne',
        'lesung' => 'bildung', 'vortrag' => 'bildung', 'führung' => 'bildung',
        'markt' => 'markt', 'fest' => 'markt',
    ];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'kulturig';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl() ?: self::DEFAULT_URL;
        $base = $this->origin($url);

        $html = $this->http->request('GET', $url, [
            'headers' => ['User-Agent' => self::USER_AGENT, 'Accept-Language' => 'de-DE,de;q=0.9'],
            'timeout' => 30,
        ])->getContent();

        $crawler = new Crawler($html);

        foreach ($crawler->filter('.cyt-eventcalendar-list-item') as $node) {
            $event = $this->mapEvent(new Crawler($node), $base, $url);
            if ($event !== null) {
                yield $event;
            }
        }
    }

    private function mapEvent(Crawler $node, string $base, string $listUrl): ?ImportedEvent
    {
        $title = $this->text($node, '.cyt-eventcalendar-list-general-item-title');
        if ($title === '') {
            return null;
        }
        $itemText = $this->clean($node->text(''));

        // Date: prefer the compact "05.06.26" / "05.06.2026" anywhere in the item.
        if (!preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{2,4})/', $itemText, $m)) {
            return null;
        }
        $day = (int) $m[1];
        $month = (int) $m[2];
        $year = (int) $m[3];
        if ($year < 100) {
            $year += 2000;
        }
        $tz = new \DateTimeZone('Europe/Berlin');
        $start = SafeDate::create($year, $month, $day, 0, 0, $tz);
        if ($start === null) {
            return null;
        }
        $allDay = true;
        if (preg_match('/(\d{1,2})[:.](\d{2})\s*Uhr/u', $itemText, $t) || preg_match('/Beginn[:\s]*?(\d{1,2})[:.](\d{2})/iu', $itemText, $t)) {
            $start = $start->setTime((int) $t[1], (int) $t[2]);
            $allDay = false;
        }

        $artist = $this->text($node, '.cyt-eventcalendar-list-general-item-artist');
        $venue = $this->text($node, '.cyt-eventcalendar-detail-item-location');

        // City from the "33397 Rietberg" address fragment if present.
        $city = 'Rietberg';
        if (preg_match('/\b\d{5}\s+([A-Za-zÄÖÜäöüß.\- ]+?)(?:\s*\||$)/u', $itemText, $c)) {
            $city = trim($c[1]);
        }

        // Detail link → stable id + per-event source URL.
        $detailUrl = $listUrl;
        $link = $node->filter('a[href]');
        if ($link->count() > 0) {
            foreach ($link as $a) {
                $href = (new Crawler($a))->attr('href') ?? '';
                if ($href !== '' && !str_contains($href, 'veranstaltungsorte')) {
                    $detailUrl = $this->absolute($base, $href);
                    break;
                }
            }
        }

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            allDay: $allDay,
            description: $artist !== '' ? $artist : null,
            venueName: $venue !== '' ? $venue : null,
            city: $city,
            categorySlug: $this->mapCategory($title.' '.$artist),
            sourceUrl: $detailUrl,
            externalId: 'kulturig-'.substr(sha1($start->format('Y-m-d').'|'.ImportedEvent::normalizeTitle($title)), 0, 16),
        );
    }

    private function mapCategory(string $haystack): string
    {
        $h = mb_strtolower($haystack);
        foreach (self::CATEGORY_RULES as $needle => $slug) {
            if (str_contains($h, $needle)) {
                return $slug;
            }
        }

        return 'sonstiges';
    }

    private function text(Crawler $node, string $selector): string
    {
        $el = $node->filter($selector);

        return $el->count() > 0 ? $this->clean($el->first()->text('')) : '';
    }

    private function clean(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($s, \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));
    }

    private function origin(string $url): string
    {
        $p = parse_url($url);

        return ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '');
    }

    private function absolute(string $base, string $href): string
    {
        if (str_starts_with($href, 'http')) {
            return $href;
        }

        return $base.'/'.ltrim($href, '/');
    }
}
