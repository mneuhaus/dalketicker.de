<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke HTML scraper for the Musikschule für den Kreis Gütersloh event
 * listing at https://www.musikschule-guetersloh.de/veranstaltungen.
 *
 * TYPO3 site using the tx_news plugin; the listing is fully server-rendered
 * HTML (no ICS/RSS/JSON-LD feed). Each upcoming event is a single anchor
 * `a[href*="tx_news_pi1%5Baction%5D=detail"]` wrapping a `.grid-x` card with:
 *   - the German title in the anchor's `title` attribute (and inner `<h2>`),
 *   - `<h5 class="subheader">` carrying "Weekday, DD.MM.YYYY",
 *   - one or more `<p>` paragraphs of prose (the first usually contains the
 *     start time as "HH.MM Uhr" / "HH:MM Uhr"),
 *   - `<img class="lazy" data-src="fileadmin/...">` (real image in data-src;
 *     src is just an inline SVG placeholder).
 *
 * Detail hrefs are relative and HTML-escaped (&amp;); they are decoded and
 * prefixed with the site base. The detail page carries the full prose, but the
 * list already gives us title/date/time/image, so no extra request is needed.
 */
#[AutoconfigureTag('app.source_importer')]
final class MusikschuleGtImporter implements SourceImporter
{
    private const BASE = 'https://www.musikschule-guetersloh.de';
    private const DEFAULT_LIST = self::BASE.'/veranstaltungen';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.de)';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'musikschule_gt';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $city = $config['city'] ?? 'Gütersloh';
        $maxEvents = (int) ($config['maxEvents'] ?? 200);
        $url = $source->getUrl() ?: self::DEFAULT_LIST;

        $html = $this->fetch($url);
        if ($html === null) {
            return;
        }

        $tz = new \DateTimeZone('Europe/Berlin');
        $crawler = new Crawler($html, $url);
        $count = 0;
        $seen = [];

        foreach ($crawler->filter('a[href*="tx_news_pi1%5Baction%5D=detail"]') as $node) {
            if ($count >= $maxEvents) {
                break;
            }

            $anchor = new Crawler($node);

            $title = $this->title($anchor);
            if ($title === null) {
                continue;
            }

            $dateText = $this->text($anchor, 'h5.subheader');
            $start = $this->parseStart($dateText, $anchor, $tz);
            if ($start === null) {
                continue;
            }

            $href = $node->getAttribute('href');
            $detailUrl = $this->absolute($href);
            if ($detailUrl === null) {
                continue;
            }

            $externalId = $this->externalId($detailUrl);
            $dedup = $externalId ?? $detailUrl;
            if (isset($seen[$dedup])) {
                continue;
            }
            $seen[$dedup] = true;
            ++$count;

            $description = $this->description($anchor);
            $imageUrl = $this->image($anchor);
            $allDay = $start->format('H:i') === '00:00';

            yield new ImportedEvent(
                title: $title,
                startsAt: $start,
                allDay: $allDay,
                description: $description,
                city: $city,
                categorySlug: 'musik',
                sourceUrl: $detailUrl,
                imageUrl: $imageUrl,
                organizer: 'Musikschule für den Kreis Gütersloh',
                externalId: $externalId,
                raw: array_filter([
                    'date' => $dateText,
                    'href' => $href,
                ], static fn ($v) => $v !== null && $v !== ''),
            );
        }
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

    private function title(Crawler $anchor): ?string
    {
        $h2 = $this->text($anchor, 'h2');
        if ($h2 !== null) {
            return $h2;
        }

        $attr = $anchor->attr('title');
        if (is_string($attr)) {
            $attr = trim(html_entity_decode($attr, ENT_QUOTES | ENT_HTML5));
            if ($attr !== '') {
                return $attr;
            }
        }

        return null;
    }

    /**
     * Build the start datetime from the "Weekday, DD.MM.YYYY" subheader, taking
     * the time-of-day from the first "HH.MM Uhr" / "HH:MM Uhr" in the prose if
     * present; otherwise midnight (treated as all-day).
     */
    private function parseStart(?string $dateText, Crawler $anchor, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if ($dateText === null || !preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $dateText, $d)) {
            return null;
        }

        [$h, $i] = [0, 0];
        $prose = $this->allProse($anchor);
        if ($prose !== '' && preg_match('/\b(\d{1,2})[.:](\d{2})\s*Uhr/u', $prose, $tm)) {
            $hh = (int) $tm[1];
            $mm = (int) $tm[2];
            if ($hh <= 23 && $mm <= 59) {
                [$h, $i] = [$hh, $mm];
            }
        }

        return (new \DateTimeImmutable('now', $tz))
            ->setDate((int) $d[3], (int) $d[2], (int) $d[1])
            ->setTime($h, $i);
    }

    private function description(Crawler $anchor): ?string
    {
        $text = $this->firstProse($anchor);
        if ($text === null) {
            return null;
        }
        if (mb_strlen($text) > 500) {
            $text = mb_substr($text, 0, 497).'...';
        }

        return $text;
    }

    private function firstProse(Crawler $anchor): ?string
    {
        $p = $anchor->filter('p');
        if ($p->count() === 0) {
            return null;
        }
        $text = trim(preg_replace('/\s+/', ' ', $p->first()->text('')) ?? '');

        return $text !== '' ? $text : null;
    }

    private function allProse(Crawler $anchor): string
    {
        $parts = [];
        foreach ($anchor->filter('p') as $p) {
            $text = trim((new Crawler($p))->text(''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode(' ', $parts);
    }

    private function image(Crawler $anchor): ?string
    {
        $img = $anchor->filter('img.lazy, img');
        if ($img->count() === 0) {
            return null;
        }
        $src = $img->first()->attr('data-src');
        if (!is_string($src) || trim($src) === '' || str_starts_with($src, 'data:')) {
            $src = $img->first()->attr('src');
        }
        $src = is_string($src) ? trim($src) : '';
        if ($src === '' || str_starts_with($src, 'data:')) {
            return null;
        }

        return $this->absolute($src);
    }

    private function externalId(string $detailUrl): ?string
    {
        $query = parse_url($detailUrl, PHP_URL_QUERY);
        if (!is_string($query)) {
            return null;
        }
        parse_str($query, $params);
        $id = $params['tx_news_pi1']['news'] ?? null;

        return is_string($id) && $id !== '' ? 'musikschule_gt:'.$id : null;
    }

    private function absolute(?string $href): ?string
    {
        if ($href === null || trim($href) === '') {
            return null;
        }
        $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5);
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        return self::BASE.'/'.ltrim($href, '/');
    }

    private function text(Crawler $node, string $selector): ?string
    {
        $sub = $node->filter($selector);
        if ($sub->count() === 0) {
            return null;
        }
        $text = trim($sub->first()->text(''));

        return $text !== '' ? $text : null;
    }
}
