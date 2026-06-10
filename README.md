# Dalketicker

Veranstaltungs-Aggregator für den **Kreis Gütersloh** — sammelt Events aus
vielen fragmentierten Quellen (Vereins-Seiten, Stadtkalender, ICS-Feeds) an
einem Ort. Live unter **https://dalketicker.de**.

## Stack

- **Symfony 7.4 LTS** (PHP 8.4), **PostgreSQL 16**
- **FrankenPHP** als App-Server, **Traefik** als Reverse Proxy
- **Twig + Tailwind CSS v4** über AssetMapper + `symfonycasts/tailwind-bundle`
  (kein Node-Build)

## Lokale Entwicklung

```bash
make dc/up        # startet App+DB hinter Traefik, Setup beim 1. Lauf
make dc/shell     # Shell im App-Container
make db/fixtures  # DB neu aufsetzen + Demo-Daten
make tailwind/build
```

- App:  https://dalketicker.traefik.me
- Console-Befehle laufen im Container: `docker compose exec app php bin/console …`

## Import & AI-Pipeline

```bash
make import       # alle aktiven Quellen importieren (dalketicker:import --all)
make import/dry   # Dry-Run
make dedup        # AI-Dedup quellenübergreifend (dalketicker:dedup-ai)
make categorize   # AI-Kategorisierung (dalketicker:categorize-ai --apply)
make summarize    # AI-Kurzteaser (dalketicker:summarize-ai --apply)
```

Wie man eine neue Quelle anbindet, steht in [AGENTS.md](AGENTS.md).

## Deployment

```bash
make deploy       # Guard, rsync, Image-Build, Migrationen, Blue-Green-Swap
```

`make deploy` bricht bei unsauberem Working Tree ab (`DEPLOY_FORCE=1` zum
Erzwingen) und fragt vor dem rsync nach (`DEPLOY_YES=1` für Automatisierung).
Details (Server, Secrets, Prod-Image) in [AGENTS.md](AGENTS.md).

## Betrieb auf dem Server

- **Scheduler:** Service `scheduler` in `docker-compose.prod.yml`
  (`docker/cron.sh`) — Importe alle 3 h, nachts die AI-Pflegepässe
  (dedup, categorize, summarize, rededup), sonntags `prune-unseen`.
- **DB-Backup:** Sidecar `db-backup` (`docker/backup.sh`) — täglich gegen
  03:00 ein `pg_dump -Fc` ins Volume `dalketicker-db-backups`, Aufbewahrung
  14 Tage. Manueller Dump: `make deploy/backup`.

## Weiteres

Konventionen, Architektur und Quellen-Anbindung: [AGENTS.md](AGENTS.md).
