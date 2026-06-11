<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VenueRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A physical place where events happen. Venues are deduplicated by a normalized
 * name + city key so that the same hall referenced by several sources collapses
 * into one record.
 */
#[ORM\Entity(repositoryClass: VenueRepository::class)]
#[ORM\Table(name: 'venue')]
#[ORM\UniqueConstraint(name: 'uniq_venue_region_dedup', columns: ['region_id', 'dedup_key'])]
#[ORM\Index(name: 'idx_venue_city', columns: ['city'])]
class Venue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $postalCode = null;

    #[ORM\Column(length: 120)]
    private string $city;

    #[ORM\ManyToOne(targetEntity: Region::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Region $region;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    /** Normalized "name|city" used to merge duplicate venues across sources. */
    #[ORM\Column(length: 200)]
    private string $dedupKey;

    public function __construct(string $name, string $city, Region $region)
    {
        $this->name = $name;
        $this->city = $city;
        $this->region = $region;
        $this->dedupKey = self::buildDedupKey($name, $city);
    }

    public static function buildDedupKey(string $name, string $city): string
    {
        $normalize = static fn (string $v): string => preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($v))) ?? '';

        return substr($normalize($name).'|'.$normalize($city), 0, 200);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function setStreet(?string $street): static
    {
        $this->street = $street;

        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): static
    {
        $this->postalCode = $postalCode;

        return $this;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getRegion(): Region
    {
        return $this->region;
    }

    public function setRegion(Region $region): static
    {
        $this->region = $region;

        return $this;
    }

    public function setCity(string $city): static
    {
        $this->city = $city;
        $this->dedupKey = self::buildDedupKey($this->name, $city);

        return $this;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setCoordinates(?float $latitude, ?float $longitude): static
    {
        $this->latitude = $latitude;
        $this->longitude = $longitude;

        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): static
    {
        $this->website = $website;

        return $this;
    }

    public function getDedupKey(): string
    {
        return $this->dedupKey;
    }

    public function getFullAddress(): string
    {
        return trim(implode(', ', array_filter([
            $this->street,
            trim(($this->postalCode ?? '').' '.$this->city),
        ])));
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
