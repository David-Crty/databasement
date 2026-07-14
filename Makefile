.PHONY: help install start test test-sequential test-mysql test-postgres test-filter test-filter-mysql test-filter-postgres test-coverage test-coverage-filter backup-test lint-check lint-fix lint migrate migrate-fresh migrate-fresh-seed db-seed setup clean import-db docs docs-build release

# Colors for output
GREEN  := \033[0;32m
YELLOW := \033[0;33m
NC     := \033[0m # No Color

# Docker / PHP helpers
DOCKER_COMPOSE := docker compose
PHP_SERVICE    := app

# Forward AI-agent env vars (Claude Code, Cursor, Gemini, ...) into the container so
# laravel/pao detects the agent and emits compact JSON output instead of verbose logs.
# `-e VAR` is only added when VAR is set on the host, avoiding empty-string false positives.
AGENT_ENV := $(foreach v,CLAUDECODE CLAUDE_CODE AI_AGENT CURSOR_AGENT GEMINI_CLI PAO_DISABLE,$(if $($(v)),-e $(v)))
PHP_EXEC  := $(DOCKER_COMPOSE) exec --user application -T $(AGENT_ENV) $(PHP_SERVICE)
PHP_COMPOSER   := $(PHP_EXEC) composer
PHP_ARTISAN    := $(PHP_EXEC) php artisan
NPM_EXEC       := npm

##@ Help

help: ## Display this help message
	@echo "$(GREEN)Available commands:$(NC)"
	@awk 'BEGIN {FS = ":.*##"; printf "\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  $(YELLOW)%-15s$(NC) %s\n", $$1, $$2 } /^##@/ { printf "\n$(GREEN)%s$(NC)\n", substr($$0, 5) } ' $(MAKEFILE_LIST)

##@ Development

install: ## Install dependencies (composer + npm)
	$(PHP_COMPOSER) install
	$(NPM_EXEC) install

setup: start install build migrate
	docker compose restart app worker

start: ## Start development server (all services: php, queue, mysql, postgres)
	docker compose up -d

migrate:
	$(PHP_ARTISAN) migrate

logs:
	$(DOCKER_COMPOSE) logs -f php

create-bucket: ## Create S3 bucket in rustfs (usage: make create-bucket BUCKET=my-bucket)
	docker run --rm --network=$$(docker network ls --filter name=databasement -q | head -1) \
		-e AWS_ACCESS_KEY_ID=rustfsadmin \
		-e AWS_SECRET_ACCESS_KEY=rustfsadmin \
		amazon/aws-cli \
		--endpoint-url=http://rustfs:9000 \
		s3 mb s3://$(or $(BUCKET),test-bucket) 2>/dev/null || true
##@ Testing

test: ## Run all tests in parallel (default)
	$(PHP_ARTISAN) test --parallel

test-filter: ## Run tests with filter (usage: make test-filter FILTER=DatabaseServer)
	$(PHP_ARTISAN) test --parallel  --filter="$(FILTER)"

test-coverage: ## Run tests with coverage
	$(PHP_ARTISAN) test --parallel --coverage

test-coverage-filter: ## Run tests with coverage and filter (usage: make test-coverage-filter FILTER=FailureNotification)
	$(PHP_ARTISAN) test --parallel --coverage --filter="$(FILTER)"

test-sequential: ## Run all tests sequentially (for debugging)
	$(PHP_ARTISAN) test

##@ Code Quality

lint-check: ## Check code style with Laravel Pint
	$(PHP_EXEC) vendor/bin/pint --test

lint-fix: ## Fix code style with Laravel Pint
	$(PHP_EXEC) vendor/bin/pint

lint: lint-fix ## Alias for lint-fix

ide-helper: ## Regenerate _ide_helper_models.php (gitignored; consumed by PHPStan via scanFiles)
	$(PHP_EXEC) php artisan ide-helper:models --write-mixin --no-interaction

phpstan: ## Run PHPStan static analysis
	$(PHP_EXEC) vendor/bin/phpstan analyse --memory-limit=1G

pre-commit: lint-fix ide-helper phpstan test ## Run all pre-commit checks (lint, ide-helper, phpstan, tests)

##@ Assets

build: ## Build production assets
	$(NPM_EXEC) run build

dev-assets: ## Start Vite dev server only
	$(NPM_EXEC) run dev

##@ Documentation

docs: ## Start documentation dev server (Docusaurus)
	cd docs && $(NPM_EXEC) install && $(NPM_EXEC) run start

docs-build: ## Build documentation for production (Docusaurus)
	cd docs && $(NPM_EXEC) install && $(NPM_EXEC) run build

##@ Database

migrate-fresh: ## Drop all tables and re-migrate
	$(PHP_ARTISAN) migrate:fresh

migrate-fresh-seed: ## Drop all tables, re-migrate and seed
	$(PHP_ARTISAN) migrate:fresh --seed

db-seed: ## Run database seeders
	$(PHP_ARTISAN) db:seed

import-db: ## Import a gzipped SQL dump into local MySQL (usage: make import-db FILE=/path/to/dump.sql.gz)
	@if [ -z "$(FILE)" ]; then \
		echo "$(YELLOW)Usage: make import-db FILE=/path/to/dump.sql.gz$(NC)"; \
		exit 1; \
	fi
	@if [ ! -f "$(FILE)" ]; then \
		echo "$(YELLOW)Error: File '$(FILE)' not found$(NC)"; \
		exit 1; \
	fi
	@echo "$(GREEN)Dropping and recreating 'databasement' database...$(NC)"
	$(DOCKER_COMPOSE) exec -T mysql mysql -uroot -proot -e "DROP DATABASE IF EXISTS databasement; CREATE DATABASE databasement;"
	@echo "$(GREEN)Importing $(FILE)...$(NC)"
	gunzip -c "$(FILE)" | $(DOCKER_COMPOSE) exec -T mysql mysql -uroot -proot databasement
	@echo "$(GREEN)Import complete!$(NC)"

##@ Maintenance

clean: ## Clear all caches
	$(PHP_ARTISAN) cache:clear
	$(PHP_ARTISAN) config:clear
	$(PHP_ARTISAN) route:clear
	$(PHP_ARTISAN) view:clear

optimize: ## Optimize the application for production
	$(PHP_ARTISAN) config:cache
	$(PHP_ARTISAN) route:cache
	$(PHP_ARTISAN) view:cache

##@ Release

release: ## Create a new release (usage: make release VERSION=1.0.1)
	@if [ -z "$(VERSION)" ]; then \
		echo "$(YELLOW)Usage: make release VERSION=1.0.1$(NC)"; \
		exit 1; \
	fi
	@if [ "$$(git branch --show-current)" != "main" ]; then \
		echo "$(YELLOW)Error: You must be on the main branch to create a release.$(NC)"; \
		exit 1; \
	fi
	@git fetch origin main --quiet
	@if [ "$$(git rev-parse HEAD)" != "$$(git rev-parse origin/main)" ]; then \
		echo "$(YELLOW)Error: Local main is not up to date with origin/main. Run 'git pull' first.$(NC)"; \
		exit 1; \
	fi
	@if [ -n "$$(git status --porcelain)" ]; then \
		echo "$(YELLOW)Error: Working directory is not clean. Commit or stash changes first.$(NC)"; \
		exit 1; \
	fi
	@echo "$(GREEN)Creating release v$(VERSION)...$(NC)"
	git tag v$(VERSION)
	git push origin v$(VERSION)
	@echo "$(GREEN)Release v$(VERSION) created! Workflows will build Docker images, Helm chart, and GitHub Release.$(NC)"
