<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness/readiness probe for the container healthcheck and Traefik's
 * load-balancer health check. Deliberately trivial: no DB, no template — a hit
 * here means the PHP front controller is up and serving, which is exactly the
 * signal blue-green deploys need before routing traffic to a new container.
 * Returns text/plain so the visit counter (HTML-only) ignores it.
 */
final class HealthController extends AbstractController
{
    #[Route('/healthz', name: 'health', methods: ['GET'])]
    public function health(): Response
    {
        return new Response('ok', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }
}
