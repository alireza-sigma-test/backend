.DEFAULT_GOAL := help
DC := docker compose
PHP := $(DC) exec php

# The php container runs as the invoking user so bind-mounted files stay
# writable by both sides. Without these the compose fallback (1000:1000) is
# wrong on any host whose primary UID differs.
export WWWUSER := $(shell id -u)
export WWWGROUP := $(shell id -g)

help: ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-10s\033[0m %s\n",$$1,$$2}'

up: ## Build, start, migrate and seed — the one command a reviewer runs
	@test -f .env || cp .env.example .env
	$(DC) up -d --build
	# The bind mount shadows the image's vendor/, so a fresh clone has none.
	# Without this, every artisan call below fails on a clean checkout.
	$(PHP) composer install --no-interaction --prefer-dist
	$(PHP) php artisan key:generate --force
	$(PHP) php artisan migrate --force --seed
	$(PHP) php artisan storage:link
	@echo "API        http://localhost:8000"
	@echo "Websocket  ws://localhost:$${REVERB_HOST_PORT:-8080}  (Reverb)"
	@echo "phpMyAdmin http://localhost:8081  (proposal / secret)"
	@echo "Mailpit    http://localhost:8025"
	@echo ""
	@echo "The queue worker and Reverb come up with everything else — there is no"
	@echo "second terminal to open. Watch them with: docker compose logs -f queue reverb"

down: ## Stop the stack
	$(DC) down

fresh: ## Drop, re-migrate and re-seed the database, then purge orphaned media files
	$(PHP) php artisan migrate:fresh --seed
	# migrate:fresh restarts media ids at 1, so a stale directory left over
	# from a previous run can silently collide with (or just outlive) the
	# fresh seed's media row. media-library:clean removes exactly the
	# directories under the media disk (storage/app/private) that have no
	# matching row in the table this migration just rebuilt — it never
	# touches anything outside Media Library's own storage.
	$(PHP) php artisan media-library:clean --force

test: ## Run the Pest suite — ARGS="--filter=Foo" narrows it
	$(PHP) php artisan test $(ARGS)

lint: ## Check code style without changing files (CI-safe)
	$(PHP) ./vendor/bin/pint --test

lint-fix: ## Auto-fix code style
	$(PHP) ./vendor/bin/pint

shell: ## Open a shell in the PHP container
	$(PHP) sh

logs: ## Tail all container logs
	$(DC) logs -f

.PHONY: help up down fresh test lint lint-fix shell logs
