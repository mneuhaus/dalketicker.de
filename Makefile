# ======================================================================#
# Dalketicker - Makefile                                                #
# ======================================================================#
# Stack: FrankenPHP + PostgreSQL + Traefik                              #
# App:   https://dalketicker.traefik.me                                 #
# ======================================================================#

.PHONY: help
help: ## Show this help
	@grep -E '^[a-zA-Z0-9_/-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-22s\033[0m %s\n", $$1, $$2}'

# Run a console command inside the app container
CONSOLE = docker compose exec -T app php bin/console

# ======================================================================#
# Installation & Build                                                  #
# ======================================================================#

install: ## Install composer dependencies (host)
	composer install

tailwind/build: ## Build Tailwind CSS once
	$(CONSOLE) tailwind:build

tailwind/watch: ## Build Tailwind CSS in watch mode
	docker compose exec app php bin/console tailwind:build --watch

assets/compile: ## Compile AssetMapper assets (production)
	$(CONSOLE) asset-map:compile

cache/clear: ## Clear Symfony cache
	$(CONSOLE) cache:clear

# ======================================================================#
# Docker Compose                                                        #
# ======================================================================#

.PHONY: dc/up dc/down dc/restart dc/build dc/logs dc/status dc/network dc/setup dc/reset dc/shell
.PHONY: dc/traefik/start dc/traefik/stop dc/traefik/status
.PHONY: db/fixtures migrate

dc/network: ## Create the shared traefik docker network
	@docker network inspect $${TRAEFIK_NETWORK:-traefik} >/dev/null 2>&1 || \
	  docker network create $${TRAEFIK_NETWORK:-traefik}

dc/traefik/start: ## Start the shared Traefik reverse proxy
	@if docker ps --filter name=traefik --filter status=running | grep -q traefik; then \
	  echo "Traefik already running"; \
	else \
	  $(MAKE) dc/network; \
	  docker run -d --name traefik --restart unless-stopped \
	    --network $${TRAEFIK_NETWORK:-traefik} \
	    -p 80:80 -p 443:443 -p 8080:8080 \
	    -v /var/run/docker.sock:/var/run/docker.sock:ro \
	    -v $${HOME}/.local/share/traefik:/data \
	    traefik:v3.0 \
	    --api.dashboard=true --api.insecure=true \
	    --entrypoints.web.address=:80 \
	    --entrypoints.websecure.address=:443 \
	    --providers.docker=true \
	    --providers.docker.network=$${TRAEFIK_NETWORK:-traefik} \
	    --providers.docker.exposedbydefault=false \
	    --log.level=INFO; \
	  echo "Traefik started - Dashboard: http://localhost:8080"; \
	fi

dc/traefik/stop: ## Stop the shared Traefik
	@docker stop traefik 2>/dev/null || true
	@docker rm traefik 2>/dev/null || true

dc/traefik/status: ## Show Traefik status
	@if docker ps --filter name=traefik --filter status=running | grep -q traefik; then \
	  echo "Traefik: running (http://localhost:8080)"; \
	else \
	  echo "Traefik: not running"; \
	fi

dc/up: ## Start the environment (auto-setup on first run)
	@$(MAKE) dc/traefik/start
	docker compose up -d --build
	@if [ ! -f .docker-initialized ]; then \
	  echo "First start - running setup..."; \
	  $(MAKE) dc/setup; \
	fi
	@$(MAKE) dc/status

dc/down: ## Stop the environment
	docker compose down

dc/restart: ## Restart the app container
	docker compose restart app

dc/build: ## Rebuild the app image from scratch
	docker compose build --pull --no-cache

dc/setup: ## First-run setup (deps, schema, fixtures, css)
	@echo "[1/4] Composer install..."
	@composer install --no-interaction
	@echo "[2/4] Database schema + migrations..."
	@$(MAKE) migrate
	@echo "[3/4] Fixtures..."
	@$(CONSOLE) doctrine:fixtures:load --no-interaction 2>/dev/null || true
	@echo "[4/4] Tailwind build..."
	@$(MAKE) tailwind/build
	@touch .docker-initialized
	@echo "Setup complete -> https://dalketicker.traefik.me"

