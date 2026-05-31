<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke HTML scraper for the events programme of the Museum Peter August
 * Boeckstiegel in Werther (Westf.):
 * https://www.museumpab.de/kunstvermittlung/veranstaltungen/
 *
 * The site is a server-rendered TYPO3 page (Tailwind frontend) with NO machine
 * feed: no JSON-LD, no RSS/Atom, no ICS, no tribe/destination.one. Events are
 * free-text paragraphs in the main content area, each a single `<p>` of the
 * form:
 *
 *   <p><strong>TITLE</strong> // [In Kooperation mit …] // [Mit PERSON] //
 *      WEEKDAY DD.MM.YYYY // [TIME e.g. "18–20 Uhr"] // [PRICE] //
 *      [Anmeldung über die <a href="…">VHS X</a>] // …<br>DESCRIPTION</p>
 *
 * Parsing recipe:
 *   - Row selector: `<p>` whose first child is `<strong>` AND whose *header*
 *     (the part before the first `<br>`, i.e. the "//"-separated metadata)
 *     contains a German DD.MM.YYYY date. This avoids the museum-founder's birth
 *     date ("07.04.1889") that appears inside description prose.
 *   - Title: text of the leading `<strong>` (entities decoded, trimmed).
 *   - Date: first DD.MM.YYYY in the header. Two-day events ("Do 16. + Fr.
 *     17.10.2025") list several dates; the earliest is the start.
 *   - Time (optional): "HH[.MM]–HH[.MM] Uhr" (en-dash) in a header field.
 *   - Detail URL: there are no per-event internal detail pages, so sourceUrl is
 *     always the (stable, 200) listing page. External VHS registration links can
 *     rot (404) and aren't the event's own page, so they are kept only in `raw`.
 *   - Venue/city: fixed — Museum Peter August Böckstiegel, Werther (Westf.).
 *
 * The listing is seasonal: it shows the current programme only, so the importer
 * ingests whatever blocks are present and de-dups by title+date. Filtering to
 * upcoming events happens downstream at display time.
 */
#[AutoconfigureTag('app.source_importer')]
final class MuseumPabImporter implements SourceImporter
{
    private const DEFAULT_LIST = 'https://www.museumpab.de/kunstvermittlung/veranstaltungen/';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.de)';
    private const VENUE = 'Museum Peter August Böckstiegel';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'museum_pab';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $city = $config['city'] ?? 'Werther (Westf.)';
        $listUrl = $source->getUrl() ?: self::DEFAULT_LIST;

        $html = $this->fetch($listUrl);
        if ($html === null) {
            return;
        }

        $crawler = new Crawler($html, $listUrl);
        $tz = new \DateTimeZone('Europe/Berlin');
        $seen = [];
        $count = 0;

        foreach ($crawler->filter('p') as $node) {
            if ($count >= 200) {
                break;
            }

            try {
                $event = $this->parseParagraph($node, $listUrl, $city, $tz);
            } catch (\Throwable) {
                continue;
            }
            if ($event === null) {
                continue;
            }

            $dedup = ImportedEvent::normalizeTitle($event->title).'|'.$event->startsAt->format('Y-m-d');
            if (isset($seen[$dedup])) {
                continue;
            }
            $seen[$dedup] = true;
            ++$count;

            yield $event;
        }
    }

    private function parseParagraph(\DOMElement $node, string $listUrl, string $city, \DateTimeZone $tz): ?ImportedEvent
    {
        // Only paragraphs that lead with a <strong> title qualify as event rows.
        $first = null;
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $first = $child;
                break;
            }
            if ($child->nodeType === \XML_TEXT_NODE && trim($child->textContent) !== '') {
                break;
            }
        }
        if (!$first instanceof \DOMElement || strtolower($first->nodeName) !== 'strong') {
            return null;
        }

        $title = $this->clean($first->textContent);
        if ($title === '') {
            return null;
        }

        $p = new Crawler($node);
        $innerHtml = $p->html();

        // Header = everything before the first <br>; the description (which may
        // mention unrelated dates like the artist's birth year) follows it.
        $header = preg_split('/<br\s*\/?>/i', $innerHtml, 2)[0] ?? $innerHtml;
        $headerText = $this->clean(strip_tags($header));

        $start = $this->parseStart($headerText, $tz);
        if ($start === null) {
            return null;
        }

        // External registration link (VHS course page), if any. Kept only as
        // metadata: such links can 404, and they are not the event's own page,
        // so they must not become the sourceUrl.
        $registrationUrl = null;
        $links = $p->filter('a');
        if ($links->count() > 0) {
            $href = $links->first()->attr('href');
            if (is_string($href) && str_starts_with($href, 'http')) {
                $registrationUrl = $href;
            }
        }

        $description = $this->description($innerHtml);
        $price = $this->price($headerText);

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            allDay: $start->format('H:i') === '00:00',
            description: $description,
            venueName: self::VENUE,
            city: $city,
            locationText: self::VENUE.', '.$city,
            categorySlug: $this->mapCategory($title, $description),
            sourceUrl: $listUrl,
            price: $price,
            organizer: self::VENUE,
            externalId: 'museum_pab:'.substr(sha1(ImportedEvent::normalizeTitle($title).$start->format('Y-m-d')), 0, 16),
            raw: array_filter([
                'header' => $headerText,
                'registrationUrl' => $registrationUrl,
            ], static fn ($v) => $v !== null && $v !== ''),
        );
    }

    /**
     * Earliest DD.MM.YYYY in the header, optionally combined with a start time
     * ("18–20 Uhr" / "10–12.30 Uhr"; the hour before the en-dash is the start).
     */
    private function parseStart(string $header, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!preg_match_all('/\b(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})\b/', $header, $m, PREG_SET_ORDER)) {
            return null;
        }

        $earliest = null;
        foreach ($m as $d) {
            $day = (int) $d[1];
            $month = (int) $d[2];
            $year = (int) $d[3];
            if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat(
                'Y-n-j H:i',
                sprintf('%d-%d-%d 00:00', $year, $month, $day),
                $tz,
            );
            if ($date === false) {
                continue;
            }
            if ($earliest === null || $date < $earliest) {
                $earliest = $date;
            }
        }
        if ($earliest === null) {
            return null;
        }

        // Start time: first "HH[.:]MM" or "HH" immediately before "–"/"-" + "Uhr".
        if (preg_match('/(\d{1,2})(?:[.:](\d{2}))?\s*[–—-]\s*\d{1,2}(?:[.:]\d{2})?\s*Uhr/u', $header, $tm)) {
            $hour = (int) $tm[1];
            $minute = isset($tm[2]) && $tm[2] !== '' ? (int) $tm[2] : 0;
            if ($hour <= 23 && $minute <= 59) {
                $earliest = $earliest->setTime($hour, $minute);
            }
        }

        return $earliest;
    }

    private function price(string $header): ?string
    {
        if (preg_match('/(\d+\s*Euro[^\/<]*|Eintritt frei|Eintritt \(Kinder frei\)[^\/<]*)/u', $header, $m)) {
            $price = $this->clean($m[1]);

            return $price !== '' ? $price : null;
        }

        return null;
    }

    private function description(string $innerHtml): ?string
    {
        $parts = preg_split('/<br\s*\/?>/i', $innerHtml, 2);
        if (!is_array($parts) || count($parts) < 2) {
            return null;
        }
        $text = $this->clean(strip_tags($parts[1]));
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > 500) {
            $text = mb_substr($text, 0, 497).'...';
        }

        return $text;
    }

    private function mapCategory(string $title, ?string $description): ?string
    {
        $haystack = mb_strtolower($title.' '.($description ?? ''));

        return match (true) {
            str_contains($haystack, 'familie'),
            str_contains($haystack, 'kinder'),
            str_contains($haystack, 'kita') => 'familie',

            str_contains($haystack, 'workshop'),
            str_contains($haystack, 'führung'),
            str_contains($haystack, 'vortrag'),
            str_contains($haystack, 'seminar'),
            str_contains($haystack, 'kurs') => 'bildung',

            default => 'kunst',
        };
    }

    private function fetch(string $url): ?string
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => 20,
            ]);
            if ($response->getStatusCode() >= 400) {
                return null;
            }

            return $response->getContent();
        } catch (\Throwable) {
            return null;
        }
    }

    private function clean(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
        $value = str_replace("\u{00a0}", ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
