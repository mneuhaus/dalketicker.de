<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Tally of what one source import did, for CLI/logging output.
 */
final class ImportReport
{
    public int $seen = 0;
    public int $created = 0;
    public int $updated = 0;
    public int $unchanged = 0;
    public int $duplicates = 0;
    public int $errors = 0;
    public ?string $fatal = null;

    public function __construct(public readonly string $sourceKey)
    {
    }

    public function summary(): string
    {
        if ($this->fatal !== null) {
            return sprintf('%s: FEHLER – %s', $this->sourceKey, $this->fatal);
        }

        return sprintf(
            '%s: %d gesehen · %d neu · %d aktualisiert · %d unverändert · %d Duplikate · %d Fehler',
            $this->sourceKey, $this->seen, $this->created, $this->updated, $this->unchanged, $this->duplicates, $this->errors,
        );
    }
}
