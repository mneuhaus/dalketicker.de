<?php

declare(strict_types=1);

namespace App\Tests\Importer;

use App\Importer\SafeDate;
use PHPUnit\Framework\TestCase;

final class SafeDateTest extends TestCase
{
    private \DateTimeZone $tz;

    protected function setUp(): void
    {
        $this->tz = new \DateTimeZone('Europe/Berlin');
    }

    public function testFebruary29InNonLeapYearReturnsNull(): void
    {
        self::assertNull(SafeDate::create(2026, 2, 29, 12, 0, $this->tz));
    }

    public function testFebruary29InLeapYearIsValid(): void
    {
        $date = SafeDate::create(2028, 2, 29, 12, 0, $this->tz);

        self::assertNotNull($date);
        self::assertSame('2028-02-29 12:00', $date->format('Y-m-d H:i'));
    }

    public function testJune31ReturnsNullInsteadOfRollingOverToJuly(): void
    {
        self::assertNull(SafeDate::create(2026, 6, 31, 0, 0, $this->tz));
    }

    public function testValidDateKeepsValuesAndTimezone(): void
    {
        $date = SafeDate::create(2026, 12, 24, 18, 30, $this->tz);

        self::assertNotNull($date);
        self::assertSame('2026-12-24 18:30', $date->format('Y-m-d H:i'));
        self::assertSame('Europe/Berlin', $date->getTimezone()->getName());
    }

    public function testOutOfRangeTimeReturnsNull(): void
    {
        self::assertNull(SafeDate::create(2026, 6, 15, 24, 0, $this->tz));
        self::assertNull(SafeDate::create(2026, 6, 15, 12, 60, $this->tz));
        self::assertNull(SafeDate::create(2026, 6, 15, -1, 0, $this->tz));
    }
}
