<?php

declare(strict_types=1);

namespace App\Command;

use App\Importer\ImportedEvent;
use App\Repository\SourceRepository;
use App\Service\EventImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manually add a single event under an existing source — for events that no
 * automated importer can reach (e.g. only posted on Facebook, or a flyer).
 *
 * Goes through {@see EventImporter::upsertOne()}, i.e. the exact same path as a
 * real import (venue/category resolution, dedup, slug), so a later automated
 * run reconciles cleanly. The external id is prefixed "manual:" so it never
 * collides with importer-generated ids; re-running with the same fields just
 * updates the event instead of creating a duplicate.
 */
#[AsCommand(
    name: 'dalketicker:event:add',
    description: 'Einzelnes Event manuell unter einer Quelle anlegen (z. B. nur-auf-Facebook / Plakat)',
)]
final class AddEventCommand extends Command
{
    public function __construct(
        private readonly SourceRepository $sources,
        private readonly EventImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Quellen-Key (z. B. sv_pavenstaedt)')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Titel')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Startdatum (YYYY-MM-DD oder DD.MM.YYYY)')
            ->addOption('time', null, InputOption::VALUE_REQUIRED, 'Startuhrzeit HH:MM (leer = ganztägig)')
            ->addOption('end-date', null, InputOption::VALUE_REQUIRED, 'Enddatum (optional)')
            ->addOption('end-time', null, InputOption::VALUE_REQUIRED, 'Enduhrzeit HH:MM (optional)')
            ->addOption('location', null, InputOption::VALUE_REQUIRED, 'Ort / Adresse')
            ->addOption('city', null, InputOption::VALUE_REQUIRED, 'Stadt (wird normalisiert)')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Kategorie-Slug (z. B. markt)')
            ->addOption('price', null, InputOption::VALUE_REQUIRED, 'Preis (Freitext)')
            ->addOption('organizer', null, InputOption::VALUE_REQUIRED, 'Veranstalter')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Quell-/Info-Link')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Beschreibung (bei Fakten-only leer lassen)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tz = new \DateTimeZone('Europe/Berlin');

        $sourceKey = (string) $input->getOption('source');
        $title = trim((string) $input->getOption('title'));
        if ($sourceKey === '' || $title === '') {
            $io->error('--source und --title sind erforderlich.');

            return Command::INVALID;
        }

        $source = $this->sources->findByKey($sourceKey);
        if ($source === null) {
            $io->error(sprintf('Quelle "%s" nicht gefunden.', $sourceKey));

            return Command::FAILURE;
        }

        $start = $this->buildDateTime((string) $input->getOption('date'), (string) $input->getOption('time'), $tz);
        if ($start === null) {
            $io->error('Ungültiges --date. Erwartet YYYY-MM-DD oder DD.MM.YYYY.');

            return Command::INVALID;
        }
        $allDay = trim((string) $input->getOption('time')) === '';

        $endDateOpt = trim((string) $input->getOption('end-date'));
        $end = $this->buildDateTime(
            $endDateOpt !== '' ? $endDateOpt : (string) $input->getOption('date'),
            (string) $input->getOption('end-time'),
            $tz,
        );
        if ($end !== null && $end <= $start) {
            $end = null;
        }

        $location = $this->opt($input, 'location');
        $externalId = sprintf(
            'manual:%s-%s',
            $start->format('Y-m-d'),
            substr(preg_replace('/[^a-z0-9]+/', '', mb_strtolower($title)) ?? '', 0, 40),
        );

        $dto = new ImportedEvent(
            title: $title,
            startsAt: $start,
            endsAt: $end,
            allDay: $allDay,
            description: $this->opt($input, 'description'),
            venueName: $location,
            city: $this->opt($input, 'city'),
            locationText: $location,
            categorySlug: $this->opt($input, 'category'),
            sourceUrl: $this->opt($input, 'url'),
            price: $this->opt($input, 'price'),
            organizer: $this->opt($input, 'organizer'),
            externalId: $externalId,
            raw: ['manual' => true],
        );

        $created = $this->importer->upsertOne($source, $dto);

        $io->success(sprintf(
            '%s: "%s" am %s%s (Quelle %s, externalId %s)',
            $created ? 'Angelegt' : 'Aktualisiert',
            $title,
            $start->format('d.m.Y'),
            $allDay ? '' : ' '.$start->format('H:i'),
            $sourceKey,
            $externalId,
        ));

        return Command::SUCCESS;
    }

    private function opt(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    private function buildDateTime(string $date, string $time, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $date = trim($date);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $date, $m)) {
            [$y, $mo, $d] = [(int) $m[3], (int) $m[2], (int) $m[1]];
        } else {
            return null;
        }

        $h = 0;
        $i = 0;
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $tm)) {
            $h = (int) $tm[1];
            $i = (int) $tm[2];
        }

        return (new \DateTimeImmutable('now', $tz))->setDate($y, $mo, $d)->setTime($h, $i);
    }
}
