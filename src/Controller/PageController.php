<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\SourceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Static informational pages: legal notice and the aggregation/opt-out info.
 */
final class PageController extends AbstractController
{
    #[Route('/impressum', name: 'page_impressum', methods: ['GET'])]
    public function impressum(): Response
    {
        return $this->render('page/impressum.html.twig');
    }

    #[Route('/quellen', name: 'page_quellen', methods: ['GET'])]
    public function quellen(SourceRepository $sources): Response
    {
        // List the real aggregated sources (skip internal/demo + empty URLs).
        $list = array_filter(
            $sources->findBy([], ['name' => 'ASC']),
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
}
