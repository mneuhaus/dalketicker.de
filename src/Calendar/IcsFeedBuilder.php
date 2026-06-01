<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Entity\Event;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds an RFC 5545 iCalendar (.ics) document from a set of {@see Event}s, so
 * visitors can subscribe to the (optionally filtered) programme in their own
 * calendar app. Timed events are emitted in UTC (so no VTIMEZONE is needed and
 * DST is always correct); all-day events use VALUE=DATE with an exclusive DTEND.
 */
final class IcsFeedBuilder
{
    private const PRODID = '-//dalketicker//Veranstaltungen Kreis Gütersloh//DE';

    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    /**
     * @param Event[] $events
     * @param string  $calName  human-readable calendar name (shown in the app)
     */
    public function build(array $events, string $calName): string
    {
        $utc = new \DateTimeZone('UTC');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:'.self::PRODID,
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            // Hint clients to re-poll roughly twice a day (matches the import cron).
            'REFRESH-INTERVAL;VALUE=DURATION:PT12H',
            'X-PUBLISHED-TTL:PT12H',
            'X-WR-CALNAME:'.$this->escapeText($calName),
            'X-WR-TIMEZONE:Europe/Berlin',
        ];

        foreach ($events as $event) {
            array_push($lines, ...$this->vevent($event, $utc));
        }

        $lines[] = 'END:VCALENDAR';

        // RFC 5545: lines are CRLF-separated and folded at 75 octets.
        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    /**
     * @return string[] the VEVENT block lines (unfolded)
     */
    private function vevent(Event $event, \DateTimeZone $utc): array
    {
        $stamp = $event->getLastSeenAt()->setTimezone($utc)->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VEVENT',
            'UID:event-'.$event->getId().'@dalketicker.de',
            'DTSTAMP:'.$stamp,
            'LAST-MODIFIED:'.$stamp,
            'SUMMARY:'.$this->escapeText($event->getTitle()),
        ];

        // Start / end. All-day → DATE values with an exclusive end day.
        if ($event->isAllDay()) {
            $tz = new \DateTimeZone('Europe/Berlin');
            $start = $event->getStartsAt()->setTimezone($tz);
            $endExclusive = ($event->getEndsAt() ?? $event->getStartsAt())->setTimezone($tz)->modify('+1 day');
            $lines[] = 'DTSTART;VALUE=DATE:'.$start->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$endExclusive->format('Ymd');
        } else {
            $lines[] = 'DTSTART:'.$event->getStartsAt()->setTimezone($utc)->format('Ymd\THis\Z');
            if ($event->getEndsAt() !== null) {
                $lines[] = 'DTEND:'.$event->getEndsAt()->setTimezone($utc)->format('Ymd\THis\Z');
            }
        }

        $location = $event->getVenue()?->getFullAddress() ?: $event->getDisplayLocation();
        if ($location !== null && $location !== '') {
            $lines[] = 'LOCATION:'.$this->escapeText($location);
        }

        $description = $this->descriptionFor($event);
        if ($description !== '') {
            $lines[] = 'DESCRIPTION:'.$this->escapeText($description);
        }

        $categories = [];
        foreach ($event->getCategories() as $category) {
            $categories[] = $category->getName();
        }
        if ($categories !== []) {
            $lines[] = 'CATEGORIES:'.$this->escapeText(implode(',', $categories));
        }

        $venue = $event->getVenue();
        if ($venue?->getLatitude() !== null && $venue->getLongitude() !== null) {
            $lines[] = sprintf('GEO:%s;%s', $venue->getLatitude(), $venue->getLongitude());
        }

        $lines[] = 'URL:'.$this->detailUrl($event);
        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /** Plain-text description: strip HTML, then append the dalketicker link. */
    private function descriptionFor(Event $event): string
    {
        $parts = [];
        $text = trim(html_entity_decode(strip_tags((string) $event->getDescription()), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        if ($text !== '') {
            $parts[] = $text;
        }
        $parts[] = 'Mehr Infos: '.$this->detailUrl($event);

        return implode("\n\n", $parts);
    }

    private function detailUrl(Event $event): string
    {
        return $this->urls->generate(
            'event_show',
            ['id' => $event->getId(), 'slug' => $event->getSlug()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    /** Escape a TEXT value per RFC 5545 §3.3.11. */
    private function escapeText(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'],
            $value,
        );
    }

    /**
     * Fold a content line to <=75 octets, continuing with CRLF + a leading space
     * (RFC 5545 §3.1). Folding is byte-aware so multibyte UTF-8 isn't split.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = '';
        $current = 0;
        $len = strlen($line);
        for ($i = 0; $i < $len;) {
            // Width of the next UTF-8 code point in bytes.
            $byte = \ord($line[$i]);
            $width = match (true) {
                $byte >= 0xF0 => 4,
                $byte >= 0xE0 => 3,
                $byte >= 0xC0 => 2,
                default => 1,
            };
            if ($current + $width > 73) { // leave room; continuation adds a space
                $out .= "\r\n ";
                $current = 1;
            }
            $out .= substr($line, $i, $width);
            $current += $width;
            $i += $width;
        }

        return $out;
    }
}
