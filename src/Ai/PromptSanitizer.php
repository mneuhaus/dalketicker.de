<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Neutralizes scraped text before it is interpolated into an AI prompt. The
 * prompts wrap event data in <event_data>…</event_data> and declare everything
 * inside as pure data — stripping angle brackets guarantees a scraped title,
 * organizer or location can never close that container and smuggle injected
 * instructions past it. Whitespace (incl. newlines) is collapsed so one event
 * stays one prompt line.
 */
final class PromptSanitizer
{
    public static function clean(?string $value): string
    {
        $flat = str_replace(['<', '>'], ' ', $value ?? '');

        return trim(preg_replace('/\s+/u', ' ', $flat) ?? '');
    }
}
