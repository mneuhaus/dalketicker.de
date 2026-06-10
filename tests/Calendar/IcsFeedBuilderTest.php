<?php

declare(strict_types=1);

namespace App\Tests\Calendar;

use App\Calendar\IcsFeedBuilder;
use App\Entity\Event;
use App\Entity\Source;
use App\Enum\SourceType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class IcsFeedBuilderTest extends TestCase
{
    private IcsFeedBuilder $builder;

    protected function setUp(): void
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            static fn (string $route, array $params = []) => 'https://dalketicker.de/event/'.$params['id'].'-'.$params['slug'],
        );
        $this->builder = new IcsFeedBuilder($urls);
    }

    private function event(string $title, \DateTimeImmutable $startsAt, int $id = 1): Event
    {
        $source = new Source('test', 'Testquelle', SourceType::Ics);
        $event = new Event($title, $startsAt, $source);
        $event->setSlug('test-event');

        $ref = new \ReflectionProperty(Event::class, 'id');
        $ref->setValue($event, $id);

        return $event;
    }

    private function berlin(string $when): \DateTimeImmutable
    {
        return new \DateTimeImmutable($when, new \DateTimeZone('Europe/Berlin'));
    }

    public function testAllDayEventGetsDateValuesWithExclusiveEnd(): void
    {
        $event = $this->event('Stadtfest', $this->berlin('2026-07-01 00:00'))->setAllDay(true);

        $ics = $this->builder->build([$event], 'Testkalender');

        self::assertStringContainsString('DTSTART;VALUE=DATE:20260701', $ics);
        // Exclusive DTEND: the day after the (single-day) event.
        self::assertStringContainsString('DTEND;VALUE=DATE:20260702', $ics);
    }

    public function testMultiDayAllDayEventEndsTheDayAfterItsLastDay(): void
    {
        $event = $this->event('Schützenfest', $this->berlin('2026-07-03 00:00'))
            ->setAllDay(true)
            ->setEndsAt($this->berlin('2026-07-05 00:00'));

        $ics = $this->builder->build([$event], 'Testkalender');

        self::assertStringContainsString('DTSTART;VALUE=DATE:20260703', $ics);
        self::assertStringContainsString('DTEND;VALUE=DATE:20260706', $ics);
    }

    public function testTimedEventWithEndIsEmittedAsUtcInstants(): void
    {
        // 19:00 Berlin summer time = 17:00 UTC.
        $event = $this->event('Konzert', $this->berlin('2026-07-01 19:00'))
            ->setEndsAt($this->berlin('2026-07-01 21:00'));

        $ics = $this->builder->build([$event], 'Testkalender');

        self::assertStringContainsString('DTSTART:20260701T170000Z', $ics);
        self::assertStringContainsString('DTEND:20260701T190000Z', $ics);
    }

    public function testTimedEventWithoutEndHasNoDtend(): void
    {
        $event = $this->event('Lesung', $this->berlin('2026-07-01 19:00'));

        $ics = $this->builder->build([$event], 'Testkalender');

        self::assertStringContainsString('DTSTART:20260701T170000Z', $ics);
        self::assertStringNotContainsString('DTEND', $ics);
    }

    public function testCalendarCarriesNameAndUidAndDetailUrl(): void
    {
        $event = $this->event('Konzert', $this->berlin('2026-07-01 19:00'), 42);

        $ics = $this->builder->build([$event], 'Mein Kalender');

        self::assertStringContainsString('X-WR-CALNAME:Mein Kalender', $ics);
        self::assertStringContainsString('UID:event-42@dalketicker.de', $ics);
        self::assertStringContainsString('https://dalketicker.de/event/42-test-event', $ics);
    }
}
