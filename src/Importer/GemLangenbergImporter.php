<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Importer for the municipality of Langenberg.
 *
 * The IONAS (TVM) CMS exposes a clean public iCalendar export at
 * https://www.langenberg.de/startseite/kalender/event.ics. We use a bespoke
 * importer instead of the generic {@see IcsImporter} because:
 *   - the feed mixes past and future events (we filter to DTSTART >= today),
 *   - useful data lives in X-* extensions (location name, postal address,
 *     organizer) and ATTACH (image), which the generic importer ignores,
 *   - the event's own detail page URL is not in the ICS directly but can be
 *     reconstructed from the ATTACH image path — but only when that path's
 *     date-slug prefix matches the event's DTSTART date (the image is sometimes
 *     a reused/shared logo whose slug belongs to an unrelated event).
 */
#[AutoconfigureTag('app.source_importer')]
final class GemLangenbergImporter implements SourceImporter
{
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.de)';
    private const BASE = 'https://www.langenberg.de';
    private const MAX_ITEMS = 200;

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'gem_langenberg';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl() ?: self::BASE.'/startseite/kalender/event.ics?weekends=false&tagMode=ALL';

        $body = $this->http->request('GET', $url, [
            'headers' => ['User-Agent' => self::USER_AGENT],
            'timeout' => 20,
        ])->getContent();

        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin'));
        $count = 0;

        foreach ($this->splitVevents($this->unfold($body)) as $fields) {
            if ($count >= self::MAX_ITEMS) {
                break;
            }
            try {
                $event = $this->mapEvent($fields, $today);
            } catch (\Throwable) {
                continue;
            }
            if ($event !== null) {
                ++$count;
                yield $event;
            }
        }
    }

    /** Unfold RFC 5545 folded lines (continuation lines start with space/tab). */
    private function unfold(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        return preg_replace('/\n[ \t]/', '', $body) ?? $body;
    }

    /**
     * @return iterable<array<string, array{value: string, params: array<string,string>}>>
     */
    private function splitVevents(string $body): iterable
    {
        if (!preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $body, $matches)) {
            return;
        }

        foreach ($matches[1] as $block) {
            $fields = [];
            foreach (explode("\n", trim($block)) as $line) {
                if (!str_contains($line, ':')) {
                    continue;
                }
                [$name, $value] = explode(':', $line, 2);
                $params = [];
                $parts = explode(';', $name);
                $key = strtoupper(array_shift($parts));
                foreach ($parts as $param) {
                    if (str_contains($param, '=')) {
                        [$pk, $pv] = explode('=', $param, 2);
                        $params[strtoupper($pk)] = $pv;
                    }
                }
                $fields[$key] = ['value' => $this->unescape($value), 'params' => $params];
            }
            yield $fields;
        }
    }

    /**
     * @param array<string, array{value: string, params: array<string,string>}> $fields
     */
    private function mapEvent(array $fields, \DateTimeImmutable $today): ?ImportedEvent
    {
        $title = trim($fields['SUMMARY']['value'] ?? '');
        if ($title === '' || !isset($fields['DTSTART'])) {
            return null;
        }

        $start = $this->parseDate($fields['DTSTART']);
        if ($start === null) {
            return null;
        }

        // The feed ships past events too — only keep today and future.
        if ($start < $today) {
            return null;
        }

        $allDay = ($fields['DTSTART']['params']['VALUE'] ?? null) === 'DATE';
        $end = isset($fields['DTEND']) ? $this->parseDate($fields['DTEND']) : null;

        $venueName = trim($fields['X-LOCATION-NAME']['value'] ?? ($fields['LOCATION']['value'] ?? ''));
        $locationText = $this->buildLocationText($fields);
        $description = trim($fields['DESCRIPTION']['value'] ?? '');
        $imageUrl = $this->extractImage($fields);
        $sourceUrl = $this->detailUrl($fields, $start);

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: $end,
            allDay: $allDay,
            description: $description !== '' ? $description : null,
            venueName: $venueName !== '' ? $venueName : null,
            city: 'Langenberg',
            locationText: $locationText,
            categorySlug: $this->mapCategory($fields, $title, $description),
            sourceUrl: $sourceUrl,
            imageUrl: $imageUrl,
            organizer: ($fields['X-ORGANIZER-NAME']['value'] ?? '') ?: null,
            externalId: ($fields['UID']['value'] ?? '') ?: null,
            raw: array_map(static fn ($f) => $f['value'], $fields),
        );
    }

    /** @param array<string, array{value: string, params: array<string,string>}> $fields */
    private function buildLocationText(array $fields): ?string
    {
        $parts = array_filter([
            trim($fields['X-LOCATION-NAME']['value'] ?? ''),
            trim($fields['X-STREET-ADDRESS']['value'] ?? ''),
            trim(($fields['X-POSTAL-CODE']['value'] ?? '').' '.($fields['X-LOCALITY']['value'] ?? '')),
        ], static fn (string $p) => $p !== '');

        if ($parts === []) {
            $loc = trim($fields['LOCATION']['value'] ?? '');

            return $loc !== '' ? $loc : null;
        }

        return implode(', ', $parts);
    }

    /** @param array<string, array{value: string, params: array<string,string>}> $fields */
    private function extractImage(array $fields): ?string
    {
        $attach = $fields['ATTACH'] ?? null;
        if ($attach === null) {
            return null;
        }
        $fmt = $attach['params']['FMTTYPE'] ?? '';
        if ($fmt !== '' && !str_starts_with(strtolower($fmt), 'image/')) {
            return null;
        }
        $value = trim($attach['value']);

        return str_starts_with($value, 'http') ? $value : null;
    }

    /**
     * Reconstruct the event's own detail page from the ATTACH image path, but
     * only when that path's date prefix matches the event date — otherwise the
     * image is a reused/shared asset pointing at an unrelated event.
     *
     * @param array<string, array{value: string, params: array<string,string>}> $fields
     */
    private function detailUrl(array $fields, \DateTimeImmutable $start): ?string
    {
        $attach = trim($fields['ATTACH']['value'] ?? '');
        if ($attach === '') {
            return null;
        }
        $date = $start->format('Y-m-d');
        if (preg_match('#/startseite/kalender/('.preg_quote($date, '#').'-[^/]+)/#', $attach, $m)) {
            return self::BASE.'/startseite/kalender/'.$m[1].'/';
        }

        return null;
    }

    /**
     * @param array<string, array{value: string, params: array<string,string>}> $fields
     */
    private function mapCategory(array $fields, string $title, string $description): ?string
    {
        $ionas = mb_strtolower(trim($fields['X-IONAS-CATEGORY']['value'] ?? ($fields['CATEGORIES']['value'] ?? '')));
        $ionasMap = [
            'konzert' => 'musik',
            'fest' => 'party',
        ];
        if (isset($ionasMap[$ionas])) {
            return $ionasMap[$ionas];
        }

        $haystack = mb_strtolower($title.' '.$description);
        $keywords = [
            'musik' => ['konzert', 'musik', 'chor', 'band', 'orchester', 'jazz', 'klavier', 'singen'],
            'party' => ['party', 'disco', 'tanzabend', 'feier', 'fest'],
            'buehne' => ['theater', 'kabarett', 'comedy', 'bühne', 'lesung', 'schauspiel', 'oper', 'musical'],
            'kunst' => ['ausstellung', 'kunst', 'galerie', 'vernissage', 'museum'],
            'familie' => ['kinder', 'familie', 'spielenachmittag', 'basteln', 'jugend', 'eltern'],
            'sport' => ['sport', 'lauf', 'turnier', 'fußball', 'fussball', 'fitness', 'wanderung', 'radtour', 'fahrradtour', 'schwimm'],
            'markt' => ['markt', 'flohmarkt', 'basar', 'trödel'],
            'genuss' => ['kulinarisch', 'wein', 'kochen', 'genuss', 'kaffee', 'essen'],
            'bildung' => ['vortrag', 'seminar', 'workshop', 'kurs', 'führung', 'fuehrung', 'infoabend', 'informationsveranstaltung', 'bildung', 'lesekreis', 'besichtigung'],
        ];
        foreach ($keywords as $slug => $words) {
            foreach ($words as $word) {
                if (str_contains($haystack, $word)) {
                    return $slug;
                }
            }
        }

        return 'sonstiges';
    }

    /** @param array{value: string, params: array<string,string>} $field */
    private function parseDate(array $field): ?\DateTimeImmutable
    {
        $value = trim($field['value']);
        $tzId = $field['params']['TZID'] ?? null;
        $tz = new \DateTimeZone('Europe/Berlin');
        if ($tzId !== null) {
            try {
                $tz = new \DateTimeZone($tzId);
            } catch (\Exception) {
                // fall back to Europe/Berlin
            }
        }

        // UTC form: 20260131T180000Z
        if (preg_match('/^(\d{8})T(\d{6})Z$/', $value, $m)) {
            return (new \DateTimeImmutable($m[1].'T'.$m[2], new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('Europe/Berlin'));
        }
        // Local datetime: 20260131T180000
        if (preg_match('/^\d{8}T\d{6}$/', $value)) {
            return \DateTimeImmutable::createFromFormat('Ymd\THis', $value, $tz) ?: null;
        }
        // Date only: 20260131
        if (preg_match('/^\d{8}$/', $value)) {
            return (\DateTimeImmutable::createFromFormat('Ymd', $value, $tz) ?: null)?->setTime(0, 0);
        }

        return null;
    }

    private function unescape(string $value): string
    {
        return str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $value);
    }
}
