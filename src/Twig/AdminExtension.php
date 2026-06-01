<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\ContactMessageRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Small helpers for the admin templates. {@see unread_contacts()} powers the
 * unread badge on the admin nav so new contact messages are visible from any
 * backend page.
 */
final class AdminExtension extends AbstractExtension
{
    public function __construct(private readonly ContactMessageRepository $contacts)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_contacts', $this->unreadContacts(...)),
        ];
    }

    public function unreadContacts(): int
    {
        return $this->contacts->counts()['unseen'];
    }
}
