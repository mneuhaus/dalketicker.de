<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke HTML scraper for the official municipality of Steinhagen event
 * listing at https://www.steinhagen-app.de/veranstaltungen.
 *
 * The page is fully server-rendered (no ICS/RSS/JSON-LD feed). Every card is an
 * `<article class="portfolio-item">`, but only the event cards carry a
 * `.h4.nobottommargin a` link whose href points at a detail page and ends with
 * a 10-digit Unix start timestamp, e.g.
 *   /veranstaltungen/<slug>/1780264800  ->  2026-06-01 (Europe/Berlin).
 * The leading category-navigation cards have no such timestamped link and are
 * skipped. The image lives in `.portfolio-image a img[src]` (relative
 * `/media/processed/...`). The displayed date in `.portfolio-desc .h5` is a
 * human string ("Montag, 01.06.2026" or "27.05.2026 - 16.12.2026"); we prefer
 * the unambiguous epoch from the href and only fall back to the German date
 * string. The same slug may repeat with different timestamps for recurring
 * dates — we dedupe by full href and emit one event per occurrence.
 */
#[AutoconfigureTag('app.source_importer')]
final class GemSteinhagenImporter implements SourceImporter
{
    private const BASE = 'https://www.steinhagen-app.de';
    private const DEFAULT_LIST = self::BASE.'/veranstaltungen';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.de)';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'gem_steinhagen';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $city = $config['city'] ?? 'Steinhagen';
        $maxEvents = (int) ($config['maxEvents'] ?? 200);
        $listUrl = $source->getUrl() ?: self::DEFAULT_LIST;

        $html = $this->fetch($listUrl);
        if ($html === null) {
            return;
        }

        $tz = new \DateTimeZone('Europe/Berlin');
        $crawler = new Crawler($html, $listUrl);
        $seen = [];
        $count = 0;

        foreach ($crawler->filter('article.portfolio-item') as $node) {
            if ($count >= $maxEvents) {
                break;
            }

            try {
                $article = new Crawler($node);

                $link = $article->filter('.h4.nobottommargin a');
                if ($link->count() === 0) {
                    continue; // category-navigation card, not an event
                }
                $link = $link->first();

                $href = $link->attr('href');
                $title = trim($link->text(''));
                if (!is_string($href) || $href === '' || $title === '') {
                    continue;
                }

                $start = $this->startFromHref($href, $tz);
                if ($start === null) {
                    $start = $this->startFromDisplay($this->text($article, '.portfolio-desc .h5'), $tz);
                }
                if ($start === null) {
                    continue;
                }

                $detailUrl = $this->absolute($href);
                if (isset($seen[$detailUrl])) {
                    continue;
                }
                $seen[$detailUrl] = true;
                ++$count;

                $imageUrl = $this->absolute($this->attr($article, '.portfolio-image a img', 'src'));
                $displayDate = $this->text($article, '.portfolio-desc .h5');

                yield new ImportedEvent(
                    title: $title,
                    startsAt: $start,
                    allDay: true,
                    city: $city,
                    categorySlug: $this->mapCategory($title),
                    sourceUrl: $detailUrl,
                    imageUrl: $imageUrl,
                    organizer: 'Gemeinde Steinhagen',
                    externalId: 'gem_steinhagen:'.$detailUrl,
                    raw: array_filter([
                        'href' => $href,
                        'displayDate' => $displayDate,
                    ], static fn ($v) => $v !== null && $v !== ''),
                );
            } catch (\Throwable) {
                continue;
            }
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

    /**
     * The detail href ends with a 10-digit Unix start timestamp, e.g.
     * /veranstaltungen/<slug>/1780264800 -> 2026-06-01 (Europe/Berlin).
     */
    private function startFromHref(string $href, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!preg_match('#/(\d{10})$#', $href, $m)) {
            return null;
        }

        return (new \DateTimeImmutable('@'.$m[1]))->setTimezone($tz);
    }

    /**
     * Fallback: parse the displayed German date string, either a single
     * "Montag, 01.06.2026" (weekday optional) or a range "27.05.2026 - ..."
     * (we take the first date). Time is midnight (events render as all-day).
     */
    private function startFromDisplay(?string $display, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if ($display === null || !preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $display, $m)) {
            return null;
        }

        return (new \DateTimeImmutable('now', $tz))
            ->setDate((int) $m[3], (int) $m[2], (int) $m[1])
            ->setTime(0, 0);
    }

    private function mapCategory(string $title): ?string
    {
        $haystack = mb_strtolower($title);

        $map = [
            'sport' => ['training', 'sport', 'lauf', 'turnier', 'fitness', 'wanderung', 'yoga', 'yonga', 'beckenboden', 'gym'],
            'musik' => ['konzert', 'musik', 'chor', 'band', 'orchester', 'jazz', 'singen'],
            'party' => ['party', 'disco', 'tanzabend'],
            'buehne' => ['theater', 'kabarett', 'comedy', 'bühne', 'lesung', 'schauspiel', 'oper', 'musical'],
            'kunst' => ['ausstellung', 'kunst', 'galerie', 'vernissage', 'museum'],
            'familie' => ['kinder', 'familie', 'vorlesen', 'lesespaß', 'basteln', 'jugend', 'eltern'],
            'markt' => ['markt', 'flohmarkt', 'basar', 'trödel'],
            'genuss' => ['kulinarisch', 'wein', 'kochen', 'genuss', 'kaffee'],
            'bildung' => ['vortrag', 'seminar', 'workshop', 'kurs', 'führung', 'infoabend', 'bildung'],
        ];

        foreach ($map as $slug => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return $slug;
                }
            }
        }

        return null;
    }

    private function absolute(?string $href): ?string
    {
        if ($href === null || $href === '') {
            return null;
        }
        $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);
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

    private function attr(Crawler $node, string $selector, string $attr): ?string
    {
        $sub = $node->filter($selector);
        if ($sub->count() === 0) {
            return null;
        }

        return $sub->first()->attr($attr);
    }
}
