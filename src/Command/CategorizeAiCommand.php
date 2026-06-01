<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\AiCategorizer;
use App\Repository\EventRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * AI-assisted category suggestions for events that lack a real category.
 * For now DRY-RUN ONLY: it prints what the model would assign, so we can judge
 * quality and cost before wiring up auto-apply + audit.
 */
#[AsCommand(
    name: 'dalketicker:categorize-ai',
    description: 'Schlägt per KI Kategorien für (noch) unkategorisierte Events vor (Dry-Run)',
)]
final class CategorizeAiCommand extends Command
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly AiCategorizer $categorizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Wie viele unkategorisierte Events testen', '25');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('KI-Kategorisierung (Dry-Run) – Modell: '.$this->categorizer->getModel());

        if (!$this->categorizer->isConfigured()) {
            $io->error('OPENROUTER_API_KEY ist nicht gesetzt.');

            return Command::FAILURE;
        }

        $limit = max(1, (int) $input->getOption('limit'));
        $events = $this->events->findUncategorizedUpcoming($limit);
        if ($events === []) {
            $io->success('Keine unkategorisierten Events gefunden.');

            return Command::SUCCESS;
        }

        $io->writeln(sprintf('%d unkategorisierte Events an die KI …', \count($events)));
        $result = $this->categorizer->classify($events);
        $proposals = $result['proposals'];

        $rows = [];
        foreach ($events as $event) {
            $current = [];
            foreach ($event->getCategories() as $c) {
                $current[] = $c->getSlug();
            }
            $proposal = $proposals[$event->getId()] ?? null;
            $rows[] = [
                $event->getId(),
                mb_strimwidth($event->getTitle(), 0, 40, '…'),
                $current === [] ? '—' : implode(',', $current),
                $proposal ? implode(',', $proposal['slugs']) : '(keine)',
                $proposal ? mb_strimwidth($proposal['reason'], 0, 50, '…') : '',
            ];
        }

        $io->table(['ID', 'Titel', 'jetzt', 'KI-Vorschlag', 'Begründung'], $rows);

        $usage = $result['usage'];
        if ($usage !== []) {
            $io->writeln(sprintf(
                '<comment>Tokens: prompt=%s, completion=%s, total=%s</comment>',
                $usage['prompt_tokens'] ?? '?',
                $usage['completion_tokens'] ?? '?',
                $usage['total_tokens'] ?? '?',
            ));
        }
        $io->note(sprintf('%d von %d Events bekamen einen Vorschlag. (Dry-Run – nichts gespeichert.)', \count($proposals), \count($events)));

        return Command::SUCCESS;
    }
}
