<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\RegionContext;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

final class RegionExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly RegionContext $regions,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
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

    public function getFunctions(): array
    {
        return [
            new TwigFunction('region_share_image', $this->shareImage(...)),
        ];
    }

    /**
     * Absolute URL of the link-preview image, built on the canonical host.
     * Uses public/share/{regionKey}/status.png when it exists, otherwise the
     * shared default image.
     */
    public function shareImage(): string
    {
        $region = $this->regions->current();
        $path = '/share/'.$region->getKey().'/status.png';
        if (!is_file($this->projectDir.'/public'.$path)) {
            $path = '/share/status.png';
        }

        return $region->getBaseUrl().$path;
    }
}
