<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\ContactMessageRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Small helpers for the admin templates. {@see unread_contacts()} powers the
 * unread badge on the admin nav so new contact messages are visible from any
 * backend page.
 */
final class AdminExtension extends AbstractExtension
{
    public function __construct(
        private readonly ContactMessageRepository $contacts,
        private readonly RequestStack $requests,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_contacts', $this->unreadContacts(...)),
            new TwigFunction('admin_region_params', $this->adminRegionParams(...)),
        ];
    }

    public function unreadContacts(): int
    {
        return $this->contacts->counts()['unseen'];
    }

    /**
     * Keep the active admin region filter when moving between filtered admin
     * views. With no query parameter the current host region remains the default.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function adminRegionParams(array $extra = []): array
    {
        $request = $this->requests->getCurrentRequest();
        $region = $request?->query->get('region');
        if (\is_string($region) && $region !== '') {
            $extra['region'] = $region;
        }

        return $extra;
    }
}
