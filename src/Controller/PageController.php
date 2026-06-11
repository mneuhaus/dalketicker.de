<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\SourceRepository;
use App\Service\RegionContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Static informational pages: legal notice and the aggregation/opt-out info.
 */
final class PageController extends AbstractController
{
    public function __construct(private readonly RegionContext $regions)
    {
    }

    #[Route('/impressum', name: 'page_impressum', methods: ['GET'])]
    public function impressum(): Response
    {
        return $this->render('page/impressum.html.twig');
    }

    #[Route('/quellen', name: 'page_quellen', methods: ['GET'])]
    public function quellen(SourceRepository $sources): Response
    {
        $region = $this->regions->current();
        // List the real aggregated sources (skip internal/demo + empty URLs).
        $list = array_filter(
            $sources->findForRegion($region),
            static fn ($s) => $s->getKey() !== 'demo',
        );

        return $this->render('page/quellen.html.twig', ['sources' => $list]);
    }

    #[Route('/datenschutz', name: 'page_datenschutz', methods: ['GET'])]
    public function datenschutz(): Response
    {
        return $this->render('page/datenschutz.html.twig');
    }

    /** How to add dalketicker to the home screen (installable web app). */
    #[Route('/app', name: 'page_app', methods: ['GET'])]
    public function app(): Response
    {
        return $this->render('page/app.html.twig');
    }

    #[Route('/manifest.webmanifest', name: 'site_manifest', methods: ['GET'])]
    public function manifest(): JsonResponse
    {
        $region = $this->regions->current();

        return new JsonResponse([
            'name' => $region->getSiteName().' – '.$region->getTagline(),
            'short_name' => $region->getSiteName(),
            'description' => 'Alle Veranstaltungen '.$region->getAreaInPhrase().' an einem Ort.',
            'lang' => 'de',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#faf9f6',
            'theme_color' => $region->getThemeColor(),
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ]);
    }

    #[Route('/robots.txt', name: 'robots_txt', methods: ['GET'])]
    public function robots(Request $request): Response
    {
        $host = $request->getSchemeAndHttpHost();
        $body = <<<TXT
            User-agent: *
            Allow: /
            Disallow: /admin
            Disallow: /login
            Disallow: /logout

            Sitemap: {$host}/sitemap.xml

            TXT;

        return new Response($body, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
