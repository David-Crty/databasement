.PHONY: help install start test test-mysql test-postgres test-filter test-filter-mysql test-filter-postgres test-coverage backup-test lint-check lint-fix lint migrate migrate-fresh db-seed setup clean import-db docs-build

# Colors for output
GREEN  := \033[0;32m
YELLOW := \033[0;33m
NC     := \033[0m # No Color

# Docker / PHP helpers
DOCKER_COMPOSE := docker compose
PHP_SERVICE    := app
PHP_EXEC       := $(DOCKER_COMPOSE) exec -T $(PHP_SERVICE)
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

create-bucket: ## Create S3 bucket in LocalStack (usage: make create-bucket BUCKET=my-bucket)
	$(DOCKER_COMPOSE) exec -T localstack awslocal s3 mb s3://$(or $(BUCKET),test-bucket)
##@ Testing

test: ## Run all tests (SQLite)
	$(PHP_ARTISAN) test

test-mysql: ## Run all tests with MySQL
	$(DOCKER_COMPOSE) exec -T mysql mysql -uroot -proot -e "DROP DATABASE IF EXISTS databasement_app_test; CREATE DATABASE databasement_app_test;" 2>/dev/null || true
	$(DOCKER_COMPOSE) exec -T -e EXTRA_ENV_FILE=.env.mysql.testing $(PHP_SERVICE) php artisan test

test-filter: ## Run tests with filter (usage: make test-filter FILTER=DatabaseServer)
	$(PHP_ARTISAN) test --filter=$(FILTER)

test-filter-mysql: ## Run tests with filter using MySQL (usage: make test-filter-mysql FILTER=DatabaseServer)
	$(DOCKER_COMPOSE) exec -T mysql mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS databasement_app_test;" 2>/dev/null || true
	$(DOCKER_COMPOSE) exec -T -e EXTRA_ENV_FILE=.env.mysql.testing $(PHP_SERVICE) php artisan test --filter=$(FILTER)

test-postgres: ## Run all tests with PostgreSQL
	$(DOCKER_COMPOSE) exec -T postgres psql -U root -d postgres -c "DROP DATABASE IF EXISTS databasement_app_test;"
	$(DOCKER_COMPOSE) exec -T postgres psql -U root -d postgres -c "CREATE DATABASE databasement_app_test;"
	$(DOCKER_COMPOSE) exec -T -e EXTRA_ENV_FILE=.env.postgres.testing $(PHP_SERVICE) php artisan test

test-filter-postgres: ## Run tests with filter using PostgreSQL (usage: make test-filter-postgres FILTER=DatabaseServer)
	$(DOCKER_COMPOSE) exec -T postgres psql -U root -d postgres -c "DROP DATABASE IF EXISTS databasement_app_test;"
	$(DOCKER_COMPOSE) exec -T postgres psql -U root -d postgres -c "CREATE DATABASE databasement_app_test;"
	$(DOCKER_COMPOSE) exec -T -e EXTRA_ENV_FILE=.env.postgres.testing $(PHP_SERVICE) php artisan test --filter=$(FILTER)

test-coverage: ## Run tests with coverage
	$(PHP_ARTISAN) test --coverage

##@ Code Quality

lint-check: ## Check code style with Laravel Pint
	$(PHP_EXEC) vendor/bin/pint --test

lint-fix: ## Fix code style with Laravel Pint
	$(PHP_EXEC) vendor/bin/pint

lint: lint-fix ## Alias for lint-fix

phpstan: ## Run PHPStan static analysis
	$(PHP_EXEC) vendor/bin/phpstan analyse --memory-limit=1G

pre-commit: lint-fix phpstan test

##@ Assets

build: ## Build production assets
	$(NPM_EXEC) run build

dev-assets: ## Start Vite dev server only
	$(NPM_EXEC) run dev

##@ Documentation

docs-build: ## Build documentation (Docusaurus)
	cd docs && $(NPM_EXEC) run build

##@ Database

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
