<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-day bookkeeping for the AI dedup pass: a fingerprint of the day's visible
 * event set. A day is only (re)sent to the AI when its fingerprint changed —
 * keeps the twice-daily cron cheap.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_dedup_day')]
class AiDedupDay
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $regionKey;

    #[ORM\Id]
    #[ORM\Column(length: 10)]
    private string $day; // 'Y-m-d'

    #[ORM\Column(length: 40)]
    private string $fingerprint;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $reviewedAt;

    public function __construct(string $regionKey, string $day, string $fingerprint, \DateTimeImmutable $reviewedAt)
    {
        $this->regionKey = $regionKey;
        $this->day = $day;
        $this->fingerprint = $fingerprint;
        $this->reviewedAt = $reviewedAt;
    }

    public function getRegionKey(): string
    {
        return $this->regionKey;
    }

    public function getDay(): string
    {
        return $this->day;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function setFingerprint(string $fingerprint): static
    {
        $this->fingerprint = $fingerprint;

        return $this;
    }

    public function setReviewedAt(\DateTimeImmutable $reviewedAt): static
    {
        $this->reviewedAt = $reviewedAt;

        return $this;
    }
}
