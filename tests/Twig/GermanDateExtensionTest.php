<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\GermanDateExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class GermanDateExtensionTest extends TestCase
{
    private GermanDateExtension $extension;

    protected function setUp(): void
    {
        // Fixed "now": Wednesday, 2026-06-10 12:00 Europe/Berlin.
        $now = new \DateTimeImmutable('2026-06-10 12:00:00', new \DateTimeZone('Europe/Berlin'));
        $this->extension = new GermanDateExtension(new MockClock($now));
    }

    private function berlin(string $when): \DateTimeImmutable
    {
        return new \DateTimeImmutable($when, new \DateTimeZone('Europe/Berlin'));
    }

    public function testDayLabelForTodayTomorrowAndDayAfter(): void
    {
        self::assertSame('Heute', $this->extension->dayLabel($this->berlin('2026-06-10 23:00')));
        self::assertSame('Morgen', $this->extension->dayLabel($this->berlin('2026-06-11 06:00')));
        self::assertSame('Übermorgen', $this->extension->dayLabel($this->berlin('2026-06-12 06:00')));
    }

    public function testDayLabelFallsBackToWeekdayAndDate(): void
    {
        self::assertSame('Samstag, 20. Juni', $this->extension->dayLabel($this->berlin('2026-06-20 20:00')));
    }

    public function testDayLabelConvertsForeignTimezonesToBerlin(): void
    {
        // 22:30 UTC is already 00:30 of the next day in Berlin => "Morgen".
        $utc = new \DateTimeImmutable('2026-06-10 22:30:00', new \DateTimeZone('UTC'));

        self::assertSame('Morgen', $this->extension->dayLabel($utc));
    }

    public function testDateDropsTheCurrentYear(): void
    {
        self::assertSame('24. Dezember', $this->extension->date($this->berlin('2026-12-24')));
    }

    public function testDateKeepsOtherYears(): void
    {
        self::assertSame('24. Dezember 2025', $this->extension->date($this->berlin('2025-12-24')));
        self::assertSame('1. Januar 2027', $this->extension->date($this->berlin('2027-01-01')));
    }

    public function testDateWithYearForcesTheYear(): void
    {
        self::assertSame('24. Dezember 2026', $this->extension->date($this->berlin('2026-12-24'), true));
    }

    public function testWeekdayShortAndLong(): void
    {
        $saturday = $this->berlin('2026-06-20');

        self::assertSame('Sa', $this->extension->weekday($saturday));
        self::assertSame('Samstag', $this->extension->weekday($saturday, true));
    }

    public function testTimeAndMonthName(): void
    {
        self::assertSame('19:30 Uhr', $this->extension->time($this->berlin('2026-06-20 19:30')));
        self::assertSame('März', $this->extension->monthName(3));
        self::assertSame('', $this->extension->monthName(0));
    }
}
