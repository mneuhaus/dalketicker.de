<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Publication state of an event. Imported events default to Published; the
 * dedup pass demotes cross-source duplicates to Duplicate so they are hidden
 * from listings, and a human can Hide noise.
 */
enum EventStatus: string
{
    case Published = 'published';
    case Hidden = 'hidden';
    case Duplicate = 'duplicate';
    case Pending = 'pending';

    public function isVisible(): bool
    {
        return $this === self::Published;
    }
}
