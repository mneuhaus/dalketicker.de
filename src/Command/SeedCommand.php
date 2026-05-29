<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\CatalogSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds the catalogue (categories + sources) idempotently, and optionally a
 * batch of demo events. Works in every environment, including prod.
 *
 *   bin/console dalketicker:seed           # categories + sources only
 *   bin/console dalketicker:seed --demo    # also add demo events
 */
#[AsCommand(
    name: 'dalketicker:seed',
    description: 'Legt Kategorien + Quellen an (idempotent), optional Demo-Events',
)]
final class SeedCommand extends Command
{
    public function __construct(private readonly CatalogSeeder $seeder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('demo', null, InputOption::VALUE_NONE, 'Zusätzlich Demo-Events anlegen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->seeder->seedCatalog();
        $io->success('Katalog (Kategorien + Quellen) angelegt/aktualisiert.');

        if ($input->getOption('demo')) {
            $this->seeder->seedDemoEvents();
            $io->success('Demo-Events angelegt.');
        }

        return Command::SUCCESS;
    }
}
