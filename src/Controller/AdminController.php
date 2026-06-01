<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\AiDeduper;
use App\Ai\DuplicateMerger;
use App\Entity\AiDedupDecision;
use App\Entity\User;
use App\Importer\ImporterRegistry;
use App\Repository\EventRepository;
use App\Repository\ImportRunRepository;
use App\Repository\SourceRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;
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

        $recent = $runs->findRecent(40);

        return $this->render('admin/imports.html.twig', [
            'sources' => $allSources,
            'latest' => $latest,
            'recent' => $recent,
            'lastRun' => $recent[0] ?? null,
        ]);
    }

    /** Details of a single import run. */
    #[Route('/imports/{id}', name: 'admin_import_run', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function importRun(int $id, ImportRunRepository $runs): Response
    {
        $run = $runs->find($id);
        if ($run === null) {
            throw $this->createNotFoundException('Lauf nicht gefunden.');
        }

        return $this->render('admin/import_run.html.twig', [
            'run' => $run,
            'history' => $runs->findForSource($run->getSource(), 15),
        ]);
    }

    /** Everything about one source: origin, adapter, dedup stats, its events, run history. */
    #[Route('/sources/{id}', name: 'admin_source', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function source(
        int $id,
        SourceRepository $sources,
        EventRepository $events,
        ImportRunRepository $runs,
        ImporterRegistry $importers,
    ): Response {
        $source = $sources->find($id);
        if ($source === null) {
            throw $this->createNotFoundException('Quelle nicht gefunden.');
        }

        $importerAvailable = $importers->has($source);
        $importerClass = $importerAvailable ? $importers->get($source)::class : null;

        return $this->render('admin/source.html.twig', [
            'source' => $source,
            'counts' => $events->statusCountsForSource($source),
            'wonDuplicates' => $events->countWonDuplicates($source),
            'events' => $events->findBySource($source),
            'runs' => $runs->findForSource($source, 15),
            'importerClass' => $importerClass,
            'importerAvailable' => $importerAvailable,
        ]);
    }

    /** Cookieless visit statistics: per-day views/visitors + top pages. */
    #[Route('/statistik', name: 'admin_stats', methods: ['GET'])]
    public function stats(Connection $db): Response
    {
        $since = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin')))->modify('-29 days')->format('Y-m-d');

        $days = $db->fetchAllAssociative(
            'SELECT day, views, visitors FROM daily_stat ORDER BY day DESC LIMIT 30',
        );
        $totals = $db->fetchAssociative(
            'SELECT COALESCE(SUM(views), 0) AS views, COALESCE(SUM(visitors), 0) AS visitors FROM daily_stat WHERE day >= :since',
            ['since' => $since],
        ) ?: ['views' => 0, 'visitors' => 0];
        $pages = $db->fetchAllAssociative(
            'SELECT route_key, SUM(views) AS views FROM page_stat WHERE day >= :since GROUP BY route_key ORDER BY views DESC LIMIT 15',
            ['since' => $since],
        );

        $maxDayViews = 0;
        foreach ($days as $d) {
            $maxDayViews = max($maxDayViews, (int) $d['views']);
        }

        return $this->render('admin/stats.html.twig', [
            'days' => $days,
            'totals' => $totals,
            'pages' => $pages,
            'maxDayViews' => $maxDayViews,
        ]);
    }

    /** Review the AI deduplication merges and undo them if needed. */
    #[Route('/dedup', name: 'admin_dedup', methods: ['GET'])]
    public function dedup(EntityManagerInterface $em, AiDeduper $deduper): Response
    {
        $decisions = $em->getRepository(AiDedupDecision::class)->findBy(
            ['active' => true],
            ['createdAt' => 'DESC'],
            100,
        );

        return $this->render('admin/dedup.html.twig', [
            'decisions' => $decisions,
            'configured' => $deduper->isConfigured(),
            'model' => $deduper->getModel(),
        ]);
    }

    /**
     * Trigger the AI dedup pass by hand. A live run can do a dozen sequential
     * LLM calls (~a minute), so we launch it detached in the background and let
     * the results trickle into the list above — keeps the request snappy and
     * dodges max_execution_time.
     */
    #[Route('/dedup/run', name: 'admin_dedup_run', methods: ['POST'])]
    public function dedupRun(
        Request $request,
        AiDeduper $deduper,
        #[Autowire('%kernel.project_dir%')] string $projectDir,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('dedup_run', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Formular.');

            return $this->redirectToRoute('admin_dedup');
        }
        if (!$deduper->isConfigured()) {
            $this->addFlash('error', 'AI-Dedup ist nicht konfiguriert (OPENROUTER_API_KEY fehlt).');

            return $this->redirectToRoute('admin_dedup');
        }

        $log = $projectDir.'/var/log/dedup-manual.log';
        // Trailing "&" lets the shell return immediately; nohup detaches the run
        // from the web request so it survives the response.
        $cmd = sprintf(
            'nohup php %s/bin/console dalketicker:dedup-ai --days=60 --max-ai-calls=80 >> %s 2>&1 &',
            escapeshellarg($projectDir),
            escapeshellarg($log),
        );
        $process = Process::fromShellCommandline($cmd, $projectDir);
        $process->setTimeout(null);
        $process->disableOutput();
        $process->run();

        $this->addFlash('success', 'AI-Dedup gestartet – läuft im Hintergrund. Liste in ~1 Minute aktualisieren.');

        return $this->redirectToRoute('admin_dedup');
    }

    /** Undo a single AI merge: restore the duplicate to Published. */
    #[Route('/dedup/{id}/undo', name: 'admin_dedup_undo', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function dedupUndo(int $id, Request $request, EntityManagerInterface $em, DuplicateMerger $merger): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('dedup_undo', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Formular.');

            return $this->redirectToRoute('admin_dedup');
        }
        $decision = $em->getRepository(AiDedupDecision::class)->find($id);
        if ($decision !== null && $decision->isActive()) {
            $merger->undo($decision);
            $em->flush();
            $this->addFlash('success', 'Merge rückgängig gemacht – Event wieder sichtbar.');
        }

        return $this->redirectToRoute('admin_dedup');
    }

    /** Change the logged-in admin's e-mail and/or password. */
    #[Route('/konto', name: 'admin_account', methods: ['GET', 'POST'])]
    public function account(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        UserRepository $users,
        Security $security,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $error = null;

        if ($request->isMethod('POST')) {
            $current = (string) $request->request->get('current_password');
            $email = trim((string) $request->request->get('email'));
            $new = (string) $request->request->get('new_password');
            $confirm = (string) $request->request->get('new_password_confirm');

            if (!$this->isCsrfTokenValid('account', (string) $request->request->get('_token'))) {
                $error = 'Ungültiges Formular, bitte erneut versuchen.';
            } elseif (!$hasher->isPasswordValid($user, $current)) {
                $error = 'Das aktuelle Passwort stimmt nicht.';
            } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $error = 'Bitte eine gültige E-Mail-Adresse angeben.';
            } elseif ($email !== $user->getEmail() && $users->findByEmail($email) !== null) {
                $error = 'Diese E-Mail-Adresse ist bereits vergeben.';
            } elseif ($new !== '' && mb_strlen($new) < 8) {
                $error = 'Das neue Passwort muss mindestens 8 Zeichen haben.';
            } elseif ($new !== '' && $new !== $confirm) {
                $error = 'Die neuen Passwörter stimmen nicht überein.';
            } else {
                $user->setEmail($email);
                if ($new !== '') {
                    $user->setPassword($hasher->hashPassword($user, $new));
                }
                $em->flush();
                // Re-establish the session so changing the identifier/password
                // doesn't log the user out.
                $security->login($user);
                $this->addFlash('success', 'Konto aktualisiert.');

                return $this->redirectToRoute('admin_account');
            }
        }

        return $this->render('admin/account.html.twig', [
            'email' => $user->getEmail(),
            'error' => $error,
        ]);
    }
}
