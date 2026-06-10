<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke importer for the Wapelbad (Verl-Sürenheide) Wix site.
 *
 * The "PROGRAMM" list on /about-1 is rendered server-side as a sequence of
 * rich-text paragraphs of the shape "DD.MM.YYYY • Titel". There is no ICS,
 * RSS or schema.org markup, and Wix mints volatile CSS classes, so we extract
 * the rendered text via {@see Crawler} and match the date/title lines with a
 * regex rather than relying on selectors. A second part of the programme is
 * embedded as an image (wixstatic.com) and is not machine-readable; only the
 * text list is parsed.
 */
#[AutoconfigureTag('app.source_importer')]
final class WapelbadImporter implements SourceImporter
{
    private const DEFAULT_URL = 'https://www.wapelbad.de/about-1';
    private const DEFAULT_CITY = 'Gütersloh';
    private const VENUE = 'Wapelbad';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)';

    /** Sentinel inserted at block boundaries so titles can be delimited cleanly. */
    private const BLOCK_SEP = "\x1e";

    /**
     * Matches one programme line, tolerating the stray double-dot typo seen in
     * the source (e.g. "11.09..2026") and either bullet glyph. The title runs
     * up to the next block boundary (BLOCK_SEP) or end of string.
     */
    private const LINE_RE = '/\b(\d{1,2})\.\.?(\d{1,2})\.\.?(\d{4})\s*[\x{2022}\x{2219}\x{00B7}]\s*([^\x{001e}]+)/u';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'wapelbad';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl() ?: self::DEFAULT_URL;
        $config = $source->getConfig();
        $city = $config['city'] ?? self::DEFAULT_CITY;

        $html = $this->http->request('GET', $url, [
            'headers' => ['User-Agent' => self::USER_AGENT],
            'timeout' => 30,
        ])->getContent();

        $text = $this->renderedText($html);

        $tz = new \DateTimeZone('Europe/Berlin');
        $seen = [];
        if (!preg_match_all(self::LINE_RE, $text, $matches, \PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $m) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];
            $title = $this->cleanTitle($m[4]);

            $start = $title !== '' ? SafeDate::create($year, $month, $day, 0, 0, $tz) : null;
            if ($start === null) {
                continue;
            }

            // externalId: stable per date+title, de-duplicating recurring titles.
            $externalId = sprintf('wapelbad-%04d%02d%02d-%s', $year, $month, $day, ImportedEvent::normalize($title));
            if (isset($seen[$externalId])) {
                continue;
            }
            $seen[$externalId] = true;

            yield new ImportedEvent(
                title: $title,
                startsAt: $start,
                allDay: true,
                venueName: self::VENUE,
                city: $city,
                locationText: self::VENUE.', '.$city,
                categorySlug: $this->mapCategory($title),
                sourceUrl: $url,
                imageUrl: null,
                organizer: self::VENUE,
                externalId: $externalId,
                raw: ['line' => trim($m[0])],
            );
        }
    }

    /** Extract the human-visible text of the page, scripts/styles stripped. */
    private function renderedText(string $html): string
    {
        $crawler = new Crawler($html);
        $crawler->filter('script, style, noscript')->each(static function (Crawler $node): void {
            foreach ($node as $dom) {
                $dom->parentNode?->removeChild($dom);
            }
        });

        // Join with newlines so block boundaries survive for the regex lookahead.
        $body = $crawler->filterXPath('//body');
        $text = $body->count() > 0 ? $body->html() : $html;

        // Mark block ends with a sentinel the regex can anchor the title on,
        // before stripping tags (otherwise neighbouring blocks would merge).
        $text = preg_replace('#</(p|div|li|h[1-6])\s*>|<br\s*/?>#i', '$0'.self::BLOCK_SEP, $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text); // non-breaking space -> normal space

        return $text;
    }

    private function cleanTitle(string $title): string
    {
        $title = html_entity_decode($title, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $title = str_replace("\u{00A0}", ' ', $title);
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;

        return trim($title);
    }

    /** Coarse keyword mapping onto the allowed category slugs. */
    private function mapCategory(string $title): ?string
    {
        $t = mb_strtolower($title);

        return match (true) {
            str_contains($t, 'kunsthandwerkmarkt'), str_contains($t, 'markt') => 'markt',
            str_contains($t, 'kinderdisco'), str_contains($t, 'kids'), str_contains($t, 'familie') => 'familie',
            str_contains($t, 'quiz'), str_contains($t, 'crime night') => 'bildung',
            str_contains($t, 'theater'), str_contains($t, 'kino') => 'buehne',
            str_contains($t, 'beach cup'), str_contains($t, 'cup') => 'sport',
            str_contains($t, 'wapelbeats'), str_contains($t, 'musik'), str_contains($t, 'live'),
            str_contains($t, '90s'), str_contains($t, '2000s'), str_contains($t, 'open air') => 'musik',
            default => null,
        };
    }
}
