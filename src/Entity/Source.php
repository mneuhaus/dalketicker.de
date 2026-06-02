<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SourceType;
use App\Repository\SourceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An external place we pull events from (city calendar, club site, PDF, ...).
 * The {@see SourceType} plus the importer key decide which importer reads it.
 */
#[ORM\Entity(repositoryClass: SourceRepository::class)]
#[ORM\Table(name: 'source')]
class Source
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Stable machine key, e.g. "stadt_gt", "wapelbad", "gtv1879". */
    #[ORM\Column(length: 64, unique: true)]
    private string $key;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(length: 20, enumType: SourceType::class)]
    private SourceType $type;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $url = null;

    /** Importer service key; defaults to the type when null. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $importer = null;

    #[ORM\Column]
    private bool $enabled = true;

    /**
     * Legal safeguard for third-party aggregator sources: when true, only FACTS
     * are shown (title, date/time, place, price) plus a link to the original —
     * NOT the creative parts (description text, images, AI summary derived from
     * the text). True for competitor/aggregator feeds; false for the original
     * organizer/venue (where we may show their content).
     */
    #[ORM\Column]
    private bool $factsOnly = false;

    /** When we recorded a publishing permission ("Freigabe") from the source. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    /** Note on the approval (who/how, e.g. "Mail von … am …"). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $approvalNote = null;

    /** Filename of an uploaded approval proof (e-mail screenshot), in var/uploads/freigaben. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $approvalImage = null;

    /** Free-form per-source importer configuration (selectors, mappings, ...). */
    #[ORM\Column(type: Types::JSON)]
    private array $config = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $lastStatus = null;

    public function __construct(string $key, string $name, SourceType $type)
    {
        $this->key = $key;
        $this->name = $name;
        $this->type = $type;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): SourceType
    {
        return $this->type;
    }

    public function setType(SourceType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

        return $this;
    }

    /** Importer key to use, falling back to the source type's value. */
    public function getImporter(): string
    {
        return $this->importer ?? $this->type->value;
    }

    public function setImporter(?string $importer): static
    {
        $this->importer = $importer;

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

    public function isFactsOnly(): bool
    {
        return $this->factsOnly;
    }

    public function setFactsOnly(bool $factsOnly): static
    {
        $this->factsOnly = $factsOnly;

        return $this;
    }

    public function isApproved(): bool
    {
        return $this->approvedAt !== null;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeImmutable $approvedAt): static
    {
        $this->approvedAt = $approvedAt;

        return $this;
    }

    public function getApprovalNote(): ?string
    {
        return $this->approvalNote;
    }

    public function setApprovalNote(?string $note): static
    {
        $this->approvalNote = $note;

        return $this;
    }

    public function getApprovalImage(): ?string
    {
        return $this->approvalImage;
    }

    public function setApprovalImage(?string $filename): static
    {
        $this->approvalImage = $filename;

        return $this;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setConfig(array $config): static
    {
        $this->config = $config;

        return $this;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function setLastRunAt(?\DateTimeImmutable $lastRunAt): static
    {
        $this->lastRunAt = $lastRunAt;

        return $this;
    }

    public function getLastStatus(): ?string
    {
        return $this->lastStatus;
    }

    public function setLastStatus(?string $lastStatus): static
    {
        $this->lastStatus = $lastStatus !== null ? substr($lastStatus, 0, 1024) : null;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
