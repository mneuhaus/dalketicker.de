<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContactMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A message submitted through the public contact form. Everything that clears
 * the cheap bot filters is stored; an AI classifier flags likely spam so the
 * admin inbox can separate real enquiries from junk without dropping anything.
 */
#[ORM\Entity(repositoryClass: ContactMessageRepository::class)]
#[ORM\Table(name: 'contact_message')]
#[ORM\Index(name: 'idx_contact_spam_created', columns: ['spam', 'created_at'])]
class ContactMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** AI verdict: likely spam/scam (still stored, just filed under "Spam"). */
    #[ORM\Column]
    private bool $spam = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $spamReason = null;

    #[ORM\Column]
    private bool $seen = false;

    public function __construct(string $name, string $email, string $message, \DateTimeImmutable $createdAt)
    {
        $this->name = $name;
        $this->email = $email;
        $this->message = $message;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isSpam(): bool
    {
        return $this->spam;
    }

    public function setSpam(bool $spam, ?string $reason = null): static
    {
        $this->spam = $spam;
        $this->spamReason = $reason;

        return $this;
    }

    public function getSpamReason(): ?string
    {
        return $this->spamReason;
    }

    public function isSeen(): bool
    {
        return $this->seen;
    }

    public function setSeen(bool $seen): static
    {
        $this->seen = $seen;

        return $this;
    }
}
