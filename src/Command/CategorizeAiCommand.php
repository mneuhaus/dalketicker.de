<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\AiCategorizer;
use App\Ai\CategoryApplier;
use App\Entity\Event;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * AI categorization pass (run after import/dedup).
 *  - "fill":    events without a real category get AI categories applied directly.
 *  - "opinion": events that already have a category get a second opinion; a clear
 *               contradiction is recorded as a pending suggestion for /admin.
 * Every looked-at event gets an {@see \App\Entity\AiCategoryDecision} (auditable,
 * reversible, and used to skip already-processed events on re-runs).
 * Dry-run by default; pass --apply to write.
 */
#[AsCommand(
    name: 'dalketicker:categorize-ai',
    description: 'KI-Kategorisierung: Lücken füllen + Zweitmeinung (Widersprüche zur Admin-Prüfung)',
)]
final class CategorizeAiCommand extends Command
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly AiCategorizer $categorizer,
        private readonly CategoryApplier $applier,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Änderungen wirklich speichern (sonst Dry-Run)')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'fill | opinion | all', 'all')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max. Events pro Pass (0 = alle)', '0')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Events pro KI-Aufruf', '25')
            ->addOption('max-calls', null, InputOption::VALUE_REQUIRED, 'Max. KI-Aufrufe insgesamt (0 = unbegrenzt)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $mode = (string) $input->getOption('mode');
        $limit = max(0, (int) $input->getOption('limit'));
        $batch = max(1, (int) $input->getOption('batch'));
        $maxCalls = max(0, (int) $input->getOption('max-calls'));

        $io->title(sprintf('KI-Kategorisierung %s – Modell: %s', $apply ? '(APPLY)' : '(Dry-Run)', $this->categorizer->getModel()));
        if (!$this->categorizer->isConfigured()) {
            $io->error('OPENROUTER_API_KEY ist nicht gesetzt.');

            return Command::FAILURE;
        }

        $calls = 0;
        $stats = ['fill' => 0, 'suggestions' => 0, 'agreed' => 0, 'skipped' => 0];

        foreach (['fill' => true, 'opinion' => false] as $passName => $uncategorized) {
            if ($mode !== 'all' && $mode !== $passName) {
                continue;
            }

            $events = $this->events->findForCategorization($uncategorized, $limit > 0 ? $limit : 100000);
            if ($events === []) {
                continue;
            }
            $io->section(sprintf('Pass "%s": %d Events', $passName, \count($events)));

            foreach (array_chunk($events, $batch) as $chunk) {
                if ($maxCalls > 0 && $calls >= $maxCalls) {
                    $io->warning('Budget für KI-Aufrufe erreicht – Stopp.');
                    break 2;
                }
                ++$calls;
                $proposals = $this->categorizer->classify($chunk)['proposals'];

                foreach ($chunk as $event) {
                    $proposal = $proposals[$event->getId()] ?? null;
                    if ($proposal === null) {
                        ++$stats['skipped'];
                        continue;
                    }
                    $this->handle($io, $event, $proposal, $uncategorized, $apply, $stats);
                }

                if ($apply) {
                    $this->em->flush();
                }
            }
        }

        $io->newLine();
        $io->success(sprintf(
            '%s | KI-Aufrufe: %d · gefüllt: %d · Vorschläge (pending): %d · bestätigt: %d · ohne Vorschlag: %d',
            $apply ? 'Gespeichert' : 'Dry-Run (nichts gespeichert)',
            $calls, $stats['fill'], $stats['suggestions'], $stats['agreed'], $stats['skipped'],
        ));

        return Command::SUCCESS;
    }

    /**
     * @param array{slugs: list<string>, reason: string} $proposal
     * @param array<string,int>                          $stats
     */
    private function handle(SymfonyStyle $io, Event $event, array $proposal, bool $uncategorized, bool $apply, array &$stats): void
    {
        $model = $this->categorizer->getModel();
        $current = [];
        foreach ($event->getCategories() as $c) {
            $current[] = $c->getSlug();
        }

        if ($uncategorized) {
            ++$stats['fill'];
            if ($apply) {
                $this->applier->applyFill($event, $proposal['slugs'], $model, $proposal['reason']);
            } elseif ($io->isVerbose()) {
                $io->writeln(sprintf('  fill  #%d "%s" → %s', $event->getId(), $event->getTitle(), implode(',', $proposal['slugs'])));
            }

            return;
        }

        // Opinion pass: clear contradiction = the AI's primary pick isn't among
        // the event's current categories.
        $contradiction = !\in_array($proposal['slugs'][0], $current, true);
        if ($contradiction) {
            ++$stats['suggestions'];
            if ($apply) {
                $this->applier->recordSuggestion($event, $proposal['slugs'], $model, $proposal['reason']);
            } elseif ($io->isVerbose()) {
                $io->writeln(sprintf('  ?diff #%d "%s": jetzt %s → KI %s', $event->getId(), $event->getTitle(), implode(',', $current), implode(',', $proposal['slugs'])));
            }
        } else {
            ++$stats['agreed'];
            if ($apply) {
                $this->applier->recordAgreed($event, $model);
            }
        }
    }
}
