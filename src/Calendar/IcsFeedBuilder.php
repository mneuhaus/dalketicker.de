<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Entity\Event;
use Sabre\VObject\Component\VCalendar;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds an RFC 5545 iCalendar (.ics) document from a set of {@see Event}s, so
 * visitors can subscribe to the (optionally filtered) programme in their own
 * calendar app.
 *
 * Generation is delegated to sabre/vobject (the de-facto PHP iCalendar library,
 * also used by CalDAV servers) — it handles line folding, escaping, CRLF and
 * value typing per spec. Timed events are emitted in UTC (so no VTIMEZONE is
 * needed and DST is always correct); all-day events use VALUE=DATE with an
 * exclusive DTEND.
 */
final class IcsFeedBuilder
{
    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    /**
     * @param Event[] $events
     * @param string  $calName  human-readable calendar name (shown in the app)
     */
    public function build(array $events, string $calName): string
    {
        $calendar = new VCalendar();
        $calendar->PRODID = '-//'.$this->prodIdHost($events).'//'.$calName.'//DE';
        $calendar->add('METHOD', 'PUBLISH');
        // Auto-refresh hints (RFC 7986 + the Apple/Microsoft extension). Imports
        // run every 3 h (docker/cron.sh); 6 h keeps subscribers reasonably fresh
        // without every calendar app polling on each import cycle. The response
        // is additionally HTTP-cached for an hour (EventController::feed).
        $calendar->add('REFRESH-INTERVAL', 'PT6H', ['VALUE' => 'DURATION']);
        $calendar->add('X-PUBLISHED-TTL', 'PT6H');
        $calendar->add('X-WR-CALNAME', $calName);
        $calendar->add('X-WR-TIMEZONE', 'Europe/Berlin');

        foreach ($events as $event) {
            $this->addEvent($calendar, $event);
        }

        return $calendar->serialize();
    }

    private function addEvent(VCalendar $calendar, Event $event): void
    {
        $utc = new \DateTimeZone('UTC');
        $stamp = $event->getLastSeenAt()->setTimezone($utc);

        $vevent = $calendar->add('VEVENT', [
            'UID' => 'event-'.$event->getId().'@'.$event->getRegion()->getCanonicalHost(),
            'DTSTAMP' => $stamp,
            'LAST-MODIFIED' => $stamp,
            'SUMMARY' => $event->getTitle(),
            'STATUS' => 'CONFIRMED',
            'URL' => $this->detailUrl($event),
        ]);

        // Start / end. All-day → DATE values with an exclusive end day; timed →
        // UTC instants (Z suffix, no VTIMEZONE required).
        if ($event->isAllDay()) {
            $berlin = new \DateTimeZone('Europe/Berlin');
            $start = $event->getStartsAt()->setTimezone($berlin);
            $endExclusive = ($event->getEndsAt() ?? $event->getStartsAt())->setTimezone($berlin)->modify('+1 day');
            $vevent->add('DTSTART', $start, ['VALUE' => 'DATE']);
            $vevent->add('DTEND', $endExclusive, ['VALUE' => 'DATE']);
        } else {
            $vevent->add('DTSTART', $event->getStartsAt()->setTimezone($utc));
            if ($event->getEndsAt() !== null) {
                $vevent->add('DTEND', $event->getEndsAt()->setTimezone($utc));
            }
        }

        $location = $event->getVenue()?->getFullAddress() ?: $event->getDisplayLocation();
        if ($location !== null && $location !== '') {
            $vevent->add('LOCATION', $location);
        }

        $description = $this->descriptionFor($event);
        if ($description !== '') {
            $vevent->add('DESCRIPTION', $description);
        }

        $categories = [];
        foreach ($event->getCategories() as $category) {
            $categories[] = $category->getName();
        }
        if ($categories !== []) {
            $vevent->add('CATEGORIES', $categories);
        }

        $venue = $event->getVenue();
        if ($venue?->getLatitude() !== null && $venue->getLongitude() !== null) {
            $vevent->add('GEO', [$venue->getLatitude(), $venue->getLongitude()]);
        }
    }

    /**
     * Plain-text description + the dalketicker link. For facts-only (aggregator)
     * sources the foreign description text is omitted — only the link remains.
     */
    private function descriptionFor(Event $event): string
    {
        $parts = [];
        if (!$event->isFactsOnly()) {
            $text = trim(html_entity_decode(strip_tags((string) $event->getDescription()), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
            if ($text !== '') {
                $parts[] = $text;
            }
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

    /** @param Event[] $events */
    private function prodIdHost(array $events): string
    {
        if ($events === []) {
            return 'dalketicker.de';
        }

        return $events[0]->getRegion()->getCanonicalHost();
    }
}