dc/reset: ## Delete containers/volumes and re-run setup
	@read -p "This deletes all data. Sure? (y/N) " c && [ "$$c" = "y" ] || (echo Aborted && exit 1)
	@rm -f .docker-initialized
	docker compose down -v --remove-orphans
	@$(MAKE) dc/up

dc/logs: ## Follow container logs
	docker compose logs -f

dc/status: ## Show status + URLs
	@docker compose ps
	@$(MAKE) dc/traefik/status
	@echo "App:  https://dalketicker.traefik.me"

dc/shell: ## Open a shell in the app container
	docker compose exec app bash

# ======================================================================#
# Database                                                              #
# ======================================================================#

migrate: ## Create DB (if needed) and run migrations
	@$(CONSOLE) doctrine:database:create --if-not-exists
	@$(CONSOLE) doctrine:migrations:migrate --no-interaction

db/fixtures: ## Drop, recreate, migrate and load fixtures
	@$(CONSOLE) doctrine:database:drop --force --if-exists || true
	@$(CONSOLE) doctrine:database:create --if-not-exists
	@$(CONSOLE) doctrine:migrations:migrate --no-interaction
	@$(CONSOLE) doctrine:fixtures:load --no-interaction

# ======================================================================#
# Aggregation                                                           #
# ======================================================================#

import: ## Run all source importers
	@$(CONSOLE) dalketicker:import --all

import/dry: ## Run importers without writing (dry run)
	@$(CONSOLE) dalketicker:import --all --dry-run

dedup: ## AI dedup pass (after import)
	@$(CONSOLE) dalketicker:dedup-ai

categorize: ## AI categorization pass (after import/dedup)
	@$(CONSOLE) dalketicker:categorize-ai --apply

summarize: ## AI short-teaser pass (after categorization)
	@$(CONSOLE) dalketicker:summarize-ai --apply

dedup/dry: ## AI dedup pass, propose only (no changes)
	@$(CONSOLE) dalketicker:dedup-ai --dry-run

# ======================================================================#
# Quality                                                               #
# ======================================================================#

.PHONY: stan test

stan: ## Run PHPStan static analysis (host PHP)
	php vendor/bin/phpstan analyse --memory-limit=1G

test: ## Run the PHPUnit test suite (host PHP)
	php bin/phpunit

# ======================================================================#
# Deployment (dalketicker.de)                                           #
# ======================================================================#
# Server keeps its own .env with the generated secrets — NEVER rsync it,
# or the Postgres credentials break.

DEPLOY_HOST = root@neuhaus.nrw
DEPLOY_PATH = /opt/dalketicker.neuhaus.nrw
PROD = docker compose -f docker-compose.prod.yml

.PHONY: deploy deploy/check deploy/sync deploy/build deploy/migrate deploy/rollout deploy/sidecars deploy/backup

deploy: deploy/check deploy/sync deploy/build deploy/migrate deploy/rollout deploy/sidecars ## Sync, build, migrate, zero-downtime swap
	@echo "Deployed -> https://dalketicker.de"

deploy/check: ## Guard: clean working tree + confirmation (DEPLOY_FORCE=1 / DEPLOY_YES=1 to skip)
	@if [ "$(DEPLOY_FORCE)" != "1" ] && [ -n "$$(git status --porcelain)" ]; then \
	  echo "ABBRUCH: Working Tree nicht sauber — deploy rsynct mit --delete nach Prod."; \
	  echo "Erst committen/stashen oder mit DEPLOY_FORCE=1 erzwingen."; \
	  exit 1; \
	fi
	@if [ "$(DEPLOY_YES)" != "1" ]; then \
	  read -p "Deploy nach $(DEPLOY_HOST):$(DEPLOY_PATH)? (y/N) " c && [ "$$c" = "y" ] || (echo Aborted && exit 1); \
	fi

deploy/sync: ## rsync code to the server (excludes .env and build artifacts)
	rsync -az --delete \
	  --exclude '.git/' --exclude 'var/' --exclude 'vendor/' --exclude 'node_modules/' \
	  --exclude 'public/assets/' --exclude 'assets/vendor/' --exclude '.docker-initialized' \
	  --exclude '.env' --exclude '.env.local' --exclude '.env.*.local' --exclude 'tools/' \
	  -e 'ssh -o BatchMode=yes' \
	  ./ $(DEPLOY_HOST):$(DEPLOY_PATH)/

