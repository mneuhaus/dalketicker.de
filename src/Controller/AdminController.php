<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\AiCategorizer;
use App\Ai\AiDeduper;
use App\Ai\CategoryApplier;
use App\Ai\DuplicateMerger;
use App\Entity\AiCategoryDecision;
use App\Entity\AiDedupDecision;
use App\Entity\ContactMessage;
use App\Repository\ContactMessageRepository;
use App\Entity\User;
use App\Importer\ImporterRegistry;
use App\Repository\CategoryRepository;
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
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin'));
        $since = $today->modify('-29 days')->format('Y-m-d');

        $rows = $db->fetchAllAssociative(
            'SELECT day, views, visitors FROM daily_stat WHERE day >= :since ORDER BY day ASC',
            ['since' => $since],
        );
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[(string) $r['day']] = $r;
        }

        // Continuous 30-day window (oldest → newest), gaps filled with zeros so
        // the chart has one point per calendar day.
        $series = [];
        for ($i = 29; $i >= 0; --$i) {
            $key = $today->modify('-'.$i.' days')->format('Y-m-d');
            $series[] = [
                'day' => $key,
                'views' => (int) ($byDay[$key]['views'] ?? 0),
                'visitors' => (int) ($byDay[$key]['visitors'] ?? 0),
            ];
        }

        $totals = $db->fetchAssociative(
            'SELECT COALESCE(SUM(views), 0) AS views, COALESCE(SUM(visitors), 0) AS visitors FROM daily_stat WHERE day >= :since',
            ['since' => $since],
        ) ?: ['views' => 0, 'visitors' => 0];
        $pages = $db->fetchAllAssociative(
            'SELECT route_key, SUM(views) AS views FROM page_stat WHERE day >= :since GROUP BY route_key ORDER BY views DESC LIMIT 15',
            ['since' => $since],
        );

        // Independent scales so the (smaller) visitor line is readable against
        // its own right-hand axis rather than hugging the baseline.
        $maxViews = max(1, max(array_column($series, 'views')));
        $maxVisitors = max(1, max(array_column($series, 'visitors')));

        return $this->render('admin/stats.html.twig', [
            'series' => $series,
            'maxViews' => $maxViews,
            'maxVisitors' => $maxVisitors,
            'todayViews' => end($series)['views'] ?? 0,
            'totals' => $totals,
            'pages' => $pages,
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

        // In prod, Symfony logs to stderr, so var/log isn't created on its own —
        // make sure it exists, else the redirect below fails and the job dies.
        @mkdir($projectDir.'/var/log', 0775, true);
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

    /** Review AI categorization: pending suggestions (accept/dismiss) + applied (undo). */
    #[Route('/kategorien', name: 'admin_categorize', methods: ['GET'])]
    public function categorize(EntityManagerInterface $em, AiCategorizer $categorizer, EventRepository $events): Response
    {
        $repo = $em->getRepository(AiCategoryDecision::class);

        $counts = [];
        foreach (['applied', 'pending', 'agreed', 'dismissed', 'undone'] as $status) {
            $counts[$status] = $repo->count(['status' => $status]);
        }
        $processed = array_sum($counts);
        $remaining = $events->countWithoutCategoryDecision();

        // A run is "active" if decisions were written in the last 2 minutes — then
        // we let the page auto-refresh so progress updates live.
        $lastAt = $em->createQuery('SELECT MAX(d.createdAt) FROM '.AiCategoryDecision::class.' d')->getSingleScalarResult();
        $active = $lastAt !== null && new \DateTimeImmutable((string) $lastAt) > new \DateTimeImmutable('-2 minutes');

        return $this->render('admin/categorize.html.twig', [
            'pending' => $repo->findBy(['status' => 'pending'], ['createdAt' => 'DESC'], 200),
            'applied' => $repo->findBy(['status' => 'applied'], ['createdAt' => 'DESC'], 60),
            'configured' => $categorizer->isConfigured(),
            'model' => $categorizer->getModel(),
            'counts' => $counts,
            'processed' => $processed,
            'remaining' => $remaining,
            'active' => $active,
            'summaryDone' => $events->countWithSummary(),
            'summaryEligible' => $events->countSummaryEligible(),
        ]);
    }

    /** Trigger the AI categorization pass by hand (detached, like the dedup run). */
    #[Route('/kategorien/run', name: 'admin_categorize_run', methods: ['POST'])]
    public function categorizeRun(
        Request $request,
        AiCategorizer $categorizer,
        #[Autowire('%kernel.project_dir%')] string $projectDir,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('categorize', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Formular.');

            return $this->redirectToRoute('admin_categorize');
        }
        if (!$categorizer->isConfigured()) {
            $this->addFlash('error', 'AI ist nicht konfiguriert (OPENROUTER_API_KEY fehlt).');

            return $this->redirectToRoute('admin_categorize');
        }

        @mkdir($projectDir.'/var/log', 0775, true);
        $log = $projectDir.'/var/log/categorize-manual.log';
        $cmd = sprintf(
            'nohup php %s/bin/console dalketicker:categorize-ai --apply --max-calls=120 >> %s 2>&1 &',
            escapeshellarg($projectDir),
            escapeshellarg($log),
        );
        $process = Process::fromShellCommandline($cmd, $projectDir);
        $process->setTimeout(null);
        $process->disableOutput();
        $process->run();

        $this->addFlash('success', 'KI-Kategorisierung gestartet – läuft im Hintergrund. Liste in ~1–2 Minuten aktualisieren.');

        return $this->redirectToRoute('admin_categorize');
    }

    #[Route('/kategorien/{id}/{action}', name: 'admin_categorize_decide', requirements: ['id' => '\d+', 'action' => 'accept|dismiss|undo'], methods: ['POST'])]
    public function categorizeDecide(int $id, string $action, Request $request, EntityManagerInterface $em, CategoryApplier $applier): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('categorize', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Formular.');

            return $this->redirectToRoute('admin_categorize');
        }
        $decision = $em->getRepository(AiCategoryDecision::class)->find($id);
        if ($decision !== null) {
            match ($action) {
                'accept' => $applier->acceptSuggestion($decision),
                'dismiss' => $applier->dismissSuggestion($decision),
                'undo' => $applier->undo($decision),
            };
            $em->flush();
            $this->addFlash('success', match ($action) {
                'accept' => 'Vorschlag übernommen.',
                'dismiss' => 'Vorschlag verworfen.',
                'undo' => 'Kategorie zurückgesetzt.',
            });
        }

        return $this->redirectToRoute('admin_categorize');
    }

    /** Edit & pin individual event fields so a correction survives re-imports. */
    #[Route('/events/{id}/edit', name: 'admin_event_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function eventEdit(int $id, EventRepository $events, CategoryRepository $categories): Response
    {
        $event = $events->find($id);
        if ($event === null) {
            throw $this->createNotFoundException('Event nicht gefunden.');
        }

        return $this->render('admin/event_edit.html.twig', [
            'event' => $event,
            'allCategories' => $categories->findAllOrdered(),
        ]);
    }

    #[Route('/events/{id}', name: 'admin_event_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function eventUpdate(int $id, Request $request, EventRepository $events, CategoryRepository $categories, EntityManagerInterface $em): RedirectResponse
    {
        $event = $events->find($id);
        if ($event === null) {
            throw $this->createNotFoundException('Event nicht gefunden.');
        }
        if (!$this->isCsrfTokenValid('event_edit', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Formular.');

            return $this->redirectToRoute('admin_event_edit', ['id' => $id]);
        }

        $event->setTitle(mb_substr(trim((string) $request->request->get('title', '')), 0, 300) ?: $event->getTitle());
        $event->setOrganizer($this->blankToNull(mb_substr(trim((string) $request->request->get('organizer', '')), 0, 200)));
        $event->setDescription($this->blankToNull(trim((string) $request->request->get('description', ''))));
        $event->setImageUrl($this->blankToNull(mb_substr(trim((string) $request->request->get('imageUrl', '')), 0, 1024)));

        // Date/time (datetime-local inputs). Only applied when the start parses.
        $tz = new \DateTimeZone('Europe/Berlin');
        $start = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) $request->request->get('startsAt', ''), $tz);
        if ($start instanceof \DateTimeImmutable) {
            $event->setStartsAt($start);
            $endRaw = trim((string) $request->request->get('endsAt', ''));
            $end = $endRaw !== '' ? \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $endRaw, $tz) : null;
            $event->setEndsAt($end instanceof \DateTimeImmutable ? $end : null);
            $event->setAllDay($request->request->getBoolean('allDay'));
        }

        $cats = [];
        foreach ((array) $request->request->all('categories') as $slug) {
            $cat = is_string($slug) ? $categories->findBySlug($slug) : null;
            if ($cat !== null) {
                $cats[] = $cat;
            }
        }
        $event->setCategories($cats);

        // A field is "pinned" (kept across re-imports) when its checkbox is set.
        foreach (['title', 'organizer', 'description', 'imageUrl', 'categories', 'datum'] as $field) {
            $event->setFieldLocked($field, $request->request->getBoolean('lock_'.$field));
        }
        $em->flush();

        $this->addFlash('success', 'Event gespeichert. Gepinnte Felder bleiben beim nächsten Import erhalten.');

        return $this->redirectToRoute('admin_event_edit', ['id' => $id]);
    }

    private function blankToNull(string $v): ?string
    {
        return $v === '' ? null : $v;
    }

    /** Contact-form inbox: real enquiries and AI-flagged spam, separately. */
    #[Route('/kontakt', name: 'admin_contact', methods: ['GET'])]
    public function contact(Request $request, ContactMessageRepository $repo, EntityManagerInterface $em): Response
    {
        $view = $request->query->get('filter') === 'spam' ? 'spam' : 'real';
        $messages = $repo->findBySpam($view === 'spam');

        // Mark the real inbox as seen on view (clears the unread badge).
        if ($view === 'real') {
            $changed = false;
            foreach ($messages as $m) {
                if (!$m->isSeen()) {
                    $m->setSeen(true);
                    $changed = true;
                }
            }
            if ($changed) {
                $em->flush();
            }
        }

        return $this->render('admin/contact.html.twig', [
            'messages' => $messages,
            'view' => $view,
            'counts' => $repo->counts(),
        ]);
    }

    /** Flip the spam flag on a message the AI mis-judged. */
    #[Route('/kontakt/{id}/toggle', name: 'admin_contact_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function contactToggle(int $id, Request $request, EntityManagerInterface $em): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('contact_admin', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_contact');
        }
        $msg = $em->getRepository(ContactMessage::class)->find($id);
        if ($msg !== null) {
            $msg->setSpam(!$msg->isSpam(), $msg->isSpam() ? 'manuell als kein Spam markiert' : 'manuell als Spam markiert');
            $em->flush();
        }

        return $this->redirectToRoute('admin_contact', ['filter' => $request->request->get('from')]);
    }

    /** Delete a contact message. */
    #[Route('/kontakt/{id}/delete', name: 'admin_contact_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function contactDelete(int $id, Request $request, EntityManagerInterface $em): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('contact_admin', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_contact');
        }
        $msg = $em->getRepository(ContactMessage::class)->find($id);
        if ($msg !== null) {
            $em->remove($msg);
            $em->flush();
        }

        return $this->redirectToRoute('admin_contact', ['filter' => $request->request->get('from')]);
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
