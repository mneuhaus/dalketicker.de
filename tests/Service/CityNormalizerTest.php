<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CityNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CityNormalizerTest extends TestCase
{
    private CityNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new CityNormalizer();
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideCases(): iterable
    {
        yield 'canonical name' => ['Gütersloh', 'Gütersloh'];
        yield 'ascii variant' => ['Guetersloh', 'Gütersloh'];
        yield 'with sub-locality suffix' => ['Gütersloh-Isselhorst', 'Gütersloh'];
        yield 'stand-alone district alias' => ['Isselhorst', 'Gütersloh'];
        yield 'alias in another town' => ['Marienfeld', 'Harsewinkel'];
        yield 'canonical with bracket suffix' => ['Halle Westfalen', 'Halle (Westf.)'];
        yield 'hyphenated town, short form' => ['Rheda', 'Rheda-Wiedenbrück'];
        yield 'sz ligature' => ['Schloß Holte-Stukenbrock', 'Schloß Holte-Stukenbrock'];
    }

    #[DataProvider('provideCases')]
    public function testKnownPlacesAreMappedOntoMunicipalities(string $raw, string $expected): void
    {
        self::assertSame($expected, $this->normalizer->normalize($raw));
    }

    public function testUnknownPlaceIsReturnedUnchanged(): void
    {
        self::assertSame('Bielefeld', $this->normalizer->normalize('Bielefeld'));
    }

    public function testEmptyAndNullReturnNull(): void
    {
        self::assertNull($this->normalizer->normalize(null));
        self::assertNull($this->normalizer->normalize('   '));
    }
}
