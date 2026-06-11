<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Generic scraper for Sitepark/IES event pages rendering server-side
 * `.SP-ScheduledTeaser` cards, used by paderborn.de.
 */
#[AutoconfigureTag('app.source_importer')]
final class SiteparkScheduledTeaserImporter implements SourceImporter
{
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)';
    private const DEFAULT_MAX_PAGES = 40;
    private const DEFAULT_MAX_EVENTS = 500;

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'sitepark_teasers';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl();
        if (!$url) {
            throw new \RuntimeException('sitepark_teasers source has no URL.');
        }

        $config = $source->getConfig();
        $maxPages = (int) ($config['maxPages'] ?? self::DEFAULT_MAX_PAGES);
        $maxEvents = (int) ($config['maxEvents'] ?? self::DEFAULT_MAX_EVENTS);
        $city = \is_string($config['city'] ?? null) ? $config['city'] : null;
        $venue = \is_string($config['venue'] ?? null) ? $config['venue'] : null;

        $pageUrl = $url;
        $visited = [];
        $seen = [];
        $count = 0;

        for ($page = 0; $page < $maxPages; ++$page) {
            if (isset($visited[$pageUrl])) {
                break;
            }
            $visited[$pageUrl] = true;

            $html = $page === 0 ? $this->fetch($pageUrl) : $this->tryFetch($pageUrl);
            if ($html === null) {
                break;
            }

            $crawler = new Crawler($html, $pageUrl);
            foreach ($crawler->filter('a.SP-ScheduledTeaser[href]')->each(fn (Crawler $node) => $node) as $teaser) {
                if ($count >= $maxEvents) {
                    return;
                }

                $event = $this->mapTeaser($teaser, $source, $pageUrl, $city, $venue, $config);
                if ($event === null) {
                    continue;
                }

                $key = ($event->externalId ?? $event->dedupKey()).'|'.$event->startsAt->format('Y-m-d H:i');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                ++$count;

                yield $event;
            }

            $next = $this->nextPageUrl($crawler, $pageUrl);
            if ($next === null || isset($visited[$next])) {
                break;
            }
            $pageUrl = $next;
        }
    }

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

    private function tryFetch(string $url): ?string
    {
        try {
            return $this->fetch($url);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function mapTeaser(Crawler $teaser, Source $source, string $pageUrl, ?string $city, ?string $venue, array $config): ?ImportedEvent
    {
        $title = $this->text($teaser, '.SP-ScheduledTeaser__headline');
        $href = $teaser->attr('href');
        if ($title === '' || !\is_string($href) || $href === '') {
            return null;
        }

        $times = $teaser->filter('time[datetime]')->each(fn (Crawler $time) => trim((string) $time->attr('datetime')));
        $start = $this->parseDate($times[0] ?? null);
        if ($start === null) {
            return null;
        }
        $end = isset($times[1]) ? $this->parseDate($times[1]) : null;
        if ($end !== null && $end < $start) {
            $end = null;
        }

        $sourceUrl = $this->absoluteUrl($href, $pageUrl);
        $description = $this->text($teaser, '.SP-ScheduledTeaser__abstract');
        $category = \is_string($config['category'] ?? null)
            ? $config['category']
            : $this->mapCategory($title, $description, $href);
        $allDay = $start->format('H:i') === '00:00'
            && ($end === null || $end->format('H:i') === '23:59' || $end->format('H:i') === '00:00');

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: $end,
            allDay: $allDay,
            description: $description !== '' ? $description : null,
            venueName: $venue,
            city: $city,
            locationText: $venue,
            categorySlug: $category,
            sourceUrl: $sourceUrl,
            imageUrl: $this->imageUrl($teaser, $pageUrl),
            organizer: $source->getName(),
            externalId: 'sitepark:'.$source->getKey().':'.$this->idFromUrl($sourceUrl ?? $href).':'.$start->format('Y-m-d-Hi'),
            raw: [
                'href' => $href,
                'sourcePage' => $pageUrl,
            ],
        );
    }

    private function parseDate(?string $raw): ?\DateTimeImmutable
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $tz = new \DateTimeZone('Europe/Berlin');
        $raw = trim($raw);

        foreach (['Y-m-d H:i', 'Y-m-d H:i:s', \DateTimeInterface::ATOM] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $raw, $tz);
            if ($date instanceof \DateTimeImmutable) {
                return $date;
            }
        }

        try {
            return new \DateTimeImmutable($raw, $tz);
        } catch (\Throwable) {
            return null;
        }
    }

    private function nextPageUrl(Crawler $crawler, string $pageUrl): ?string
    {
        $link = $crawler->filter('a[rel="next"], .SP-Paging__button--next[href]')->first();
        if ($link->count() === 0) {
            return null;
        }
        $href = $link->attr('href');
        if (!\is_string($href) || $href === '') {
            return null;
        }

        return $this->absoluteUrl($href, $pageUrl);
    }

    private function imageUrl(Crawler $teaser, string $pageUrl): ?string
    {
        $img = $teaser->filter('img[src]')->first();
        if ($img->count() > 0) {
            $src = $img->attr('src');

            return \is_string($src) && $src !== '' ? $this->absoluteUrl($src, $pageUrl) : null;
        }

        $source = $teaser->filter('source[srcset]')->first();
        if ($source->count() > 0) {
            $srcset = (string) $source->attr('srcset');
            $firstCandidate = trim(explode(',', $srcset)[0]);
            $parts = preg_split('/\s+/', $firstCandidate) ?: [];
            $first = $parts[0] ?? '';

            return $first !== '' ? $this->absoluteUrl($first, $pageUrl) : null;
        }

        return null;
    }

    private function mapCategory(string $title, string $description, string $href): string
    {
        $haystack = mb_strtolower($title.' '.$description.' '.$href);
        $map = [
            'musik' => ['konzert', 'musik', 'chor', 'band', 'jazz', 'orchester'],
            'buehne' => ['theater', 'comedy', 'kabarett', 'bühne', 'buehne', 'thpb', 'studiobühne'],
            'kino' => ['kino', 'film'],
            'kunst' => ['ausstellung', 'kunst', 'museum', 'galerie', 'vernissage'],
            'familie' => ['familie', 'kinder', 'jugend', 'bilderbuch', 'vorlesen', 'bibliothek'],
            'genuss' => ['gastro', 'kulinar', 'wein', 'kochen', 'genuss'],
            'markt' => ['markt', 'fest', 'flohmarkt', 'schützenfest', 'schuetzenfest'],
            'party' => ['party', 'disco', 'nightlife'],
            'sport' => ['sport', 'lauf', 'rad', 'turnier'],
            'bildung' => ['vortrag', 'führung', 'fuehrung', 'workshop', 'kurs', 'seminar'],
        ];

        foreach ($map as $slug => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $slug;
                }
            }
        }

        return 'sonstiges';
    }

    private function text(Crawler $crawler, string $selector): string
    {
        $node = $crawler->filter($selector)->first();
        if ($node->count() === 0) {
            return '';
        }

        return $this->clean($node->text(''));
    }

    private function absoluteUrl(?string $href, string $base): ?string
    {
        if ($href === null || trim($href) === '') {
            return null;
        }
        $href = html_entity_decode(trim($href), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $parts = parse_url($base);
        if (!isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'].'://'.$parts['host'];
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }
        if (str_starts_with($href, '?')) {
            return $origin.($parts['path'] ?? '/').$href;
        }
        $path = $parts['path'] ?? '/';
        $dir = preg_replace('#/[^/]*$#', '/', $path) ?: '/';

        return $origin.$dir.$href;
    }

    private function idFromUrl(string $url): string
    {
        $path = (string) parse_url($url, \PHP_URL_PATH);
        $query = (string) parse_url($url, \PHP_URL_QUERY);

        return substr(sha1($path.'?'.$query), 0, 16);
    }

    private function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));
    }
}
