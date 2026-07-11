<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\EventRepository;
use App\Service\RegionContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * XML sitemap for search engines: the main views, the static pages and every
 * upcoming published event detail page.
 */
final class SitemapController extends AbstractController
{
    public function __construct(private readonly RegionContext $regions)
    {
    }

    #[Route('/sitemap.xml', name: 'sitemap_xml', methods: ['GET'])]
    public function sitemap(EventRepository $events): Response
    {
        $region = $this->regions->current();
        $xml = $this->renderView('sitemap.xml.twig', [
            'events' => $events->findForSitemap(region: $region),
        ]);

        $response = new Response($xml, Response::HTTP_OK, ['Content-Type' => 'application/xml; charset=utf-8']);
        // Regenerating up to 20000 URLs per crawler hit is wasteful — the
        // sitemap may be an hour stale without any harm.
        $response->setPublic();
        $response->setMaxAge(3600);
        $response->setSharedMaxAge(3600);

        return $response;
    }
}
