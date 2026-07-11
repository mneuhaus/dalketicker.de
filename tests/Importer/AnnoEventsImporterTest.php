<?php

declare(strict_types=1);

namespace App\Tests\Importer;

use App\Importer\AnnoEventsImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class AnnoEventsImporterTest extends TestCase
{
    /** @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} */
    private function parseRange(string $raw, string $today): array
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $importer = new AnnoEventsImporter(new MockHttpClient());
        $method = new \ReflectionMethod(AnnoEventsImporter::class, 'parseRange');

        return $method->invoke($importer, $raw, new \DateTimeImmutable($today, $tz), $tz);
    }

    public function testPastSeasonRowIsNotRolledIntoNextYear(): void
    {
        // A June market seen in September: next June is ~9 months ahead, so the
        // row must keep its (past) current-year date instead of fabricating a
        // next-year event the organizer never announced.
        [$start, $end] = $this->parseRange('04. – 07. Juni', '2026-09-15');

        self::assertNotNull($start);
        self::assertSame('2026-06-04', $start->format('Y-m-d'));
        self::assertSame('2026-06-07', $end?->format('Y-m-d'));
    }

    public function testYearBoundaryRowRollsForwardToNextYear(): void
    {
        // A January market seen in December lies within the plausible window
        // and rolls into the coming year.
        [$start, $end] = $this->parseRange('10. – 12. Januar', '2026-12-10');

        self::assertNotNull($start);
        self::assertSame('2027-01-10', $start->format('Y-m-d'));
        self::assertSame('2027-01-12 23:59', $end?->format('Y-m-d H:i'));
    }

    public function testRecentlyEndedRowKeepsCurrentYear(): void
    {
        // Ended less than 40 days ago: no rollover at all.
        [$start] = $this->parseRange('04. – 07. Juni', '2026-07-01');

        self::assertNotNull($start);
        self::assertSame('2026-06-04', $start->format('Y-m-d'));
    }
}
