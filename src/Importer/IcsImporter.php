<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Generic iCalendar (.ics) importer. Fetches the feed at {@see Source::getUrl()}
 * and yields one {@see ImportedEvent} per VEVENT.
 *
 * Optional source config:
 *   - city:        default city when LOCATION carries no place
 *   - venue:       default venue name
 *   - category:    default category slug
 */
#[AutoconfigureTag('app.source_importer')]
final class IcsImporter implements SourceImporter
{
    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'ics';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl();
        if (!$url) {
            throw new \RuntimeException('ICS source has no URL.');
        }

        $body = $this->http->request('GET', $url, [
            'headers' => ['User-Agent' => 'Dalketicker/1.0 (+https://dalketicker.de)'],
            'timeout' => 30,
        ])->getContent();

        $config = $source->getConfig();
        foreach ($this->splitVevents($this->unfold($body)) as $fields) {
            $event = $this->mapEvent($fields, $config);
            if ($event !== null) {
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
    private function mapEvent(array $fields, array $config): ?ImportedEvent
    {
        $summary = trim($fields['SUMMARY']['value'] ?? '');
        if ($summary === '' || !isset($fields['DTSTART'])) {
            return null;
        }

        $start = $this->parseDate($fields['DTSTART']);
        if ($start === null) {
            return null;
        }
        // Same date-only test as for DTEND below: some feeds carry a bare
        // 8-digit value without the VALUE=DATE param. allDay must match,
        // otherwise the calendar grid treats the inclusive midnight end as a
        // timed "until midnight" end and clips the last day.
        $allDay = $this->isDateOnly($fields['DTSTART']);
        $end = isset($fields['DTEND']) ? $this->parseDate($fields['DTEND']) : null;
        // RFC 5545: a date-only DTEND is exclusive (the day after the last
        // event day), so shift it back; on or before the start day the event
        // is single-day and carries no end.
        if ($end !== null && $this->isDateOnly($fields['DTEND'])) {
            $end = $end->modify('-1 day');
            if ($end <= $start) {
                $end = null;
            }
        }

        $location = trim($fields['LOCATION']['value'] ?? '');

        return new ImportedEvent(
            title: $summary,
            startsAt: $start,
            endsAt: $end,
            allDay: $allDay,
            description: ($fields['DESCRIPTION']['value'] ?? '') ?: null,
            venueName: $location !== '' ? $location : ($config['venue'] ?? null),
            city: $config['city'] ?? null,
            locationText: $location !== '' ? $location : null,
            categorySlug: $this->mapCategory($fields, $config),
            sourceUrl: ($fields['URL']['value'] ?? '') ?: null,
            externalId: ($fields['UID']['value'] ?? '') ?: null,
            raw: array_map(static fn ($f) => $f['value'], $fields),
        );
    }

    /** @param array<string, array{value: string, params: array<string,string>}> $fields */
    private function mapCategory(array $fields, array $config): ?string
    {
        $map = $config['categoryMap'] ?? [];
        $raw = mb_strtolower(trim($fields['CATEGORIES']['value'] ?? ''));
        if ($raw !== '' && isset($map[$raw])) {
            return $map[$raw];
        }

        return $config['category'] ?? null;
    }

    /** @param array{value: string, params: array<string,string>} $field */
    private function isDateOnly(array $field): bool
    {
        return ($field['params']['VALUE'] ?? null) === 'DATE'
            || preg_match('/^\d{8}$/', trim($field['value'])) === 1;
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
            return (new \DateTimeImmutable($m[1].'T'.$m[2], new \DateTimeZone('UTC')))->setTimezone($tz);
        }
        // Local datetime: 20260131T180000
        if (preg_match('/^(\d{8})T(\d{6})$/', $value, $m)) {
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
