<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Region;
use App\Repository\RegionRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves the ticker/region for the current request from the host name. In CLI
 * contexts it falls back to Gütersloh unless a command passes a region itself.
 */
final class RegionContext
{
    private ?Region $current = null;

    public function __construct(
        private readonly RequestStack $requests,
        private readonly RegionRepository $regions,
    ) {
    }

    public function current(): Region
    {
        if ($this->current !== null) {
            return $this->current;
        }

        $request = $this->requests->getCurrentRequest();
        if ($request !== null) {
            $region = $this->regions->findByHost($request->getHost());
            if ($region !== null) {
                return $this->current = $region;
            }
        }

        return $this->current = $this->regions->findDefault();
    }

    /** @return Region[] */
    public function all(): array
    {
        return $this->regions->findEnabled();
    }
}
