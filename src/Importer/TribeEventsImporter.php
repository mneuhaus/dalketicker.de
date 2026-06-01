<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Generic importer for any WordPress site running "The Events Calendar", which
 * exposes a clean public REST API at /wp-json/tribe/events/v1/events. The source
 * URL just needs to point at the site (the API base is derived from its host),
 * so the same importer serves Crossnight and any other TEC-powered venue.
 */
#[AutoconfigureTag('app.source_importer')]
final class TribeEventsImporter implements SourceImporter
{
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    private const MAX_PAGES = 10;

    /** Tribe category names (lower-cased, matched as substrings) → our slugs. */
    private const CATEGORY_RULES = [
        'konzert' => 'musik', 'live' => 'musik', 'musik' => 'musik', 'band' => 'musik', 'chor' => 'musik',
        'party' => 'party', 'club' => 'party', 'disco' => 'party', 'nightlife' => 'party',
        'theater' => 'buehne', 'comedy' => 'buehne', 'kabarett' => 'buehne', 'show' => 'buehne', 'bühne' => 'buehne',
        'kino' => 'kino', 'film' => 'kino',
        'lesung' => 'bildung', 'vortrag' => 'bildung', 'workshop' => 'bildung', 'seminar' => 'bildung',
        'markt' => 'markt', 'fest' => 'markt', 'flohmarkt' => 'markt',
        'familie' => 'familie', 'kinder' => 'familie',
        'sport' => 'sport', 'kunst' => 'kunst', 'ausstellung' => 'kunst',
        'kulinar' => 'genuss', 'essen' => 'genuss',
    ];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'tribe_events';
    }

    public function import(Source $source): iterable
    {
        $parts = parse_url($source->getUrl() ?: '');
        $base = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');

        $url = $base.'/wp-json/tribe/events/v1/events?per_page=50&start_date='.$today;
        $tz = new \DateTimeZone('Europe/Berlin');

        for ($page = 0; $page < self::MAX_PAGES && $url !== null; ++$page) {
            $data = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'],
                'timeout' => 30,
            ])->toArray(false);

            foreach (($data['events'] ?? []) as $e) {
                $event = $this->mapEvent($e, $tz);
                if ($event !== null) {
                    yield $event;
                }
            }

            // TEC hands us the absolute URL of the next page when there is one.
            $url = isset($data['next_rest']) && \is_string($data['next_rest']) ? $data['next_rest'] : null;
        }
    }

    /**
     * @param array<string, mixed> $e
     */
    private function mapEvent(array $e, \DateTimeZone $tz): ?ImportedEvent
    {
        $title = $this->clean((string) ($e['title'] ?? ''));
        $startRaw = (string) ($e['start_date'] ?? '');
        if ($title === '' || $startRaw === '') {
            return null;
        }
        $start = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startRaw, $tz);
        if ($start === false) {
            return null;
        }
        $allDay = (bool) ($e['all_day'] ?? false);
        if ($allDay) {
            $start = $start->setTime(0, 0);
        }

        $end = null;
        $endRaw = (string) ($e['end_date'] ?? '');
        if ($endRaw !== '') {
            $end = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $endRaw, $tz) ?: null;
        }

        $venue = \is_array($e['venue'] ?? null) ? $e['venue'] : [];
        $cats = $this->mapCategories($e['categories'] ?? []);

        $desc = $this->clean(strip_tags((string) ($e['description'] ?? '')));
        if (mb_strlen($desc) > 2000) {
            $desc = mb_substr($desc, 0, 1997).'...';
        }

        $image = $e['image'] ?? null;
        $imageUrl = \is_array($image) ? ($image['url'] ?? null) : (\is_string($image) ? $image : null);

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: $end,
            allDay: $allDay,
            description: $desc !== '' ? $desc : null,
            venueName: ($v = $this->clean((string) ($venue['venue'] ?? ''))) !== '' ? $v : null,
            city: ($c = $this->clean((string) ($venue['city'] ?? ''))) !== '' ? $c : null,
            locationText: ($a = $this->clean((string) ($venue['address'] ?? ''))) !== '' ? $a : null,
            categorySlug: $cats[0] ?? null,
            sourceUrl: \is_string($e['url'] ?? null) ? $e['url'] : null,
            imageUrl: \is_string($imageUrl) ? $imageUrl : null,
            price: ($p = $this->clean((string) ($e['cost'] ?? ''))) !== '' ? $p : null,
            externalId: 'tribe-'.($e['id'] ?? sha1($title.$startRaw)),
            categorySlugs: \array_slice($cats, 1),
        );
    }

    /**
     * @param mixed $categories
     *
     * @return list<string>
     */
    private function mapCategories(mixed $categories): array
    {
        $slugs = [];
        foreach (\is_array($categories) ? $categories : [] as $cat) {
            $name = mb_strtolower($this->clean((string) ($cat['name'] ?? '')));
            foreach (self::CATEGORY_RULES as $needle => $slug) {
                if ($name !== '' && str_contains($name, $needle)) {
                    $slugs[$slug] = true;
                    break;
                }
            }
        }

        return array_keys($slugs) ?: ['sonstiges'];
    }

    private function clean(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($s, \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));
    }
}
