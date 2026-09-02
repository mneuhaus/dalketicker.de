<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\SourceRepository;
use App\Service\ContactFormToken;
use App\Service\RegionContext;
use App\Twig\RegionExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Static informational pages: legal notice and the aggregation/opt-out info.
 */
final class PageController extends AbstractController
{
    public function __construct(
        private readonly RegionContext $regions,
        private readonly RegionExtension $regionExtension,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/impressum', name: 'page_impressum', methods: ['GET'])]
    public function impressum(ContactFormToken $contactToken): Response
    {
        // Signed timestamp + token for the contact form instead of a session
        // CSRF token, so this public page sets no cookie (see ContactFormToken).
        return $this->render('page/impressum.html.twig', ['contact_form' => $contactToken->issueNow()]);
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
    #[Route('/site.webmanifest', name: 'site_manifest_compat', methods: ['GET'])]
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
                ['src' => $this->regionExtension->iconPath('icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => $this->regionExtension->iconPath('icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ], Response::HTTP_OK, ['Content-Type' => 'application/manifest+json']);
    }

    #[Route('/favicon.ico', name: 'favicon_ico', methods: ['GET', 'HEAD'])]
    public function favicon(): BinaryFileResponse
    {
        return $this->iconResponse('icon-192.png');
    }

    #[Route('/apple-touch-icon.png', name: 'apple_touch_icon', methods: ['GET', 'HEAD'])]
    #[Route('/apple-touch-icon-precomposed.png', name: 'apple_touch_icon_precomposed', methods: ['GET', 'HEAD'])]
    public function appleTouchIcon(): BinaryFileResponse
    {
        return $this->iconResponse('apple-touch-icon.png');
    }

    #[Route('/robots.txt', name: 'robots_txt', methods: ['GET'])]
    public function robots(): Response
    {
        // Advertise the sitemap on the canonical host, not on alias hosts.
        $base = $this->regions->current()->getBaseUrl();
        $body = <<<TXT
            User-agent: *
            Allow: /
            Disallow: /admin
            Disallow: /login
            Disallow: /logout

            Sitemap: {$base}/sitemap.xml

            TXT;

        return new Response($body, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    private function iconResponse(string $filename): BinaryFileResponse
    {
        $response = new BinaryFileResponse($this->projectDir.'/public'.$this->regionExtension->iconPath($filename));
        $response->headers->set('Content-Type', 'image/png');
        $response->headers->set('X-Robots-Tag', 'noindex');
        // A day, and explicitly NOT immutable: these paths are fixed, not
        // content-hashed, and {@see RegionExtension::iconPath} silently
        // switches to a regional icon set the moment one is dropped into
        // public/icons/<region>/. With a year of immutable caching that switch
        // would take a year to reach everyone who had already visited.
        $response->setPublic();
        $response->setMaxAge(86400);
        $response->setSharedMaxAge(86400);

        return $response;
    }
}
