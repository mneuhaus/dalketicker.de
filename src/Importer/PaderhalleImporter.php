<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Scraper for the PaderHalle Vue event feed at /data/events.json. */
#[AutoconfigureTag('app.source_importer')]
final class PaderhalleImporter implements SourceImporter
{
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.de)';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'paderhalle';
    }

    public function import(Source $source): iterable
    {
        $pageUrl = $source->getUrl();
        if (!$pageUrl) {
            throw new \RuntimeException('paderhalle source has no URL.');
        }

        $config = $source->getConfig();
        $baseUrl = $this->origin($pageUrl) ?? 'https://www.paderhalle.de';
        $dataUrl = isset($config['dataUrl']) && \is_string($config['dataUrl'])
            ? $config['dataUrl']
            : $baseUrl.'/data/events.json';
        $payload = $this->fetchJson($dataUrl);
        $categories = $this->categoriesByUid($payload['categories'] ?? []);
        $events = $payload['events'] ?? [];
        if (!\is_array($events)) {
            return;
        }

        $seen = [];
        foreach ($events as $raw) {
            if (!\is_array($raw)) {
                continue;
            }
            $event = $this->mapEvent($raw, $categories, $baseUrl, $dataUrl);
            if ($event === null) {
                continue;
            }
            $key = ($event->externalId ?? $event->dedupKey()).'|'.$event->startsAt->format('Y-m-d H:i');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            yield $event;
        }
    }

    /** @return array<string, mixed> */
    private function fetchJson(string $url): array
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => 30,
                'max_redirects' => 5,
            ]);
            $status = $response->getStatusCode();
            $body = $status < 400 ? $response->getContent() : null;
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Fetching %s failed: %s', $url, $e->getMessage()), 0, $e);
        }
        if ($body === null) {
            throw new \RuntimeException(sprintf('Fetching %s failed: HTTP %d', $url, $status));
        }

        try {
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Fetching %s returned invalid JSON: %s', $url, $e->getMessage()), 0, $e);
        }

        return \is_array($data) ? $data : [];
    }

    /**
     * @param mixed $rawCategories
     *
     * @return array<int, string>
     */
    private function categoriesByUid($rawCategories): array
    {
        $categories = [];
        if (!\is_array($rawCategories)) {
            return $categories;
        }

        foreach ($rawCategories as $category) {
            if (!\is_array($category)) {
                continue;
            }
            $uid = (int) ($category['uid'] ?? 0);
            $title = $this->clean((string) ($category['title'] ?? ''));
            if ($uid > 0 && $title !== '') {
                $categories[$uid] = $title;
            }
        }

        return $categories;
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<int, string>   $categories
     */
    private function mapEvent(array $raw, array $categories, string $baseUrl, string $dataUrl): ?ImportedEvent
    {
        $title = $this->title($raw);
        $start = $this->parseDateTime((string) ($raw['date'] ?? ''), (string) ($raw['time'] ?? ''));
        if ($title === '' || $start === null) {
            return null;
        }

        $end = $this->parseDateTime(
            (string) ($raw['enddate'] ?? $raw['endDate'] ?? $raw['date'] ?? ''),
            (string) ($raw['endtime'] ?? $raw['endTime'] ?? ''),
        );
        if ($end !== null && $end <= $start) {
            $end = null;
        }

        $detail = $this->absoluteUrl($this->stringOrNull($raw['detail'] ?? null), $baseUrl);
        $image = $this->absoluteUrl($this->stringOrNull($raw['image'] ?? null), $baseUrl);
        $uid = (string) ($raw['uid'] ?? '');
        $categoryTitle = $categories[(int) ($raw['category_uid'] ?? 0)] ?? null;
        $description = $this->description($raw);
        $time = (string) ($raw['time'] ?? '');
        $presenter = $this->clean((string) ($raw['presenter'] ?? ''));

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: $end,
            allDay: !$this->hasTime($time),
            description: $description,
            venueName: 'PaderHalle',
            city: 'Paderborn',
            locationText: 'PaderHalle, Heiersmauer 45-51, 33098 Paderborn',
            categorySlug: $this->mapCategory($categoryTitle, $title.' '.$description),
            sourceUrl: $detail,
            imageUrl: $image,
            price: $this->stringOrNull($raw['price'] ?? null),
            organizer: $presenter !== '' ? $presenter : 'PaderHalle',
            externalId: $uid !== '' ? 'paderhalle:'.$uid.':'.$start->format('Y-m-d H:i') : null,
            raw: [
                'dataUrl' => $dataUrl,
                'uid' => $uid,
                'category' => $categoryTitle,
                'tickets' => $this->stringOrNull($raw['tickets'] ?? null),
            ],
        );
    }

    /** @param array<string, mixed> $raw */
    private function title(array $raw): string
    {
        $title = $this->clean((string) ($raw['title'] ?? ''));
        $prefix = $this->clean((string) ($raw['titlePrefix'] ?? ''));
        if (($raw['showTitlePrefix'] ?? false) === true && $prefix !== '' && !str_starts_with($title, $prefix)) {
            return trim($prefix.' '.$title);
        }

        return $title;
    }

    /** @param array<string, mixed> $raw */
    private function description(array $raw): ?string
    {
        $parts = [];
        foreach (['teaser', 'text'] as $key) {
            $text = $this->clean(strip_tags((string) ($raw[$key] ?? '')));
            if ($text !== '' && !\in_array($text, $parts, true)) {
                $parts[] = $text;
            }
        }

        return $parts === [] ? null : mb_substr(implode("\n\n", $parts), 0, 2000);
    }

    private function parseDateTime(string $date, string $time): ?\DateTimeImmutable
    {
        $date = $this->clean($date);
        if (!preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $date, $m)) {
            return null;
        }

        $hour = 0;
        $minute = 0;
        if (preg_match('/(\d{1,2}):(\d{2})/', $time, $tm)) {
            $hour = (int) $tm[1];
            $minute = (int) $tm[2];
        }

        return SafeDate::create((int) $m[3], (int) $m[2], (int) $m[1], $hour, $minute, new \DateTimeZone('Europe/Berlin'));
    }

    private function hasTime(string $time): bool
    {
        return preg_match('/\d{1,2}:\d{2}/', $time) === 1;
    }

    private function mapCategory(?string $categoryTitle, string $text): string
    {
        $haystack = mb_strtolower(($categoryTitle ?? '').' '.$text);
        $map = [
            'musik' => ['konzert', 'chor', 'jazz', 'gospel', 'pop', 'rock', 'volksmusik', 'schlager'],
            'party' => ['party', 'ball'],
            'buehne' => ['musical', 'oper', 'operette', 'show', 'revue', 'theater', 'schauspiel', 'comedy', 'kabarett', 'kleinkunst', 'ballett', 'tanz'],
            'familie' => ['kinder', 'familie'],
            'bildung' => ['lesung', 'vortrag'],
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

    private function stringOrNull(mixed $value): ?string
    {
        $string = \is_string($value) ? $this->clean($value) : '';

        return $string !== '' ? $string : null;
    }

    private function clean(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\x{00a0}/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private function absoluteUrl(?string $href, string $baseUrl): ?string
    {
        if ($href === null || $href === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $origin = $this->origin($baseUrl);
        if ($origin === null) {
            return null;
        }

        return $origin.'/'.ltrim($href, '/');
    }
}
