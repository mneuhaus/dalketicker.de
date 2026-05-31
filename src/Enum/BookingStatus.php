<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Booking/availability state of a course-like event, derived from the source's
 * status indicator (e.g. KuferWeb's "Ampel"). Used to badge and dim courses
 * that can no longer be joined, so visitors don't get their hopes up.
 */
enum BookingStatus: string
{
    case Open = 'open';          // Plätze frei / Anmeldung möglich
    case FewLeft = 'few_left';   // fast ausgebucht
    case Waitlist = 'waitlist';  // nur noch Warteliste
    case Full = 'full';          // ausgebucht
    case Cancelled = 'cancelled';// fällt aus / abgesagt
    case Closed = 'closed';      // Kurs abgeschlossen / beendet

    /** Map a free-text German status string onto a case (null when unknown). */
    public static function fromText(?string $text): ?self
    {
        $t = mb_strtolower(trim((string) $text));
        if ($t === '') {
            return null;
        }

        return match (true) {
            str_contains($t, 'ausgefallen'), str_contains($t, 'abgesagt'), str_contains($t, 'entfäll'), str_contains($t, 'entfall') => self::Cancelled,
            str_contains($t, 'warteliste') => self::Waitlist,
            str_contains($t, 'fast ausgebucht'), str_contains($t, 'wenige') => self::FewLeft,
            str_contains($t, 'ausgebucht'), str_contains($t, 'belegt') => self::Full,
            str_contains($t, 'abgeschlossen'), str_contains($t, 'beendet'), str_contains($t, 'vorbei') => self::Closed,
            str_contains($t, 'frei'), str_contains($t, 'möglich'), str_contains($t, 'buchbar') => self::Open,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Plätze frei',
            self::FewLeft => 'Fast ausgebucht',
            self::Waitlist => 'Warteliste',
            self::Full => 'Ausgebucht',
            self::Cancelled => 'Fällt aus',
            self::Closed => 'Beendet',
        };
    }

    /** Whether to surface a badge at all (Open is the unremarkable default). */
    public function showBadge(): bool
    {
        return $this !== self::Open;
    }

    /** Effectively un-joinable → dim the card. */
    public function isSoldOut(): bool
    {
        return \in_array($this, [self::Waitlist, self::Full, self::Cancelled, self::Closed], true);
    }

    /** Tailwind classes for the badge. */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Open => 'bg-emerald-100 text-emerald-800',
            self::FewLeft => 'bg-amber-100 text-amber-800',
            self::Waitlist => 'bg-orange-100 text-orange-800',
            self::Full => 'bg-rose-100 text-rose-700',
            self::Cancelled => 'bg-zinc-200 text-zinc-600',
            self::Closed => 'bg-zinc-200 text-zinc-500',
        };
    }
}
