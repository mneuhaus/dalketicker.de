<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ImportRunRepository;
use App\Repository\SourceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
final class AdminController extends AbstractController
{
    #[Route('', name: 'admin_home')]
    public function home(): RedirectResponse
    {
        return $this->redirectToRoute('admin_imports');
    }

    /** Import monitoring: latest run per source + recent run history. */
    #[Route('/imports', name: 'admin_imports', methods: ['GET'])]
    public function imports(SourceRepository $sources, ImportRunRepository $runs): Response
    {
        $latest = $runs->findLatestPerSource();
        $allSources = $sources->findBy([], ['name' => 'ASC']);

        // Sort sources: enabled first, then by latest run time desc.
        usort($allSources, static function ($a, $b) use ($latest) {
            if ($a->isEnabled() !== $b->isEnabled()) {
                return $a->isEnabled() ? -1 : 1;
            }
            $ra = $latest[$a->getId()] ?? null;
            $rb = $latest[$b->getId()] ?? null;
            $ta = $ra?->getStartedAt()?->getTimestamp() ?? 0;
            $tb = $rb?->getStartedAt()?->getTimestamp() ?? 0;

            return $tb <=> $ta;
        });

        return $this->render('admin/imports.html.twig', [
            'sources' => $allSources,
            'latest' => $latest,
            'recent' => $runs->findRecent(40),
        ]);
    }
}
