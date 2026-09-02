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
            new TwigFunction('region_icon', $this->iconPath(...)),
        ];
    }

    /**
     * Absolute URL of the link-preview image, built on the canonical host.
     * Uses public/share/{regionKey}/status.png when it exists, otherwise the
     * shared default image.
     */
    public function shareImage(): string
    {
        return $this->regions->current()->getBaseUrl().$this->regionalOrDefault('/share', 'status.png');
    }

    /**
     * Web path of an app icon: /icons/{regionKey}/{file} when a region-specific
     * set exists, otherwise the shared default set. Single source for the
     * manifest, the favicon routes and the templates, so they can't drift once
     * a regional set is dropped into public/icons/<region>/.
     */
    public function iconPath(string $filename): string
    {
        return $this->regionalOrDefault('/icons', $filename);
    }

    /** public{$dir}/{regionKey}/{$filename} when that file exists, else public{$dir}/{$filename}. */
    private function regionalOrDefault(string $dir, string $filename): string
    {
        $regional = $dir.'/'.$this->regions->current()->getKey().'/'.$filename;

        return is_file($this->projectDir.'/public'.$regional) ? $regional : $dir.'/'.$filename;
    }
}
