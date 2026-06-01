<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\AiSummarizer;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * AI teaser pass: derives a 1–2 sentence summary from each event's original
 * description (for the list view), date-ordered (soonest first). Only events
 * with a description and no summary yet are processed. Dry-run by default.
 */
#[AsCommand(
    name: 'dalketicker:summarize-ai',
    description: 'KI-Kurzvorschau (1–2 Sätze) je Event aus dem Originaltext',
)]
final class SummarizeAiCommand extends Command
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly AiSummarizer $summarizer,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Zusammenfassungen speichern (sonst Dry-Run)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max. Events (0 = alle)', '0')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Events pro KI-Aufruf', '20')
            ->addOption('max-calls', null, InputOption::VALUE_REQUIRED, 'Max. KI-Aufrufe (0 = unbegrenzt)', '0')
            ->addOption('shards', null, InputOption::VALUE_REQUIRED, 'Gesamtzahl paralleler Läufe', '1')
            ->addOption('shard', null, InputOption::VALUE_REQUIRED, 'Index dieses Laufs (0..shards-1)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $limit = max(0, (int) $input->getOption('limit'));
        $batch = max(1, (int) $input->getOption('batch'));
        $maxCalls = max(0, (int) $input->getOption('max-calls'));

        $io->title(sprintf('KI-Kurzvorschau %s – Modell: %s', $apply ? '(APPLY)' : '(Dry-Run)', $this->summarizer->getModel()));
        if (!$this->summarizer->isConfigured()) {
            $io->error('OPENROUTER_API_KEY ist nicht gesetzt.');

            return Command::FAILURE;
        }

        $shards = max(1, (int) $input->getOption('shards'));
        $shard = max(0, (int) $input->getOption('shard'));
        $events = $this->events->findWithoutSummary($limit > 0 ? $limit : 100000, $shards, $shard);
        if ($events === []) {
            $io->success('Keine Events ohne Vorschau.');

            return Command::SUCCESS;
        }
        $io->writeln(sprintf('%d Events ohne Vorschau (nach Datum) …', \count($events)));

        $calls = 0;
        $done = 0;
        foreach (array_chunk($events, $batch) as $chunk) {
            if ($maxCalls > 0 && $calls >= $maxCalls) {
                $io->warning('Budget für KI-Aufrufe erreicht – Stopp.');
                break;
            }
            ++$calls;
            $summaries = $this->summarizer->summarize($chunk)['summaries'];

            foreach ($chunk as $event) {
                $summary = $summaries[$event->getId()] ?? null;
                if ($summary === null) {
                    continue;
                }
                ++$done;
                if ($apply) {
                    $event->setSummary($summary);
                } elseif ($io->isVerbose()) {
                    $io->writeln(sprintf('  #%d "%s" → %s', $event->getId(), mb_strimwidth($event->getTitle(), 0, 30, '…'), $summary));
                }
            }

            if ($apply) {
                $this->em->flush();
            }
        }

        $io->success(sprintf('%s | KI-Aufrufe: %d · Vorschauen: %d', $apply ? 'Gespeichert' : 'Dry-Run', $calls, $done));

        return Command::SUCCESS;
    }
}
