<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves the right {@see SourceImporter} for a source by its importer key.
 */
final class ImporterRegistry
{
    /** @var array<string, SourceImporter> */
    private array $importers = [];

    /**
     * @param iterable<SourceImporter> $importers
     */
    public function __construct(
        #[AutowireIterator('app.source_importer')] iterable $importers,
    ) {
        foreach ($importers as $importer) {
            $this->importers[$importer::getKey()] = $importer;
        }
    }

    public function get(Source $source): SourceImporter
    {
        $key = $source->getImporter();
        if (!isset($this->importers[$key])) {
            throw new \RuntimeException(sprintf(
                'No importer registered for key "%s" (source "%s"). Available: %s',
                $key,
                $source->getKey(),
                implode(', ', array_keys($this->importers)) ?: '(none)',
            ));
        }

        return $this->importers[$key];
    }

    public function has(Source $source): bool
    {
        return isset($this->importers[$source->getImporter()]);
    }

    /** @return string[] */
    public function keys(): array
    {
        return array_keys($this->importers);
    }
}
