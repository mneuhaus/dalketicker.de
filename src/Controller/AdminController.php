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
use App\Entity\ImportRun;
use App\Entity\Region;
use App\Entity\Source;
use App\Entity\User;
use App\Importer\ImporterRegistry;
use App\Repository\CategoryRepository;
use App\Repository\ContactMessageRepository;
use App\Repository\EventRepository;
use App\Repository\ImportRunRepository;
use App\Repository\RegionRepository;
use App\Repository\SourceRepository;
use App\Repository\UserRepository;
use App\Service\RegionContext;
use App\Service\RunLock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

#[Route('/admin')]
final class AdminController extends AbstractController
{
    /** Raster formats only — an SVG served from our origin would render as a script-capable document. */
    private const APPROVAL_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly RegionContext $regionContext,
        private readonly RegionRepository $regions,
    ) {
    }

    #[Route('', name: 'admin_home')]
    public function home(): RedirectResponse
    {
        return $this->redirectToRoute('admin_imports');
    }

    /** Import monitoring: latest run per source + recent run history. */
    #[Route('/imports', name: 'admin_imports', methods: ['GET'])]
    public function imports(Request $request, SourceRepository $sources, ImportRunRepository $runs): Response
    {
        $region = $this->resolveAdminRegion($request);
        $latest = $runs->findLatestPerSource($region);
        $allSources = $sources->findForAdmin($region);

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

        $recent = $runs->findRecent(40, $region);

        return $this->render('admin/imports.html.twig', $this->withAdminRegion($region, [
            'sources' => $allSources,
            'latest' => $latest,
            'recent' => $recent,
            'lastRun' => $recent[0] ?? null,
            'warnings' => $this->buildSourceWarnings($allSources, $latest, $runs),
        ]));
    }

    /**
     * Health warnings per enabled source for the overview table: last run
     * failed, no successful run for over a week, or the last three runs all
     * came back empty (the source probably changed its markup).
     *
     * @param Source[]              $sources
     * @param array<int, ImportRun> $latest
     *
     * @return array<int, array{failed: string|null, staleDays: int|null, zeroEvents: bool}>
     */
    private function buildSourceWarnings(array $sources, array $latest, ImportRunRepository $runs): array
    {
        $sourceIds = array_values(array_filter(array_map(
            static fn (Source $source): ?int => $source->getId(),
            $sources,
        )));
        if ($sourceIds === []) {
            return [];
        }

        // Two bounded queries instead of a scan over every run ever recorded.
        $lastSuccessAt = $runs->lastSuccessPerSource($sourceIds);
        $recentSeen = $runs->recentSeenCounts($sourceIds);

        $now = new \DateTimeImmutable('now');
        $warnings = [];
        foreach ($sources as $source) {
            $sid = $source->getId();
            $run = $latest[$sid] ?? null;
            if ($sid === null || !$source->isEnabled() || $run === null) {
                continue;
            }

            $failed = $run->getStatus() === ImportRun::STATUS_FAILED ? ($run->getMessage() ?? 'Fehler ohne Meldung') : null;

            $staleDays = null;
            $successAt = $lastSuccessAt[$sid] ?? null;
            if ($successAt !== null) {
                $days = (int) $successAt->diff($now)->days;
                if ($days > 7) {
                    $staleDays = $days;
                }
            }

            $seen = $recentSeen[$sid] ?? [];
            $zeroEvents = \count($seen) >= 3 && array_sum($seen) === 0;

            if ($failed !== null || $staleDays !== null || $zeroEvents) {
                $warnings[$sid] = ['failed' => $failed, 'staleDays' => $staleDays, 'zeroEvents' => $zeroEvents];
            }
        }

        return $warnings;
    }

    /** Details of a single import run. */
    #[Route('/imports/{id}', name: 'admin_import_run', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function importRun(int $id, Request $request, ImportRunRepository $runs): Response
    {
        $run = $runs->find($id);
        if ($run === null) {
            throw $this->createNotFoundException('Lauf nicht gefunden.');
        }

        return $this->render('admin/import_run.html.twig', $this->withAdminRegion($this->resolveAdminRegion($request), [
            'run' => $run,
            'history' => $runs->findForSource($run->getSource(), 15),
        ]));
    }

    /** Everything about one source: origin, adapter, dedup stats, its events, run history. */
    #[Route('/sources/{id}', name: 'admin_source', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function source(
        int $id,
        Request $request,
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

        return $this->render('admin/source.html.twig', $this->withAdminRegion($this->resolveAdminRegion($request), [
            'source' => $source,
            'counts' => $events->statusCountsForSource($source),
            'wonDuplicates' => $events->countWonDuplicates($source),
            'events' => $events->findBySource($source),
            'runs' => $runs->findForSource($source, 15),
            'importerClass' => $importerClass,
            'importerAvailable' => $importerAvailable,
        ]));
    }

    /** Enable/disable a source (won't be imported while disabled). */
    #[Route('/sources/{id}/toggle', name: 'admin_source_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sourceToggle(int $id, Request $request, SourceRepository $sources, EntityManagerInterface $em): RedirectResponse
    {
        $source = $sources->find($id);
        if ($source !== null && $this->isCsrfTokenValid('source_toggle', (string) $request->request->get('_token'))) {
            $source->setEnabled(!$source->isEnabled());
            $em->flush();
            $this->addFlash('success', $source->isEnabled() ? 'Quelle aktiviert.' : 'Quelle deaktiviert.');
        }

        return $this->redirectToRoute('admin_source', ['id' => $id] + $this->adminRegionRedirectParams($request));
    }

    /** Record/clear a publishing permission ("Freigabe") incl. an optional proof image. */
    #[Route('/sources/{id}/freigabe', name: 'admin_source_approval', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sourceApproval(int $id, Request $request, SourceRepository $sources, EntityManagerInterface $em, #[Autowire('%kernel.project_dir%')] string $projectDir): RedirectResponse
    {
        $source = $sources->find($id);
        if ($source === null || !$this->isCsrfTokenValid('source_approval', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_source', ['id' => $id] + $this->adminRegionRedirectParams($request));
        }

        $dir = $projectDir.'/var/uploads/freigaben';

        if ($request->request->get('action') === 'clear') {
            if ($source->getApprovalImage() !== null) {
                @unlink($dir.'/'.$source->getApprovalImage());
            }
            $source->setApprovedAt(null)->setApprovalNote(null)->setApprovalImage(null);
            $em->flush();
            $this->addFlash('success', 'Freigabe entfernt.');

            return $this->redirectToRoute('admin_source', ['id' => $id] + $this->adminRegionRedirectParams($request));
        }

        $approved = $request->request->getBoolean('approved');
        $source->setApprovedAt($approved ? ($source->getApprovedAt() ?? new \DateTimeImmutable('now')) : null);
        $source->setApprovalNote($this->blankToNull(trim((string) $request->request->get('note', ''))));

        $file = $request->files->get('image');
        if ($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile && $file->isValid()) {
            if (!\in_array($file->getMimeType(), self::APPROVAL_IMAGE_TYPES, true)) {
                $this->addFlash('error', 'Nur Bilddateien erlaubt (JPEG, PNG, WebP oder GIF).');

                return $this->redirectToRoute('admin_source', ['id' => $id] + $this->adminRegionRedirectParams($request));
            }
            @mkdir($dir, 0775, true);
            if ($source->getApprovalImage() !== null) {
                @unlink($dir.'/'.$source->getApprovalImage());
            }
            $name = $source->getKey().'-'.bin2hex(random_bytes(6)).'.'.($file->guessExtension() ?: 'png');
            $file->move($dir, $name);
            $source->setApprovalImage($name);
        }

        $em->flush();
        $this->addFlash('success', 'Freigabe gespeichert.');

        return $this->redirectToRoute('admin_source', ['id' => $id] + $this->adminRegionRedirectParams($request));
    }

    /** Stream the uploaded approval proof image (admin only). */
    #[Route('/sources/{id}/freigabe-bild', name: 'admin_source_approval_image', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function sourceApprovalImage(int $id, SourceRepository $sources, #[Autowire('%kernel.project_dir%')] string $projectDir): Response
    {
        $source = $sources->find($id);
        $path = $source?->getApprovalImage() !== null ? $projectDir.'/var/uploads/freigaben/'.$source->getApprovalImage() : null;
        if ($path === null || !is_file($path)) {
            throw $this->createNotFoundException();
        }

        $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($path);
        // Raster images render inline; anything else (e.g. an SVG uploaded
        // before the allowlist existed) is forced to download so it can never
        // run as a document on our origin.
        $inline = \in_array($response->getFile()->getMimeType(), self::APPROVAL_IMAGE_TYPES, true);
        $response->setContentDisposition($inline ? 'inline' : 'attachment', basename($path), 'freigabe');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /** Cookieless visit statistics: per-day views/visitors + top pages. */
    #[Route('/statistik', name: 'admin_stats', methods: ['GET'])]
    public function stats(Request $request, Connection $db): Response
    {
        $region = $this->resolveAdminRegion($request);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin'));
        $since = $today->modify('-29 days')->format('Y-m-d');
        $params = ['since' => $since];
        $regionWhere = '';
        if ($region !== null) {
            $regionWhere = ' AND region_id = :region';
            $params['region'] = $region->getId();
        }

        $rows = $db->fetchAllAssociative(
            'SELECT day, SUM(views) AS views, SUM(visitors) AS visitors FROM daily_stat WHERE day >= :since'.$regionWhere.' GROUP BY day ORDER BY day ASC',
            $params,
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
            'SELECT COALESCE(SUM(views), 0) AS views, COALESCE(SUM(visitors), 0) AS visitors FROM daily_stat WHERE day >= :since'.$regionWhere,
            $params,
        ) ?: ['views' => 0, 'visitors' => 0];
        $pages = $db->fetchAllAssociative(
            'SELECT route_key, SUM(views) AS views FROM page_stat WHERE day >= :since'.$regionWhere.' GROUP BY route_key ORDER BY views DESC LIMIT 15',
            $params,
        );
        // Most popular individual events (detail-page views, last 30 days).
        $eventWhere = '';
        if ($region !== null) {
            $eventWhere = ' AND e.region_id = :region';
        }
        $topEvents = $db->fetchAllAssociative(
            'SELECT e.id, e.slug, e.title, r.canonical_host, SUM(es.views) AS views
             FROM event_stat es JOIN event e ON e.id = es.event_id
             JOIN region r ON r.id = e.region_id
             WHERE es.day >= :since'.$eventWhere.' GROUP BY e.id, e.slug, e.title, r.canonical_host ORDER BY views DESC LIMIT 15',
            $params,
        );

        // Independent scales so the (smaller) visitor line is readable against
        // its own right-hand axis rather than hugging the baseline.
        $maxViews = max(1, max(array_column($series, 'views')));
        $maxVisitors = max(1, max(array_column($series, 'visitors')));

        // Headline numbers in two rows (Aufrufe / Besucher) × heute · 7 · 30 Tage,
        // all derived from the 30-day series (visitors summed daily — "grob").
        $views = array_column($series, 'views');
        $visitors = array_column($series, 'visitors');
        $tail = static fn (array $a, int $n): int => array_sum(\array_slice($a, -$n));
        $metrics = [
            'views' => ['heute' => (int) end($views), '7' => $tail($views, 7), '30' => array_sum($views)],
            'visitors' => ['heute' => (int) end($visitors), '7' => $tail($visitors, 7), '30' => array_sum($visitors)],
        ];

        return $this->render('admin/stats.html.twig', $this->withAdminRegion($region, [
            'series' => $series,
            'maxViews' => $maxViews,
            'maxVisitors' => $maxVisitors,
            'metrics' => $metrics,
            'pages' => $pages,
            'topEvents' => $topEvents,
        ]));
    }

    /** Review the AI deduplication merges and undo them if needed. */
    #[Route('/dedup', name: 'admin_dedup', methods: ['GET'])]
    public function dedup(Request $request, EntityManagerInterface $em, AiDeduper $deduper, EventRepository $events): Response
    {
        $region = $this->resolveAdminRegion($request);
        $decisions = $this->findDedupDecisions($em, $region);

        // The list renders both events' categories — batch-load them instead
        // of two lazy queries per row.
        $shown = [];
        foreach ($decisions as $decision) {
            foreach ([$decision->getDuplicateEvent(), $decision->getCanonicalEvent()] as $event) {
                if ($event !== null) {
                    $shown[] = $event;
                }
            }
        }
        $events->preloadCategories($shown);

        return $this->render('admin/dedup.html.twig', $this->withAdminRegion($region, [
            'decisions' => $decisions,
            'configured' => $deduper->isConfigured(),
            'model' => $deduper->getModel(),
        ]));
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
        RunLock $locks,
        #[Autowire('%kernel.project_dir%')] string $projectDir,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('dedup_run', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Formular.');

            return $this->redirectToRoute('admin_dedup', $this->adminRegionRedirectParams($request));
        }
        $region = $this->resolveAdminRegion($request);
        if (!$deduper->isConfigured()) {
            $this->addFlash('error', 'AI-Dedup ist nicht konfiguriert (OPENROUTER_API_KEY fehlt).');

            return $this->redirectToRoute('admin_dedup', $this->adminRegionRedirectParams($request));
        }
        // The command holds the actual lock ("ai-dedup", see DedupAiCommand) —
        // this check just turns a silently exiting second run into a message.
        if ($locks->isLocked('ai-dedup')) {
            $this->addFlash('error', 'Ein Dedup-Lauf läuft bereits – bitte warten, bis er fertig ist.');

            return $this->redirectToRoute('admin_dedup', $this->adminRegionRedirectParams($request));
        }

        $error = $this->startDetachedConsoleRun('dalketicker:dedup-ai --days=60 --max-ai-calls=80'.$this->consoleRegionOption($region), 'dedup-manual.log', $projectDir);
        if ($error !== null) {
            $this->addFlash('error', 'AI-Dedup: '.$error);
        } else {
            $this->addFlash('success', 'AI-Dedup gestartet – läuft im Hintergrund. Liste in ~1 Minute aktualisieren.');
        }

        return $this->redirectToRoute('admin_dedup', $this->adminRegionRedirectParams($request));
    }

    /** Undo a single AI merge: restore the duplicate to Published. */
    #[Route('/dedup/{id}/undo', name: 'admin_dedup_undo', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function dedupUndo(int $id, Request $request, EntityManagerInterface $em, DuplicateMerger $merger): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('dedup_undo', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Formular.');

            return $this->redirectToRoute('admin_dedup', $this->adminRegionRedirectParams($request));
        }
        $decision = $em->getRepository(AiDedupDecision::class)->find($id);
        if ($decision !== null && $decision->isActive()) {
            $merger->undo($decision);
            // Persist the veto: the nightly AI pass must never re-apply a merge
            // an admin has explicitly overruled ({@see DedupAiCommand}).
            $decision->setUndoneByAdminAt(new \DateTimeImmutable('now'));
            $em->flush();
            $this->addFlash('success', 'Merge rückgängig gemacht – Event wieder sichtbar.');
        }

        return $this->redirectToRoute('admin_dedup', $this->adminRegionRedirectParams($request));
    }

    /** Review AI categorization: progress, pending suggestions, and a full paginated log. */
    #[Route('/kategorien', name: 'admin_categorize', methods: ['GET'])]
    public function categorize(Request $request, EntityManagerInterface $em, AiCategorizer $categorizer, EventRepository $events): Response
    {
        $region = $this->resolveAdminRegion($request);

        $counts = [];
        foreach (['applied', 'pending', 'agreed', 'dismissed', 'undone'] as $status) {
            $counts[$status] = $this->countCategoryDecisions($em, $region, $status);
        }
        $processed = array_sum($counts);
        $remaining = $events->countWithoutCategoryDecision($region);

        // A run is "active" if decisions were written in the last 2 minutes — then
        // we let the page auto-refresh so progress updates live.
        $lastAt = $this->latestCategoryDecisionAt($em, $region);
        $active = $lastAt !== null && $lastAt > new \DateTimeImmutable('-2 minutes');

        // Full paginated log: what the categories were vs what the AI proposed/applied.
        $perPage = 50;
        $page = max(1, $request->query->getInt('seite', 1));
        $total = $this->countCategoryDecisions($em, $region);

        return $this->render('admin/categorize.html.twig', $this->withAdminRegion($region, [
            'pending' => $this->findCategoryDecisions($em, $region, 200, 0, 'pending'),
            'log' => $this->findCategoryDecisions($em, $region, $perPage, ($page - 1) * $perPage),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'logTotal' => $total,
            'configured' => $categorizer->isConfigured(),
            'model' => $categorizer->getModel(),
            'counts' => $counts,
            'processed' => $processed,
            'remaining' => $remaining,
            'active' => $active,
            'summaryDone' => $events->countWithSummary($region),
            'summaryEligible' => $events->countSummaryEligible($region),
        ]));
    }

    /** Review the AI short-teasers: source text vs generated summary, paginated. */
    #[Route('/vorschau', name: 'admin_summaries', methods: ['GET'])]
    public function summaries(Request $request, EventRepository $events): Response
    {
        $region = $this->resolveAdminRegion($request);
        $perPage = 25;
        $page = max(1, $request->query->getInt('seite', 1));
        $total = $events->countSummarized($region);

        return $this->render('admin/summaries.html.twig', $this->withAdminRegion($region, [
            'events' => $events->findSummarized($perPage, ($page - 1) * $perPage, $region),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'total' => $total,
        ]));
    }

    /** Trigger the AI categorization pass by hand (detached, like the dedup run). */
    #[Route('/kategorien/run', name: 'admin_categorize_run', methods: ['POST'])]
    public function categorizeRun(
        Request $request,
        AiCategorizer $categorizer,
        RunLock $locks,
        #[Autowire('%kernel.project_dir%')] string $projectDir,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('categorize', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Formular.');

            return $this->redirectToRoute('admin_categorize', $this->adminRegionRedirectParams($request));
        }
        $region = $this->resolveAdminRegion($request);
        if (!$categorizer->isConfigured()) {
            $this->addFlash('error', 'AI ist nicht konfiguriert (OPENROUTER_API_KEY fehlt).');

            return $this->redirectToRoute('admin_categorize', $this->adminRegionRedirectParams($request));
        }
        // The command holds the actual lock ("ai-categorize", see
        // CategorizeAiCommand) — this check just provides a friendly message.
        if ($locks->isLocked('ai-categorize')) {
            $this->addFlash('error', 'Ein Kategorisierungs-Lauf läuft bereits – bitte warten, bis er fertig ist.');

            return $this->redirectToRoute('admin_categorize', $this->adminRegionRedirectParams($request));
        }

        $error = $this->startDetachedConsoleRun('dalketicker:categorize-ai --apply --max-calls=120'.$this->consoleRegionOption($region), 'categorize-manual.log', $projectDir);
        if ($error !== null) {
            $this->addFlash('error', 'KI-Kategorisierung: '.$error);
        } else {
            $this->addFlash('success', 'KI-Kategorisierung gestartet – läuft im Hintergrund. Liste in ~1–2 Minuten aktualisieren.');
        }

        return $this->redirectToRoute('admin_categorize', $this->adminRegionRedirectParams($request));
    }

    /**
     * Launch a console command detached from the request (nohup + "&") so it
     * survives the response. The previous log is kept as a single rotated
     * generation (<name>.log.1) so files in var/log stay bounded.
     *
     * @return string|null error hint for the flash message, null when the run started
     */
    private function startDetachedConsoleRun(string $arguments, string $logName, string $projectDir): ?string
    {
        // In prod, Symfony logs to stderr, so var/log isn't created on its own —
        // make sure it exists, else the redirect below fails and the job dies.
        @mkdir($projectDir.'/var/log', 0775, true);
        $log = $projectDir.'/var/log/'.$logName;
        if (is_file($log)) {
            @rename($log, $log.'.1');
        }

        // Trailing "&" lets the shell return immediately; nohup detaches the
        // run from the web request. "echo $!" hands back the PID so we can
        // verify the job didn't die right away.
        $cmd = sprintf(
            'nohup php %s/bin/console %s > %s 2>&1 & echo $!',
            escapeshellarg($projectDir),
            $arguments,
            escapeshellarg($log),
        );
        $process = Process::fromShellCommandline($cmd, $projectDir);
        $process->run();
        $pid = (int) trim($process->getOutput());

        // Grace period: a healthy run is still alive after 300 ms, an immediate
        // crash (missing php, fatal boot error, ...) is not.
        usleep(300_000);
        $died = $pid > 0 && \function_exists('posix_kill') && !@posix_kill($pid, 0);
        if (!$process->isSuccessful() || $pid <= 0 || $died) {
            return 'Start fehlgeschlagen – Details in var/log/'.$logName;
        }

        return null;
    }

    #[Route('/kategorien/{id}/{action}', name: 'admin_categorize_decide', requirements: ['id' => '\d+', 'action' => 'accept|dismiss|undo'], methods: ['POST'])]
    public function categorizeDecide(int $id, string $action, Request $request, EntityManagerInterface $em, CategoryApplier $applier): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('categorize', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Formular.');

            return $this->redirectToRoute('admin_categorize', $this->adminRegionRedirectParams($request));
        }
        $decision = $em->getRepository(AiCategoryDecision::class)->find($id);
        if ($decision !== null) {
            // The route requirement restricts $action to these three values.
            match ($action) {
                'accept' => $applier->acceptSuggestion($decision),
                'dismiss' => $applier->dismissSuggestion($decision),
                default => $applier->undo($decision),
            };
            $em->flush();
            $this->addFlash('success', match ($action) {
                'accept' => 'Vorschlag übernommen.',
                'dismiss' => 'Vorschlag verworfen.',
                default => 'Kategorie zurückgesetzt.',
            });
        }

        return $this->redirectToRoute('admin_categorize', $this->adminRegionRedirectParams($request));
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

            return $this->redirectToRoute('admin_event_edit', ['id' => $id] + $this->adminRegionRedirectParams($request));
        }

        $event->setTitle(mb_substr(trim((string) $request->request->get('title', '')), 0, 300) ?: $event->getTitle());
        $event->setOrganizer($this->blankToNull(mb_substr(trim((string) $request->request->get('organizer', '')), 0, 200)));
        $description = $this->blankToNull(trim((string) $request->request->get('description', '')));
        if ($description !== $event->getDescription()) {
            // The AI teaser describes the old text; the nightly pass writes a new one.
            $event->setSummary(null);
        }
        $event->setDescription($description);
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

        return $this->redirectToRoute('admin_event_edit', ['id' => $id] + $this->adminRegionRedirectParams($request));
    }

    private function resolveAdminRegion(Request $request): ?Region
    {
        $key = trim((string) $request->query->get('region', ''));
        if ($key === 'all') {
            return null;
        }
        if ($key !== '') {
            return $this->regions->findByKey($key) ?? $this->regionContext->current();
        }

        return $this->regionContext->current();
    }

    /**
     * @param array<string, mixed> $vars
     *
     * @return array<string, mixed>
     */
    private function withAdminRegion(?Region $region, array $vars): array
    {
        return $vars + [
            'admin_region' => $region,
            'admin_region_key' => $region?->getKey() ?? 'all',
        ];
    }

    /** @return array<string, string> */
    private function adminRegionRedirectParams(Request $request): array
    {
        $region = $request->query->get('region', $request->request->get('region'));

        return \is_string($region) && $region !== '' ? ['region' => $region] : [];
    }

    private function consoleRegionOption(?Region $region): string
    {
        return $region !== null ? ' --region='.escapeshellarg($region->getKey()) : '';
    }

    /** @return AiDedupDecision[] */
    private function findDedupDecisions(EntityManagerInterface $em, ?Region $region): array
    {
        $qb = $em->getRepository(AiDedupDecision::class)->createQueryBuilder('d')
            ->leftJoin('d.duplicateEvent', 'dup')->addSelect('dup')
            ->leftJoin('dup.source', 'dupSource')->addSelect('dupSource')
            ->leftJoin('dup.venue', 'dupVenue')->addSelect('dupVenue')
            ->leftJoin('d.canonicalEvent', 'can')->addSelect('can')
            ->leftJoin('can.source', 'canSource')->addSelect('canSource')
            ->leftJoin('can.venue', 'canVenue')->addSelect('canVenue')
            ->andWhere('d.active = :active')
            ->setParameter('active', true)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults(100);

        if ($region !== null) {
            $qb->andWhere('dup.region = :region OR can.region = :region')
                ->setParameter('region', $region);
        }

        return $qb->getQuery()->getResult();
    }

    private function countCategoryDecisions(EntityManagerInterface $em, ?Region $region, ?string $status = null): int
    {
        $qb = $this->categoryDecisionQuery($em, $region)
            ->select('COUNT(d.id)');

        if ($status !== null) {
            $qb->andWhere('d.status = :status')->setParameter('status', $status);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function latestCategoryDecisionAt(EntityManagerInterface $em, ?Region $region): ?\DateTimeImmutable
    {
        $value = $this->categoryDecisionQuery($em, $region)
            ->select('MAX(d.createdAt)')
            ->getQuery()
            ->getSingleScalarResult();

        return $value !== null ? new \DateTimeImmutable((string) $value) : null;
    }

    /** @return AiCategoryDecision[] */
    private function findCategoryDecisions(EntityManagerInterface $em, ?Region $region, int $limit, int $offset = 0, ?string $status = null): array
    {
        $qb = $this->categoryDecisionQuery($em, $region)
            ->addSelect('e', 's', 'v')
            ->leftJoin('e.source', 's')
            ->leftJoin('e.venue', 'v')
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($status !== null) {
            $qb->andWhere('d.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    private function categoryDecisionQuery(EntityManagerInterface $em, ?Region $region): QueryBuilder
    {
        $qb = $em->createQueryBuilder()
            ->select('d')
            ->from(AiCategoryDecision::class, 'd')
            ->leftJoin('d.event', 'e');

        if ($region !== null) {
            $qb->andWhere('e.region = :region')->setParameter('region', $region);
        }

        return $qb;
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
            } elseif (mb_strlen($email) > 180) {
                // Column length of User::$email; a longer value would only surface as a DB exception.
                $error = 'Die E-Mail-Adresse darf höchstens 180 Zeichen lang sein.';
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
                // doesn't log the user out. The remember-me cookie is signed
                // with the password hash and names the old e-mail, so it is
                // dead now too: re-issue it when the admin had opted in
                // (cookie present, default name from security.yaml), otherwise
                // the next browser restart would log them out.
                $badges = $request->cookies->has('REMEMBERME') ? [(new RememberMeBadge())->enable()] : [];
                $security->login($user, badges: $badges);
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
