<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Technical kind of a source, which determines the importer used to read it.
 */
enum SourceType: string
{
    case Ics = 'ics';
    case Rss = 'rss';
    case Html = 'html';
    case Pdf = 'pdf';
    case Json = 'json';
    case Facebook = 'facebook';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Ics => 'iCal/ICS-Feed',
            self::Rss => 'RSS/Atom-Feed',
            self::Html => 'Webseite (HTML)',
            self::Pdf => 'PDF',
            self::Json => 'JSON/API',
            self::Facebook => 'Facebook',
            self::Manual => 'Manuell',
        };
    }
}
