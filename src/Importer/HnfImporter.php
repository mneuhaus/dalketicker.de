<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Scraper for the Heinz Nixdorf MuseumsForum microdata event list. */
#[AutoconfigureTag('app.source_importer')]
final class HnfImporter implements SourceImporter
{
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.de)';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'hnf';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl();
        if (!$url) {
            throw new \RuntimeException('hnf source has no URL.');
        }

        $html = $this->fetch($url);
        $crawler = new Crawler($html, $url);
        $seen = [];

        foreach ($crawler->filter('dt.vevent[itemtype*="schema.org/Event"]')->each(fn (Crawler $node) => $node) as $entry) {
            $event = $this->mapEntry($entry, $url);
            if ($event === null) {
                continue;
            }
            $key = $event->dedupKey().'|'.$event->sourceUrl;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            yield $event;
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

    private function mapEntry(Crawler $entry, string $listUrl): ?ImportedEvent
    {
        $link = $entry->filter('a[href]')->first();
        $title = $this->firstText($entry, '[itemprop="name"], .title.summary');
        $dateNode = $entry->filter('[itemprop="startDate"], .date')->first();
        if ($link->count() === 0 || $title === '' || $dateNode->count() === 0) {
            return null;
        }

        $sourceUrl = $this->absoluteUrl($link->attr('href'), $listUrl);
        $start = $this->parseDate($dateNode);
        if ($sourceUrl === null || $start === null) {
            return null;
        }

        $dateText = $this->clean($dateNode->text(''));
        $allDay = !preg_match('/\d{1,2}:\d{2}/', $dateText);

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: null,
            allDay: $allDay,
            description: null,
            venueName: 'Heinz Nixdorf MuseumsForum',
            city: 'Paderborn',
            locationText: 'Heinz Nixdorf MuseumsForum',
            categorySlug: $this->mapCategory($sourceUrl, $title),
            sourceUrl: $sourceUrl,
            imageUrl: null,
            price: null,
            organizer: 'Heinz Nixdorf MuseumsForum',
            externalId: 'hnf:'.$this->idFromUrl($sourceUrl).':'.$start->format('Y-m-d Hi'),
            raw: [
                'listUrl' => $listUrl,
                'dateText' => $dateText,
            ],
        );
    }

    private function parseDate(Crawler $dateNode): ?\DateTimeImmutable
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $date = (string) ($dateNode->attr('datetime') ?? $dateNode->attr('content') ?? '');
        $text = $this->clean($dateNode->text(''));
        if ($date === '' && preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $text, $m)) {
            $date = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (!preg_match('/(\d{4})-(\d{2})-(\d{2})/', $date, $m)) {
            return null;
        }

        $hour = 0;
        $minute = 0;
        if (preg_match('/(\d{1,2}):(\d{2})/', $text, $tm)) {
            $hour = (int) $tm[1];
            $minute = (int) $tm[2];
        }

        return SafeDate::create((int) $m[1], (int) $m[2], (int) $m[3], $hour, $minute, $tz);
    }

    private function firstText(Crawler $scope, string $selector): string
    {
        $nodes = $scope->filter($selector);
        if ($nodes->count() === 0) {
            return '';
        }

        return $this->clean($nodes->first()->text(''));
    }

    private function clean(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\x{00a0}/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function absoluteUrl(?string $href, string $baseUrl): ?string
    {
        if ($href === null || trim($href) === '') {
            return null;
        }
        $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $base = parse_url($baseUrl);
        if ($base === false || !isset($base['scheme'], $base['host'])) {
            return null;
        }
        $origin = $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }
        $path = $base['path'] ?? '/';
        $dir = substr($path, 0, strrpos($path, '/') ?: 0);

        return $origin.$dir.'/'.$href;
    }

    private function idFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = basename($path, '.html');

        return $base !== '' ? $base : substr(sha1($url), 0, 16);
    }

    private function mapCategory(string $sourceUrl, string $title): string
    {
        $haystack = mb_strtolower($sourceUrl.' '.$title);

        return match (true) {
            str_contains($haystack, '/vortraege/'), str_contains($haystack, 'vortrag') => 'bildung',
            str_contains($haystack, '/workshops/'), str_contains($haystack, 'workshop') => 'bildung',
            str_contains($haystack, 'ferienprogramm'), str_contains($haystack, 'kinder') => 'familie',
            str_contains($haystack, 'schnellschach'), str_contains($haystack, 'turnier') => 'sport',
            default => 'bildung',
        };
    }
}