deploy/build: ## Build the new prod image (the running container keeps serving)
	ssh -o BatchMode=yes $(DEPLOY_HOST) 'cd $(DEPLOY_PATH) && $(PROD) build app'

deploy/migrate: ## Run migrations in a one-off container on the freshly built image
	ssh -o BatchMode=yes $(DEPLOY_HOST) 'cd $(DEPLOY_PATH) && $(PROD) run --rm --no-deps app php bin/console doctrine:migrations:migrate --no-interaction'

deploy/rollout: ## Blue-green swap: start new container, wait until healthy, then drop the old one
	ssh -o BatchMode=yes $(DEPLOY_HOST) 'cd $(DEPLOY_PATH) && \
	  OLD=$$($(PROD) ps -q app | head -1); \
	  if [ -z "$$OLD" ]; then echo "kein laufender app-Container - Erststart"; $(PROD) up -d --no-deps app; exit $$?; fi; \
	  echo "alter Container: $$OLD"; \
	  $(PROD) up -d --no-deps --no-recreate --scale app=2 app; \
	  NEW=$$($(PROD) ps -q app | grep -v "$$OLD" | head -1); \
	  if [ -z "$$NEW" ]; then echo "ABBRUCH: neuer Container nicht gestartet - alter bleibt aktiv"; exit 1; fi; \
	  echo "neuer Container: $$NEW"; \
	  for i in $$(seq 1 45); do \
	    s=$$(docker inspect -f "{{.State.Health.Status}}" "$$NEW" 2>/dev/null || echo none); \
	    [ "$$s" = "healthy" ] && break; \
	    echo "warte auf Health ($$s)..."; sleep 2; \
	  done; \
	  if [ "$$(docker inspect -f "{{.State.Health.Status}}" "$$NEW")" != "healthy" ]; then \
	    echo "ABBRUCH: neuer Container nicht gesund - alter bleibt aktiv"; \
	    docker rm -f "$$NEW" >/dev/null 2>&1 || true; \
	    exit 1; \
	  fi; \
	  echo "neuer Container gesund - kurz drainen, dann alten entfernen"; \
	  sleep 3; \
	  docker stop "$$OLD" >/dev/null && docker rm "$$OLD" >/dev/null; \
	  echo "Swap abgeschlossen"'

deploy/sidecars: ## Recreate scheduler + db-backup (picks up the freshly built image)
	ssh -o BatchMode=yes $(DEPLOY_HOST) 'cd $(DEPLOY_PATH) && $(PROD) up -d scheduler db-backup'

deploy/backup: ## Manual pg_dump on the server (into the backup volume, see docker/backup.sh)
	ssh -o BatchMode=yes $(DEPLOY_HOST) 'cd $(DEPLOY_PATH) && \
	  $(PROD) exec -T db-backup pg_dump -Fc -f /backups/dalketicker-manual-$$(date +%Y-%m-%d_%H%M%S).dump && \
	  $(PROD) exec -T db-backup ls -lh /backups'

deploy/import: ## Run importers on the server
	ssh -o BatchMode=yes $(DEPLOY_HOST) 'cd $(DEPLOY_PATH) && $(PROD) exec -T app php bin/console dalketicker:import --all'

deploy/dedup: ## Run the AI dedup pass on the server
	ssh -o BatchMode=yes $(DEPLOY_HOST) 'cd $(DEPLOY_PATH) && $(PROD) exec -T app php bin/console dalketicker:dedup-ai'

deploy/categorize: ## Run the AI categorization pass on the server
	ssh -o BatchMode=yes $(DEPLOY_HOST) 'cd $(DEPLOY_PATH) && $(PROD) exec -T app php bin/console dalketicker:categorize-ai --apply'

deploy/summarize: ## Run the AI teaser pass on the server
	ssh -o BatchMode=yes $(DEPLOY_HOST) 'cd $(DEPLOY_PATH) && $(PROD) exec -T app php bin/console dalketicker:summarize-ai --apply'
