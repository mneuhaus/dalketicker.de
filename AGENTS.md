# AGENTS.md — Dalketicker

Veranstaltungs-Aggregator für den **Kreis Gütersloh**. Sammelt Events aus vielen
fragmentierten Quellen an einem Ort. Öffentliches Projekt.

## Stack

- **Symfony 7.4 LTS** (PHP 8.4), **PostgreSQL 16**, **FrankenPHP** (App-Server),
  **Traefik** (Reverse Proxy).
- **Twig + Tailwind CSS v4** über AssetMapper + `symfonycasts/tailwind-bundle`
  (kein Node-Build). Tailwind-Theme in `assets/styles/app.css`.
- Paketmanager: **pnpm** falls JS nötig (aktuell nicht — AssetMapper).

## Lokale Entwicklung

```bash
make dc/up        # startet App+DB hinter Traefik, Setup beim 1. Lauf
make dc/shell     # Shell im App-Container
make db/fixtures  # DB neu aufsetzen + Demo-Daten
make tailwind/build
```

- App:  https://dalketicker.traefik.me
- Console-Befehle laufen **im Container**: `docker compose exec app php bin/console …`

## Deployment (Produktion)

Live unter **https://dalketicker.de** (Aliase `www.dalketicker.de` und
`dalketicker.neuhaus.nrw` → 301 auf den Apex) auf `root@neuhaus.nrw`
(`/opt/dalketicker.neuhaus.nrw/`). Geteiltes Traefik (Netz `web`, certresolver
`neuhaus`).

```bash
make deploy       # Guard (sauberer Working Tree + Rückfrage), rsync,
                  # Image-Build, Migrationen, Blue-Green-Swap, Sidecars
```

`make deploy` bricht bei unsauberem Working Tree ab (`DEPLOY_FORCE=1` zum
Erzwingen) und fragt vor dem rsync nach (`DEPLOY_YES=1` für Automatisierung).

> **WICHTIG:** Der rsync **muss `.env` ausschließen**. Die Server-`.env` enthält
> die generierten Secrets (`APP_SECRET`, `DB_PASSWORD`, mit dem das Postgres-
> Volume initialisiert wurde). Überschreibt man sie, bricht die DB-Auth.
> Das `make deploy/sync`-Target schließt `.env` bereits aus.

Prod-Image: `docker/Dockerfile.prod` (Multi-Stage, Code gebacken, Assets +
Tailwind precompiled, `APP_ENV=prod`). Fixtures gibt es in prod **nicht**
(dev-only) — Daten via `php bin/console dalketicker:seed [--demo]`.

## Betrieb (Scheduler, Backup, Locks)

- **Scheduler:** Der Service `scheduler` (gleiches App-Image, `docker/cron.sh`)
  importiert alle 3 h (`dalketicker:import --all`) und fährt nachts die
  AI-Pflege: 03:15 `dedup-ai`, 03:45 `categorize-ai --apply`, 04:15
  `summarize-ai --apply`, 04:45 `rededup`; sonntags 05:15
  `prune-unseen --apply`. Logs: `docker compose -f docker-compose.prod.yml logs scheduler`.
- **DB-Backup:** Sidecar `db-backup` (`docker/backup.sh`) zieht täglich gegen
  03:00 einen `pg_dump -Fc` ins Volume `dalketicker-db-backups`, Aufbewahrung
  14 Tage. Manuell: `make deploy/backup`. Restore via `pg_restore`.
- **Locks:** Die Console-Commands sichern sich selbst per flock — Überlappung
  von Scheduler- und Hand-Läufen ist unkritisch.

## Konventionen

- **Keine Emojis** — weder im UI/Design noch in Templates oder Commit-Messages.
  Icons als Inline-SVG (siehe `_logo.html.twig`, `event/show.html.twig`).
- UI-Sprache ist **Deutsch**. Datumsformatierung über die `de_*`-Twig-Filter
  (`App\Twig\GermanDateExtension`), nicht über `intl` zur Laufzeit.
- PHP: `declare(strict_types=1)`, typed properties, Konstruktor-Injection.
- Kleine, gut benannte Methoden; dem umgebenden Stil folgen.
- Keine Co-Authored-By-Signaturen in Commits.

## Architektur

- **Entities:** `Event`, `Venue`, `Source`, `Category` (+ Enums `SourceType`,
  `EventStatus`).
- **Dedup, zweistufig:** innerhalb einer Quelle über `(source, externalId)`
  (Upsert); quellenübergreifend über `dedupKey` (= normalisierter Titel + Tag +
  Ort) → spätere Treffer werden `EventStatus::Duplicate` und via `duplicateOf`
  verlinkt, damit sie nicht doppelt erscheinen.
- **Listing/Filter:** `EventRepository` + `App\Search\EventFilter`
  (Mehrfach-Kategorie über `kategorie[]`, Suche, Ort, Zeitraum). Sidebar-Filter
  in `templates/event/_sidebar.html.twig` (Checkboxen, Auto-Submit).

### Neue Quelle anbinden

1. `SourceImporter` implementieren (Tag `app.source_importer`, eindeutiger
   `getKey()`), gibt `ImportedEvent`-DTOs zurück. Vorlage: `IcsImporter`.
2. `Source`-Datensatz in `CatalogSeeder` ergänzen (`importer` = der Key) und
   `enabled` setzen.
3. Import: `php bin/console dalketicker:import <key>` bzw. `--all`
   (`--dry-run` zum Testen). `EventImporter` übernimmt Upsert + Dedup + Slug.

Quellen-Recherche-Hinweis: Mehrere lokale Seiten hängen am selben Backend
("auf Schlühr"). Wenn das eine ICS/JSON-Schnittstelle hat, deckt ein Importer
mehrere Quellen ab — vor dem HTML-Scraping prüfen.
