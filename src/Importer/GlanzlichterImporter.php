<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke scraper for the single-festival WordPress site
 * https://glanzlichter-openair.de/ (Yoast SEO, no ICS/RSS/Tribe/JSON-LD Events).
 *
 * The event dates are not rendered as scrapeable rows in the page-builder body,
 * but they ARE reliably published — verbatim and identically — in the Yoast
 * `og:description` meta tag, e.g.:
 *   "Termine 2026: 13.08.2026 und 27.08.2026 im Bürgerpark, Holter Str. 155b
 *    in 33758 Schloß Holte-Stukenbrock"
 *
 * The site owner edits this free-text marketing string manually each season, so
 * we parse tolerantly: pull every DD.MM.YYYY occurrence out of the string and
 * emit one Event per date. Title and detail URL come from og:title / og:url; the
 * venue is parsed from the same string. No per-event subpages exist, so every
 * occurrence shares the site root as its (resolving) detail URL.
 */
#[AutoconfigureTag('app.source_importer')]
final class GlanzlichterImporter implements SourceImporter
{
    private const BASE = 'https://glanzlichter-openair.de/';
    private const DEFAULT_TITLE = 'Glanzlichter Open Air';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'glanzlichter';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl() ?: self::BASE;
        $config = $source->getConfig();
        $city = $config['city'] ?? 'Schloß Holte-Stukenbrock';

        $html = $this->fetch($url);

        $description = $this->metaContent($html, 'og:description');
        if ($description === null) {
            return;
        }

        $title = $this->metaContent($html, 'og:title') ?: self::DEFAULT_TITLE;
        $imageUrl = $this->metaContent($html, 'og:image');
        $venue = $this->parseVenue($description);

        $tz = new \DateTimeZone('Europe/Berlin');
        $today = (new \DateTimeImmutable('today', $tz));

        // Tolerant date extraction: every DD.MM.YYYY in the marketing string.
        if (!preg_match_all('/\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b/', $description, $matches, PREG_SET_ORDER)) {
            return;
        }

        $seen = [];
        $count = 0;
        foreach ($matches as $m) {
            if ($count >= 200) {
                break;
            }

            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];
            if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
                continue;
            }

            // Open-air evening event; time not published, default to 18:00.
            $start = \DateTimeImmutable::createFromFormat(
                'Y-n-j H:i',
                sprintf('%d-%d-%d 18:00', $year, $month, $day),
                $tz,
            );
            if ($start === false) {
                continue;
            }

            $dayKey = $start->format('Y-m-d');
            if (isset($seen[$dayKey])) {
                continue;
            }
            $seen[$dayKey] = true;

            // Skip past dates (festival is recurring; only future occurrences).
            if ($start < $today) {
                continue;
            }

            ++$count;

            yield new ImportedEvent(
                title: $title,
                startsAt: $start,
                allDay: false,
                venueName: $venue,
                city: $city,
                locationText: $venue,
                categorySlug: 'musik',
                sourceUrl: self::BASE,
                imageUrl: $imageUrl,
                organizer: self::DEFAULT_TITLE,
                externalId: 'glanzlichter:'.$dayKey,
                raw: array_filter([
                    'description' => $description,
                    'venue' => $venue,
                ], static fn ($v) => $v !== null && $v !== ''),
            );
        }
    }

    /** Fetch a URL or throw, so a dead source surfaces as a failed run. */
    private function fetch(string $url): string
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => 'Dalketicker/1.0 (+https://dalketicker.de)'],
                'timeout' => 20,
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

    /** Extract a `<meta property="…" content="…">` value, HTML-decoded. */
    private function metaContent(string $html, string $property): ?string
    {
        if (!preg_match(
            '/<meta\s+property=["\']'.preg_quote($property, '/').'["\']\s+content=["\']([^"\']*)["\']/i',
            $html,
            $m,
        )) {
            return null;
        }

        $value = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $value !== '' ? $value : null;
    }

    /**
     * Parse the venue from "… im <Venue>, <Street> in <PLZ City>" by trimming
     * everything before the leading "im "/"in " location preposition. Falls back
     * to null on wording changes.
     */
    private function parseVenue(string $description): ?string
    {
        // Drop the leading "Termine YYYY: <dates>" portion at the location word.
        if (preg_match('/\b(?:im|in der|in)\s+(.+)$/u', $description, $m)) {
            $venue = trim($m[1]);
            // Normalise " in 33758 City" connector to a comma for readability.
            $venue = preg_replace('/\s+in\s+(\d{5})/u', ', $1', $venue) ?? $venue;

            return $venue !== '' ? $venue : null;
        }

        return null;
    }
}
