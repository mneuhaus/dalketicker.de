<?php

declare(strict_types=1);

namespace App\Tests\Importer;

use App\Importer\ImportedEvent;
use PHPUnit\Framework\TestCase;

final class ImportedEventTest extends TestCase
{
    private function event(
        string $title,
        string $start = '2026-06-20 20:00',
        bool $allDay = false,
        ?string $city = null,
        ?string $venueName = null,
    ): ImportedEvent {
        return new ImportedEvent(
            title: $title,
            startsAt: new \DateTimeImmutable($start, new \DateTimeZone('Europe/Berlin')),
            allDay: $allDay,
            venueName: $venueName,
            city: $city,
        );
    }

    public function testUmlautAndTransliterationVariantsCollapseOntoSameKey(): void
    {
        $a = $this->event('Konzert mit Müller', city: 'Gütersloh');
        $b = $this->event('Konzert mit Mueller', city: 'Guetersloh');

        self::assertSame($a->dedupKey(), $b->dedupKey());
    }

    public function testTimedEventsOnSameDayWithDifferentTimesStayDistinct(): void
    {
        $afternoon = $this->event('Kinofilm', '2026-06-20 15:00', city: 'Gütersloh');
        $evening = $this->event('Kinofilm', '2026-06-20 20:00', city: 'Gütersloh');

        self::assertNotSame($afternoon->dedupKey(), $evening->dedupKey());
        self::assertStringContainsString('2026-06-20 1500', $afternoon->dedupKey());
    }

    public function testAllDayEventsMatchOnDayRegardlessOfTime(): void
    {
        $a = $this->event('Stadtfest', '2026-06-20 00:00', allDay: true, city: 'Verl');
        $b = $this->event('Stadtfest', '2026-06-20 09:30', allDay: true, city: 'Verl');

        self::assertSame($a->dedupKey(), $b->dedupKey());
        self::assertStringContainsString('|2026-06-20|', $a->dedupKey());
    }

    public function testTitleNormalizationDropsSuffixAndParentheticals(): void
    {
        $a = $this->event('Sommerkonzert - Eintritt frei', city: 'Halle (Westf.)');
        $b = $this->event('Sommerkonzert (Open Air)', city: 'Halle (Westf.)');

        self::assertSame($a->dedupKey(), $b->dedupKey());
        self::assertSame('sommerkonzert', ImportedEvent::normalizeTitle('Sommerkonzert - Eintritt frei'));
    }

    public function testPlaceFallsBackToVenueWhenCityMissing(): void
    {
        $withVenue = $this->event('Lesung', venueName: 'Stadtbibliothek');

        self::assertStringEndsWith('|stadtbibliothek', $withVenue->dedupKey());
    }

    public function testNormalizeTransliteratesUmlautsAndStripsNonAlnum(): void
    {
        self::assertSame('mueller', ImportedEvent::normalize('Müller'));
        self::assertSame('strassenfest', ImportedEvent::normalize('Straßen-Fest!'));
        self::assertSame('', ImportedEvent::normalize('  ***  '));
    }
}
