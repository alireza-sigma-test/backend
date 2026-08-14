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
	@echo "phpMyAdmin http://localhost:8081  (proposal / secret)"
	@echo "Mailpit    http://localhost:8025"

down: ## Stop the stack
	$(DC) down

fresh: ## Drop, re-migrate and re-seed the database
	$(PHP) php artisan migrate:fresh --seed

test: ## Run the Pest suite — ARGS="--filter=Foo" narrows it
	$(PHP) php artisan test $(ARGS)

shell: ## Open a shell in the PHP container
	$(PHP) sh

logs: ## Tail all container logs
	$(DC) logs -f

.PHONY: help up down fresh test shell logs
