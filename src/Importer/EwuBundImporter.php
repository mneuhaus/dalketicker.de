<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Region;
use App\Entity\Source;
use App\Service\CityNormalizer;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * EWU Bund uses The Events Calendar and exposes the public event archive via
 * wp-json/tribe/events/v1. The source is nationwide, so each ticker registers
 * the same API URL and this importer keeps only events matching its region.
 *
 * Optional source config:
 *   - postalPrefixes: list<string> first PLZ digits accepted for the region
 *   - perPage:        max 100, defaults to 100
 *   - maxPages:       safety cap, defaults to 10
 */
#[AutoconfigureTag('app.source_importer')]
final class EwuBundImporter implements SourceImporter
{
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.de)';
    private const DEFAULT_URL = 'https://ewu-bund.com/wp-json/tribe/events/v1/events';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CityNormalizer $cityNormalizer,
    ) {
    }

    public static function getKey(): string
    {
        return 'ewu_bund';
    }

    public function import(Source $source): iterable
    {
        $config = $source->getConfig();
        $url = $source->getUrl() ?: self::DEFAULT_URL;
        $perPage = max(1, min(100, (int) ($config['perPage'] ?? 100)));
        $maxPages = max(1, (int) ($config['maxPages'] ?? 10));
        $seen = [];

        for ($page = 1; $page <= $maxPages; ++$page) {
            $response = $this->http->request('GET', $this->withQuery($url, [
                'per_page' => (string) $perPage,
                'page' => (string) $page,
            ]), [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                ],
                'timeout' => 30,
            ]);

            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new \RuntimeException(sprintf('EWU API returned HTTP %d on page %d.', $status, $page));
            }

            try {
                $payload = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \RuntimeException('EWU API returned invalid JSON: '.$e->getMessage(), 0, $e);
            }

            $events = \is_array($payload['events'] ?? null) ? $payload['events'] : [];
            if ($events === []) {
                break;
            }

            foreach ($events as $event) {
                if (!\is_array($event)) {
                    continue;
                }

                $mapped = $this->mapEvent($event, $source);
                if ($mapped === null || isset($seen[$mapped->externalId ?? ''])) {
                    continue;
                }

                if ($mapped->externalId !== null) {
                    $seen[$mapped->externalId] = true;
                }
                yield $mapped;
            }

            $headers = $response->getHeaders(false);
            $totalPages = (int) ($headers['x-tec-totalpages'][0] ?? $headers['x-wp-totalpages'][0] ?? 0);
            if ($totalPages > 0 && $page >= $totalPages) {
                break;
            }
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function mapEvent(array $event, Source $source): ?ImportedEvent
    {
        if (($event['status'] ?? null) !== 'publish' || ($event['hide_from_listings'] ?? false) === true) {
            return null;
        }

        $title = $this->cleanText($this->str($event['title'] ?? ''));
        $start = $this->parseDate($this->str($event['start_date'] ?? ''), $this->str($event['timezone'] ?? 'Europe/Berlin'));
        if ($title === '' || $start === null) {
            return null;
        }

        $matchedCity = $this->matchedRegionCity($event, $source);
        if ($matchedCity === null) {
            return null;
        }

        $end = $this->parseDate($this->str($event['end_date'] ?? ''), $this->str($event['timezone'] ?? 'Europe/Berlin'));
        $venue = $this->assoc($event['venue'] ?? null);
        $organizer = $this->firstAssoc($event['organizer'] ?? null);
        $categories = $this->categorySlugs($event);
        $isCourse = $this->isCourse($title, $categories);

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: $end,
            allDay: (bool) ($event['all_day'] ?? false),
            description: $this->cleanHtml($this->str($event['description'] ?? '')) ?: null,
            venueName: $this->cleanText($this->str($venue['venue'] ?? '')) ?: null,
            city: $matchedCity,
            locationText: $this->locationText($venue) ?: null,
            categorySlug: 'sport',
            sourceUrl: $this->str($event['url'] ?? '') ?: null,
            imageUrl: $this->imageUrl($event['image'] ?? null),
            price: $this->cleanText($this->str($event['cost'] ?? '')) ?: null,
            organizer: $this->cleanText($this->str($organizer['organizer'] ?? '')) ?: null,
            externalId: $this->externalId($event),
            raw: [
                'id' => $event['id'] ?? null,
                'categories' => $categories,
                'venue' => $venue,
            ],
            isCourse: $isCourse,
            categorySlugs: $isCourse ? ['bildung'] : [],
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    private function matchedRegionCity(array $event, Source $source): ?string
    {
        $region = $source->getRegion();
        $venue = $this->assoc($event['venue'] ?? null);
        $city = $this->cleanCity($this->str($venue['city'] ?? ''));
        $normalizedCity = $this->normalizeRegionCity($city, $region);
        if ($normalizedCity !== null) {
            return $normalizedCity;
        }

        $zip = $this->cleanText($this->str($venue['zip'] ?? ''));
        if ($zip !== '' && $this->matchesPostalPrefix($zip, $source)) {
            return $region->getDefaultCity();
        }

        $matchText = implode(' ', [
            $this->str($event['title'] ?? ''),
            $this->str($venue['venue'] ?? ''),
            $this->str($venue['address'] ?? ''),
            $city,
            $zip,
            $this->str($venue['province'] ?? ''),
            $this->str($venue['stateprovince'] ?? ''),
        ]);
        $compactHaystack = $this->key($matchText);
        $tokens = $this->tokens($matchText);

        foreach ($region->getCityAliases() as $alias => $displayCity) {
            if ($displayCity === $region->getDefaultCity()) {
                continue;
            }
            if ($this->matchesPlaceKey($alias, $tokens, $compactHaystack)) {
                return $displayCity;
            }
        }

        foreach ($region->getCities() as $displayCity) {
            if ($displayCity === $region->getDefaultCity()) {
                continue;
            }
            $cityKey = $this->key($displayCity);
            if ($this->matchesPlaceKey($cityKey, $tokens, $compactHaystack)) {
                return $displayCity;
            }
        }

        return null;
    }

    private function normalizeRegionCity(string $city, Region $region): ?string
    {
        if ($city === '') {
            return null;
        }

        $normalized = $this->cityNormalizer->normalize($city, $region);
        if ($normalized === null || $normalized === $region->getDefaultCity()) {
            return null;
        }

        return \in_array($normalized, $region->getCities(), true) ? $normalized : null;
    }

    private function matchesPostalPrefix(string $zip, Source $source): bool
    {
        $zip = preg_replace('/\D+/', '', $zip) ?? '';
        if ($zip === '') {
            return false;
        }

        $prefixes = \is_array($source->getConfig()['postalPrefixes'] ?? null) ? $source->getConfig()['postalPrefixes'] : [];
        foreach ($prefixes as $prefix) {
            if (\is_string($prefix) && str_starts_with($zip, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return list<string>
     */
    private function categorySlugs(array $event): array
    {
        $out = [];
        $categories = \is_array($event['categories'] ?? null) ? $event['categories'] : [];
        foreach ($categories as $category) {
            if (\is_array($category) && \is_string($category['slug'] ?? null)) {
                $out[] = $category['slug'];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $categories
     */
    private function isCourse(string $title, array $categories): bool
    {
        $courseCategories = [
            'apo-ausbildung', 'westernreitabzeichen', 'pferdefuehrerschein',
            'trainerausbildung', 'trainerfortbildung', 'richter-steward-ausbildung',
            'seminar',
        ];
        if (array_intersect($courseCategories, $categories) !== []) {
            return true;
        }

        return preg_match('/\b(lehrgang|seminar|fortbildung|ausbildung|reitabzeichen|pferdefuehrerschein|pferdeführerschein)\b/iu', $title) === 1;
    }

    /**
     * @param array<string, mixed> $venue
     */
    private function locationText(array $venue): string
    {
        $parts = array_filter([
            $this->cleanText($this->str($venue['venue'] ?? '')),
            $this->cleanText($this->str($venue['address'] ?? '')),
            trim(implode(' ', array_filter([
                $this->cleanText($this->str($venue['zip'] ?? '')),
                $this->cleanCity($this->str($venue['city'] ?? '')),
            ]))),
        ], static fn (string $part): bool => $part !== '');

        return implode(', ', array_unique($parts));
    }

    private function parseDate(string $raw, string $timezone): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw, new \DateTimeZone($timezone ?: 'Europe/Berlin'));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $event */
    private function externalId(array $event): ?string
    {
        $id = $event['global_id'] ?? $event['id'] ?? null;
        if (\is_int($id) || \is_string($id)) {
            return 'ewu-bund:'.$id;
        }

        return null;
    }

    private function imageUrl(mixed $image): ?string
    {
        if (!\is_array($image)) {
            return null;
        }

        $url = $image['url'] ?? $image['full']['url'] ?? null;

        return \is_string($url) && str_starts_with($url, 'http') ? $url : null;
    }

    private function cleanHtml(string $html): string
    {
        return $this->cleanText(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $html)));
    }

    private function cleanText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));
    }

    private function cleanCity(string $city): string
    {
        return trim((string) preg_replace('/^\d{5}\s+/u', '', $this->cleanText($city)));
    }

    /** @return array<string, mixed> */
    private function assoc(mixed $value): array
    {
        return \is_array($value) && !array_is_list($value) ? $value : [];
    }

    /** @return array<string, mixed> */
    private function firstAssoc(mixed $value): array
    {
        if (\is_array($value) && array_is_list($value)) {
            return $this->assoc($value[0] ?? null);
        }

        return $this->assoc($value);
    }

    private function str(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, string> $params
     */
    private function withQuery(string $url, array $params): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.http_build_query($params);
    }

    private function key(string $value): string
    {
        $value = mb_strtolower(html_entity_decode(strip_tags($value), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    }

    /**
     * Short municipality names must be exact tokens: "Hiller 1" is not Hille,
     * and a random word containing "verl" is not Verl. Longer compact aliases
     * still match phrases like "Schloß Holte" or "Rheda-Wiedenbrück".
     *
     * @param array<string, true> $tokens
     */
    private function matchesPlaceKey(string $key, array $tokens, string $compactHaystack): bool
    {
        if ($key === '') {
            return false;
        }

        return isset($tokens[$key]) || (mb_strlen($key) >= 6 && str_contains($compactHaystack, $key));
    }

    /** @return array<string, true> */
    private function tokens(string $value): array
    {
        $value = mb_strtolower(html_entity_decode(strip_tags($value), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        preg_match_all('/[a-z0-9]+/', $value, $matches);

        return array_fill_keys($matches[0], true);
    }
}
