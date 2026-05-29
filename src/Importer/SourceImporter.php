<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;

/**
 * Reads a {@see Source} and yields normalized {@see ImportedEvent}s. One
 * implementation per source kind (ICS/RSS/HTML/PDF/...) or per tricky site.
 *
 * Implementations are tagged `app.source_importer` and resolved by
 * {@see ImporterRegistry} via {@see self::getKey()}.
 */
interface SourceImporter
{
    /** Stable key matched against {@see Source::getImporter()}. */
    public static function getKey(): string;

    /**
     * @return iterable<ImportedEvent>
     */
    public function import(Source $source): iterable;
}
