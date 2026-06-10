<?php

declare(strict_types=1);

namespace App\Importer;

/**
 * Guarded date construction for scrapers. PHP's setDate()/setTime() silently
 * normalize out-of-range values ("31.06." becomes 01.07., "29.02.2026" becomes
 * 01.03.2026), which would turn a parse error into a wrong event date. This
 * helper validates with checkdate() and returns null instead, so callers can
 * skip the event like any other parse failure.
 */
final class SafeDate
{
    public static function create(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        \DateTimeZone $tz,
    ): ?\DateTimeImmutable {
        if (!checkdate($month, $day, $year)) {
            return null;
        }
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        return (new \DateTimeImmutable('now', $tz))
            ->setDate($year, $month, $day)
            ->setTime($hour, $minute);
    }
}
