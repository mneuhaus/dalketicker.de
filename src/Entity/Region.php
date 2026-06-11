<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RegionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One locally focused ticker/area. Despite the product language often saying
 * "Kreis", this stays generic because Bielefeld is a kreisfreie Stadt.
 */
#[ORM\Entity(repositoryClass: RegionRepository::class)]
#[ORM\Table(name: 'region')]
#[ORM\UniqueConstraint(name: 'uniq_region_key', columns: ['region_key'])]
#[ORM\UniqueConstraint(name: 'uniq_region_host', columns: ['canonical_host'])]
class Region
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'region_key', length: 64)]
    private string $key;

    #[ORM\Column(length: 80)]
    private string $siteName;

    #[ORM\Column(length: 120)]
    private string $areaName;

    #[ORM\Column(length: 160)]
    private string $tagline;

    #[ORM\Column(length: 120)]
    private string $canonicalHost;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $hostAliases = [];

    #[ORM\Column(length: 7)]
    private string $themeColor = '#0a8da3';

    #[ORM\Column(length: 1)]
    private string $logoLetter = 'd';

    #[ORM\Column(length: 120)]
    private string $defaultCity;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $cities = [];

    /** @var array<string, string> normalized alias => canonical city */
    #[ORM\Column(type: Types::JSON)]
    private array $cityAliases = [];

    #[ORM\Column]
    private bool $enabled = true;

    public function __construct(string $key, string $siteName, string $areaName, string $canonicalHost)
    {
        $this->key = $key;
        $this->siteName = $siteName;
        $this->areaName = $areaName;
        $this->canonicalHost = $canonicalHost;
        $this->tagline = 'Was läuft '.$this->getAreaInPhrase();
        $this->defaultCity = $areaName;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** @internal Allows tests/tools to model Doctrine's generated identity. */
    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getSiteName(): string
    {
        return $this->siteName;
    }

    public function setSiteName(string $siteName): static
    {
        $this->siteName = $siteName;

        return $this;
    }

    public function getAreaName(): string
    {
        return $this->areaName;
    }

    public function getAreaInPhrase(): string
    {
        if (str_starts_with($this->areaName, 'Kreis ')) {
            return 'im '.$this->areaName;
        }

        return 'in '.$this->areaName;
    }

    public function getAreaFromPhrase(): string
    {
        if (str_starts_with($this->areaName, 'Kreis ')) {
            return 'aus dem '.$this->areaName;
        }

        return 'aus '.$this->areaName;
    }

    public function setAreaName(string $areaName): static
    {
        $this->areaName = $areaName;

        return $this;
    }

    public function getTagline(): string
    {
        return $this->tagline;
    }

    public function setTagline(string $tagline): static
    {
        $this->tagline = $tagline;

        return $this;
    }

    public function getCanonicalHost(): string
    {
        return $this->canonicalHost;
    }

    public function setCanonicalHost(string $canonicalHost): static
    {
        $this->canonicalHost = mb_strtolower($canonicalHost);

        return $this;
    }

    /** @return list<string> */
    public function getHostAliases(): array
    {
        return $this->hostAliases;
    }

    /** @param list<string> $hostAliases */
    public function setHostAliases(array $hostAliases): static
    {
        $aliases = array_map(static fn (string $host): string => mb_strtolower($host), $hostAliases);
        $this->hostAliases = array_values(array_unique(array_filter($aliases)));

        return $this;
    }

    public function matchesHost(string $host): bool
    {
        $host = mb_strtolower(preg_replace('/:\d+$/', '', trim($host)) ?? $host);

        return $host === $this->canonicalHost || \in_array($host, $this->hostAliases, true);
    }

    /** Absolute base URL for cross-region links. */
    public function getBaseUrl(): string
    {
        return 'https://'.$this->canonicalHost;
    }

    public function getThemeColor(): string
    {
        return $this->themeColor;
    }

    public function setThemeColor(string $themeColor): static
    {
        $this->themeColor = $themeColor;

        return $this;
    }

    public function getLogoLetter(): string
    {
        return $this->logoLetter;
    }

    public function setLogoLetter(string $logoLetter): static
    {
        $letter = mb_strtolower(mb_substr(trim($logoLetter), 0, 1));
        $this->logoLetter = $letter !== '' ? $letter : mb_substr($this->siteName, 0, 1);

        return $this;
    }

    public function getDefaultCity(): string
    {
        return $this->defaultCity;
    }

    public function setDefaultCity(string $defaultCity): static
    {
        $this->defaultCity = $defaultCity;

        return $this;
    }

    /** @return list<string> */
    public function getCities(): array
    {
        return $this->cities;
    }

    /** @param list<string> $cities */
    public function setCities(array $cities): static
    {
        $this->cities = array_values(array_unique(array_filter(array_map('strval', $cities))));

        return $this;
    }

    /** @return array<string, string> */
    public function getCityAliases(): array
    {
        return $this->cityAliases;
    }

    /** @param array<string, string> $cityAliases */
    public function setCityAliases(array $cityAliases): static
    {
        $this->cityAliases = $cityAliases;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function __toString(): string
    {
        return $this->siteName;
    }
}
