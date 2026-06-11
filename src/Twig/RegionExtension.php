<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\RegionContext;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

final class RegionExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(private readonly RegionContext $regions)
    {
    }

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        $current = $this->regions->current();

        return [
            'site_name' => $current->getSiteName(),
            'site_tagline' => $current->getTagline(),
            'current_region' => $current,
            'all_regions' => $this->regions->all(),
        ];
    }
}
