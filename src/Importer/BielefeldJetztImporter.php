<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use App\Enum\BookingStatus;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Scraper for the central Bielefeld.JETZT / City.Team event calendar.
 *
 * The monthly overview is server-rendered Drupal markup. Each card carries a
 * stable node id, a detail link, title, short subtitle, date text, venue text
 * and optional preview image. We intentionally use the list card only; detail
 * pages can be richer, but the list already gives us enough structured facts
 * without adding hundreds of follow-up requests.
 */
#[AutoconfigureTag('app.source_importer')]
final class BielefeldJetztImporter implements SourceImporter
{
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.de)';
    private const DEFAULT_MAX_EVENTS = 800;

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'bielefeld_jetzt';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl();
        if (!$url) {
            throw new \RuntimeException('bielefeld_jetzt source has no URL.');
        }

        $config = $source->getConfig();
        $html = $this->fetch($url);
        $crawler = new Crawler($html, $url);
        $categoryLabels = $this->categoryLabels($crawler);
        $maxEvents = (int) ($config['maxEvents'] ?? self::DEFAULT_MAX_EVENTS);

        $seen = [];
        $count = 0;
        foreach ($crawler->filter('.masonry-item.masonry-view-item')->each(fn (Crawler $node) => $node) as $card) {
            if ($count >= $maxEvents) {
                break;
            }

            $event = $this->mapCard($card, $source, $url, $categoryLabels);
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

    /**
     * @param array<string, string> $categoryLabels
     */
    private function mapCard(Crawler $card, Source $source, string $listUrl, array $categoryLabels): ?ImportedEvent
    {
        $filterKeys = ' '.$this->clean((string) ($card->attr('data-filter-keys') ?? '')).' ';
        if (str_contains($filterKeys, ' k201 ')) {
            return null;
        }

        $link = $card->filter('a.box-item[href]')->first();
        if ($link->count() === 0) {
            return null;
        }

        $sourceUrl = $this->absoluteUrl((string) $link->attr('href'), $listUrl);
        $title = $this->firstText($card, '.masonry-event-content h3');
        if ($sourceUrl === null || $title === '') {
            return null;
        }

        $dateText = '';
        $venue = '';
        foreach ($card->filter('.masonry-event-content p')->each(fn (Crawler $node) => $node) as $p) {
            $text = $this->clean($p->text(''));
            if ($dateText === '' && preg_match('/\d{1,2}\.\d{1,2}\.\d{4}/', $text)) {
                $dateText = $text;
            }
            if ($venue === '' && $p->filter('.bielefeld-ui-ort')->count() > 0) {
                $venue = $text;
            }
        }

        $dates = $this->dates($dateText);
        if ($dates === null) {
            return null;
        }

        $description = $this->firstText($card, '.masonry-event-content > p.mb-3');
        $availability = $this->availabilityText($card);
        $nodeId = $this->clean((string) ($card->attr('data-node-id') ?? ''));
        $imageUrl = $this->imageUrl($card, $listUrl);
        $price = $availability !== null && str_contains(mb_strtolower($availability), 'kostenlos') ? 'kostenlos' : null;

        return new ImportedEvent(
            title: $title,
            startsAt: $dates['start'],
            endsAt: $dates['end'],
            allDay: $dates['allDay'],
            description: $description !== '' ? $this->shorten($description) : null,
            venueName: $venue !== '' ? $venue : null,
            city: 'Bielefeld',
            locationText: $venue !== '' ? $venue : null,
            categorySlug: $this->mapCategory($filterKeys, $categoryLabels, $title.' '.$description),
            sourceUrl: $sourceUrl,
            imageUrl: $imageUrl,
            price: $price,
            organizer: $source->getName(),
            externalId: 'bielefeld-jetzt:'.($nodeId !== '' ? $nodeId : substr(sha1($sourceUrl), 0, 16)).':'.$dates['start']->format('Y-m-d-H-i'),
            raw: ['dateText' => $dateText, 'availability' => $availability],
            bookingStatus: BookingStatus::fromText($availability),
        );
    }

    /**
     * @return array<string, string>
     */
    private function categoryLabels(Crawler $crawler): array
    {
        $labels = [];
        foreach ($crawler->filter('select#k option[value^="k"]')->each(fn (Crawler $node) => $node) as $option) {
            $value = $this->clean((string) ($option->attr('value') ?? ''));
            $label = $this->clean($option->text(''));
            if ($value !== '' && $value !== 'kall' && $label !== '') {
                $labels[$value] = $label;
            }
        }

        return $labels;
    }

    /**
     * @return array{start: \DateTimeImmutable, end: ?\DateTimeImmutable, allDay: bool}|null
     */
    private function dates(string $text): ?array
    {
        $text = $this->clean(str_replace("\xc2\xa0", ' ', $text));
        if (!preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $text, $first, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $tz = new \DateTimeZone('Europe/Berlin');
        $startDate = SafeDate::create((int) $first[3][0], (int) $first[2][0], (int) $first[1][0], 0, 0, $tz);
        if ($startDate === null) {
            return null;
        }

        $rest = trim(substr($text, $first[0][1] + strlen($first[0][0])));
        $endDate = null;
        if (preg_match('/^\s*[-–]\s*(\d{1,2})\.(\d{1,2})\.(\d{4})/', $rest, $endMatch)) {
            $endDate = SafeDate::create((int) $endMatch[3], (int) $endMatch[2], (int) $endMatch[1], 0, 0, $tz);
            $rest = trim(substr($rest, strlen($endMatch[0])));
        }

        [$startTime, $endTime] = $this->times($rest);
        if ($startTime === null) {
            return [
                'start' => $startDate->setTime(0, 0),
                'end' => $endDate !== null ? $endDate->setTime(23, 59) : null,
                'allDay' => true,
            ];
        }

        $start = $startDate->setTime($startTime[0], $startTime[1]);
        $end = null;
        if ($endTime !== null) {
            $endBase = $endDate ?? $startDate;
            $end = $endBase->setTime($endTime[0], $endTime[1]);
        } elseif ($endDate !== null) {
            $end = $endDate->setTime(23, 59);
        }
        if ($end !== null && $end <= $start) {
            $end = null;
        }

        return ['start' => $start, 'end' => $end, 'allDay' => false];
    }

    /**
     * @return array{0: array{0:int,1:int}|null, 1: array{0:int,1:int}|null}
     */
    private function times(string $text): array
    {
        if (preg_match('/(\d{1,2})(?::(\d{2}))?\s*(?:-|–|bis)\s*(\d{1,2})(?::(\d{2}))?\s*Uhr/i', $text, $m)) {
            return [
                [(int) $m[1], (int) $m[2]],
                [(int) $m[3], (int) ($m[4] ?? 0)],
            ];
        }
        if (preg_match('/(\d{1,2})(?::(\d{2}))?\s*Uhr/i', $text, $m)) {
            return [[(int) $m[1], (int) ($m[2] ?? 0)], null];
        }

        return [null, null];
    }

    /**
     * @param array<string, string> $categoryLabels
     */
    private function mapCategory(string $filterKeys, array $categoryLabels, string $fallbackText): string
    {
        foreach (preg_split('/\s+/', trim($filterKeys)) ?: [] as $key) {
            if (!isset($categoryLabels[$key])) {
                continue;
            }
            $slug = $this->categoryFromLabel($categoryLabels[$key]);
            if ($slug !== null) {
                return $slug;
            }
        }

        return $this->categoryFromLabel($fallbackText) ?? 'sonstiges';
    }

    private function categoryFromLabel(string $label): ?string
    {
        $label = mb_strtolower($this->clean($label));

        $rules = [
            'musik' => 'musik', 'konzert' => 'musik', 'orchester' => 'musik',
            'nightlife' => 'party', 'party' => 'party',
            'theater' => 'buehne', 'bühne' => 'buehne', 'comedy' => 'buehne', 'musical' => 'buehne',
            'film' => 'kino', 'kino' => 'kino',
            'ausstellung' => 'kunst', 'museum' => 'kunst', 'kunst' => 'kunst',
            'kind' => 'familie', 'familie' => 'familie',
            'flohmarkt' => 'markt', 'markt' => 'markt',
            'genuss' => 'genuss', 'kulinar' => 'genuss',
            'sport' => 'sport',
            'lesung' => 'bildung', 'vortrag' => 'bildung', 'wissenschaft' => 'bildung',
            'workshop' => 'bildung', 'stadtführung' => 'bildung', 'tagung' => 'bildung', 'messe' => 'bildung',
        ];

        foreach ($rules as $needle => $slug) {
            if (str_contains($label, $needle)) {
                return $slug;
            }
        }

        return null;
    }

    private function availabilityText(Crawler $card): ?string
    {
        $texts = [];
        foreach ($card->filter('.tagline p, .untagline p')->each(fn (Crawler $node) => $node) as $node) {
            $text = $this->clean($node->text(''));
            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts !== [] ? implode(' · ', $texts) : null;
    }

    private function imageUrl(Crawler $card, string $baseUrl): ?string
    {
        $img = $card->filter('img[data-src], img[src]')->first();
        if ($img->count() === 0) {
            return null;
        }
        $src = trim((string) ($img->attr('data-src') ?: $img->attr('src')));
        if ($src === '' || str_starts_with($src, 'data:')) {
            return null;
        }

        return $this->absoluteUrl($src, $baseUrl);
    }

    private function firstText(Crawler $scope, string $selector): string
    {
        $nodes = $scope->filter($selector);
        if ($nodes->count() === 0) {
            return '';
        }

        return $this->clean($nodes->first()->text(''));
    }

    private function clean(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($s, \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));
    }

    private function shorten(string $text): string
    {
        return mb_strlen($text) > 900 ? mb_substr($text, 0, 897).'...' : $text;
    }

    private function absoluteUrl(?string $href, string $base): ?string
    {
        $href = trim((string) $href);
        if ($href === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $parts = parse_url($base);
        if (!$parts || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'].'://'.$parts['host'];
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        $path = isset($parts['path']) ? preg_replace('#/[^/]*$#', '/', $parts['path']) : '/';

        return $origin.$path.$href;
    }
}
