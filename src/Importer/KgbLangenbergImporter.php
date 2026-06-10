<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke importer for KGB – Kultur.Güter.Bahnhof Langenberg
 * (https://kgb-langenberg.de/), a concert/culture venue.
 *
 * The WordPress site has no event plugin and no schema.org data. The standard
 * WordPress RSS feed (/feed/) is the only reliable machine-readable source, but
 * there is no dedicated event-date field: the event date lives only inside the
 * <title>, e.g. "KOMPARSE – 06.09.2026". <pubDate> is the post publish date and
 * must NOT be used as the event date.
 *
 * Strategy:
 *   - parse the RSS feed
 *   - extract the event date via regex (DD.MM.YYYY) at the end of the title
 *   - artist/event name = title part before the en-dash separator
 *   - skip non-event posts (no date in title)
 *   - extract start time + location heuristically from <content:encoded>
 */
#[AutoconfigureTag('app.source_importer')]
final class KgbLangenbergImporter implements SourceImporter
{
    private const FEED_URL = 'https://kgb-langenberg.de/feed/';
    private const USER_AGENT = 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'kgb_langenberg';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl() ?: self::FEED_URL;

        $body = $this->http->request('GET', $url, [
            'headers' => ['User-Agent' => self::USER_AGENT],
            'timeout' => 30,
        ])->getContent();

        $xml = @simplexml_load_string($body);
        if ($xml === false || !isset($xml->channel)) {
            throw new \RuntimeException('KGB Langenberg feed could not be parsed.');
        }

        $tz = new \DateTimeZone('Europe/Berlin');

        foreach ($xml->channel->item as $item) {
            $event = $this->mapItem($item, $tz);
            if ($event !== null) {
                yield $event;
            }
        }
    }

    private function mapItem(\SimpleXMLElement $item, \DateTimeZone $tz): ?ImportedEvent
    {
        $rawTitle = html_entity_decode(trim((string) $item->title), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        // The event date is the trailing DD.MM.YYYY in the title. Posts without
        // a date (Programmheft, Bus, Taschen, MUSIK10er, ...) are not events.
        if (!preg_match('/(\d{2})\.(\d{2})\.(\d{4})\s*$/', $rawTitle, $m)) {
            return null;
        }

        $day = (int) $m[1];
        $month = (int) $m[2];
        $year = (int) $m[3];

        // Artist / event name = everything before the date, with the trailing
        // separator (en-dash, em-dash or hyphen) and whitespace removed.
        $title = (string) preg_replace('/(\d{2})\.(\d{2})\.(\d{4})\s*$/', '', $rawTitle);
        $title = (string) preg_replace('/[\s\x{2013}\x{2014}\-]+$/u', '', $title);
        $title = trim($title);
        if ($title === '') {
            $title = $rawTitle;
        }

        $content = (string) $this->namespaced($item, 'content', 'encoded');
        [$hour, $minute] = $this->parseStartTime($content);

        $start = SafeDate::create($year, $month, $day, $hour ?? 0, $minute ?? 0, $tz);
        if ($start === null) {
            return null;
        }
        $allDay = $hour === null;

        $link = trim((string) $item->link);
        $externalId = $this->externalId($item, $link);

        return new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: null,
            allDay: $allDay,
            description: $this->cleanDescription((string) $item->description),
            venueName: 'KGB – KulturGüterBahnhof',
            city: 'Langenberg',
            locationText: 'KGB – KulturGüterBahnhof, Bahnhofstr. 14, 33449 Langenberg',
            categorySlug: 'musik',
            sourceUrl: $link !== '' ? $link : null,
            imageUrl: null,
            price: null,
            organizer: 'KGB – Kultur.Güter.Bahnhof Langenberg',
            externalId: $externalId,
            raw: [
                'title' => $rawTitle,
                'pubDate' => (string) $item->pubDate,
                'link' => $link,
            ],
        );
    }

    /**
     * Extracts "Start: 18.00 Uhr" (also "18:00") from the content HTML.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function parseStartTime(string $content): array
    {
        $text = html_entity_decode($content, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        if (preg_match('/Start:?\s*(\d{1,2})[.:](\d{2})\s*Uhr/iu', $text, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            if ($h <= 23 && $min <= 59) {
                return [$h, $min];
            }
        }

        return [null, null];
    }

    private function cleanDescription(string $raw): ?string
    {
        $text = html_entity_decode(strip_tags($raw), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > 600) {
            $text = mb_substr($text, 0, 597).'...';
        }

        return $text;
    }

    private function externalId(\SimpleXMLElement $item, string $link): ?string
    {
        $postId = (string) $this->namespaced($item, 'post-id', null, 'com-wordpress:feed-additions:1');
        if ($postId !== '') {
            return 'kgb-'.$postId;
        }

        $guid = trim((string) $item->guid);
        if ($guid !== '') {
            return $guid;
        }

        return $link !== '' ? $link : null;
    }

    /**
     * Fetches a value from a namespaced child element, tolerating missing
     * namespaces.
     */
    private function namespaced(\SimpleXMLElement $item, string $name, ?string $child, ?string $uri = null): \SimpleXMLElement|string
    {
        $namespaces = $item->getNamespaces(true);

        if ($uri === null) {
            $uri = match ($name) {
                'content' => $namespaces['content'] ?? 'http://purl.org/rss/1.0/modules/content/',
                default => '',
            };
        }

        $node = $uri !== '' ? $item->children($uri) : $item->children();
        $target = $child ?? $name;
        if (isset($node->{$target})) {
            return $node->{$target};
        }

        return '';
    }
}
