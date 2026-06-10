<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ImportRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One execution of an import for a single {@see Source}. Written by the
 * {@see \App\Service\EventImporter} so operators can monitor when jobs ran,
 * what they pulled in, and what broke.
 */
#[ORM\Entity(repositoryClass: ImportRunRepository::class)]
#[ORM\Table(name: 'import_run')]
#[ORM\Index(name: 'idx_import_run_started', columns: ['started_at'])]
#[ORM\Index(name: 'idx_import_run_source', columns: ['source_id'])]
class ImportRun
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_OK = 'ok';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Source::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Source $source;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column]
    private int $durationMs = 0;

    #[ORM\Column(length: 10)]
    private string $status = self::STATUS_OK;

    #[ORM\Column]
    private int $seen = 0;

    #[ORM\Column]
    private int $created = 0;

    #[ORM\Column]
    private int $updated = 0;

    #[ORM\Column]
    private int $unchanged = 0;

    #[ORM\Column]
    private int $duplicates = 0;

    #[ORM\Column]
    private int $errors = 0;

    /** Whether this was a dry run (no data written). */
    #[ORM\Column]
    private bool $dryRun = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    public function __construct(Source $source, \DateTimeImmutable $startedAt, bool $dryRun = false)
    {
        $this->source = $source;
        $this->startedAt = $startedAt;
        $this->dryRun = $dryRun;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): Source
    {
        return $this->source;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getSeen(): int
    {
        return $this->seen;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function getUnchanged(): int
    {
        return $this->unchanged;
    }

    public function getDuplicates(): int
    {
        return $this->duplicates;
    }

    public function getErrors(): int
    {
        return $this->errors;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;

        return $this;
    }

    /** Fill counters + status from a finished {@see \App\Service\ImportReport}. */
    public function complete(\DateTimeImmutable $finishedAt, int $seen, int $created, int $updated, int $unchanged, int $duplicates, int $errors, ?string $fatal): static
    {
        $this->finishedAt = $finishedAt;
        $this->durationMs = (int) (($finishedAt->format('U.u') - $this->startedAt->format('U.u')) * 1000);
        $this->seen = $seen;
        $this->created = $created;
        $this->updated = $updated;
        $this->unchanged = $unchanged;
        $this->duplicates = $duplicates;
        $this->errors = $errors;

        if ($fatal !== null) {
            $this->status = self::STATUS_FAILED;
            $this->message = substr($fatal, 0, 5000);
        } elseif ($errors > 0) {
            $this->status = self::STATUS_PARTIAL;
        } else {
            $this->status = self::STATUS_OK;
        }

        return $this;
    }
}
