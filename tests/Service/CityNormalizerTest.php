<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Region;
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

    public function testGueterslohRegionStillUsesStaticFallbackTables(): void
    {
        $region = self::gueterslohRegion();

        self::assertSame('Rheda-Wiedenbrück', $this->normalizer->normalize('Rheda', $region));
        self::assertSame('Rietberg', $this->normalizer->normalize('Neuenkirchen', $region));
    }

    public function testOtherRegionsAreNotMappedThroughGueterslohTables(): void
    {
        $region = self::paderbornRegion();

        // "Verlar" (Salzkotten) must not prefix-match the Gütersloh town "Verl".
        self::assertSame('Verlar', $this->normalizer->normalize('Verlar', $region));
        self::assertSame('Neuenkirchen', $this->normalizer->normalize('Neuenkirchen', $region));
        self::assertSame('Halle (Saale)', $this->normalizer->normalize('Halle (Saale)', $region));
    }

    public function testRegionOwnAliasesAndCityPrefixesStillApply(): void
    {
        $region = self::paderbornRegion();

        self::assertSame('Delbrück', $this->normalizer->normalize('Delbruck', $region));
        self::assertSame('Salzkotten', $this->normalizer->normalize('Salzkotten-Verlar', $region));
        self::assertSame('Kreis Paderborn', $this->normalizer->normalize('Kreis Paderborn', $region));
    }

    private static function gueterslohRegion(): Region
    {
        $region = new Region('guetersloh', 'dalketicker', 'Kreis Gütersloh', 'dalketicker.example');
        $region->setCities(['Gütersloh', 'Rheda-Wiedenbrück', 'Rietberg']);

        return $region;
    }

    private static function paderbornRegion(): Region
    {
        $region = new Region('paderborn', 'paderticker', 'Kreis Paderborn', 'paderticker.example');
        $region->setCities(['Paderborn', 'Delbrück', 'Salzkotten']);
        $region->setCityAliases(['delbruck' => 'Delbrück']);

        return $region;
    }
}
