# ======================================================================#
# Dalketicker - Makefile                                                #
# ======================================================================#
# Stack: FrankenPHP + PostgreSQL + Mailpit + Traefik                    #
# App:   https://dalketicker.traefik.me                                 #
# Mail:  https://mail-dalketicker.traefik.me                            #
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
	@echo "Mail: https://mail-dalketicker.traefik.me"

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
