<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\Clock\ClockInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Lightweight German date formatting that doesn't depend on the intl extension
 * being present at render time. Good enough for our list/month headers.
 */
final class GermanDateExtension extends AbstractExtension
{
    private const WEEKDAYS = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    private const WEEKDAYS_LONG = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    private const MONTHS = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('de_day_label', $this->dayLabel(...)),
            new TwigFilter('de_weekday', $this->weekday(...)),
            new TwigFilter('de_date', $this->date(...)),
            new TwigFilter('de_time', $this->time(...)),
            new TwigFilter('de_month', $this->monthName(...)),
            new TwigFilter('de_display_end', $this->displayEnd(...)),
        ];
    }

    /**
     * End of an event as displayed: for timed events an end at exactly midnight
     * means "until the end of the previous day" (same rule as the month grid),
     * so a 20:00-24:00 event doesn't read as running into the next day.
     */
    public function displayEnd(?\DateTimeInterface $end, \DateTimeInterface $start, bool $allDay = false): ?\DateTimeImmutable
    {
        if ($end === null) {
            return null;
        }
        $end = \DateTimeImmutable::createFromInterface($end);
        if (!$allDay && $end->format('His') === '000000' && $end > $start) {
            return $end->modify('-1 day');
        }

        return $end;
    }

    /** "Heute", "Morgen" or e.g. "Samstag, 31. Mai". */
    public function dayLabel(\DateTimeInterface $date): string
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $today = $this->clock->now()->setTimezone($tz)->setTime(0, 0);
        $target = \DateTimeImmutable::createFromInterface($date)->setTimezone($tz)->setTime(0, 0);
        $diff = (int) $today->diff($target)->format('%r%a');

        return match ($diff) {
            0 => 'Heute',
            1 => 'Morgen',
            2 => 'Übermorgen',
            default => $this->weekday($date, true).', '.$this->date($date),
        };
    }

    public function weekday(\DateTimeInterface $date, bool $long = false): string
    {
        $w = (int) $date->format('w');

        return $long ? self::WEEKDAYS_LONG[$w] : self::WEEKDAYS[$w];
    }

    /** e.g. "31. Mai 2026" (year dropped when current). */
    public function date(\DateTimeInterface $date, bool $withYear = false): string
    {
        $out = (int) $date->format('j').'. '.self::MONTHS[(int) $date->format('n')];
        if ($withYear || $date->format('Y') !== $this->clock->now()->format('Y')) {
            $out .= ' '.$date->format('Y');
        }

        return $out;
    }

    public function time(\DateTimeInterface $date): string
    {
        return $date->format('H:i').' Uhr';
    }

    public function monthName(int|string $month): string
    {
        return self::MONTHS[(int) $month] ?? '';
    }
}
