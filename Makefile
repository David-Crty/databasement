.PHONY: help install start test lint-check lint-fix lint migrate migrate-fresh db-seed setup clean

# Colors for output
GREEN  := \033[0;32m
YELLOW := \033[0;33m
NC     := \033[0m # No Color

##@ Help

help: ## Display this help message
	@echo "$(GREEN)Available commands:$(NC)"
	@awk 'BEGIN {FS = ":.*##"; printf "\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  $(YELLOW)%-15s$(NC) %s\n", $$1, $$2 } /^##@/ { printf "\n$(GREEN)%s$(NC)\n", substr($$0, 5) } ' $(MAKEFILE_LIST)

##@ Development

install: ## Install dependencies (composer + npm)
	composer install
	npm install

setup: ## Full project setup (install, env, key, migrate, build)
	composer setup

start: ## Start development server (all services: server, queue, logs, vite)
	composer dev

##@ Testing

test: ## Run all tests
	php artisan test

test-filter: ## Run tests with filter (usage: make test-filter FILTER=DatabaseServer)
	php artisan test --filter=$(FILTER)

test-coverage: ## Run tests with coverage
	php artisan test --coverage

##@ Code Quality

lint-check: ## Check code style with Laravel Pint
	vendor/bin/pint --test

lint-fix: ## Fix code style with Laravel Pint
	vendor/bin/pint

lint: lint-fix ## Alias for lint-fix

phpstan: ## Run PHPStan static analysis
	vendor/bin/phpstan analyse --memory-limit=1G

phpstan-baseline: ## Generate PHPStan baseline
	vendor/bin/phpstan analyse --memory-limit=1G --generate-baseline

analyse: phpstan ## Alias for phpstan

##@ Database

migrate: ## Run database migrations
	php artisan migrate

migrate-fresh: ## Fresh migration (drops all tables)
	php artisan migrate:fresh

migrate-fresh-seed: ## Fresh migration with seeders
	php artisan migrate:fresh --seed

db-seed: ## Run database seeders
	php artisan db:seed

##@ Assets

build: ## Build production assets
	npm run build

dev-assets: ## Start Vite dev server only
	npm run dev

##@ Maintenance

clean: ## Clear all caches
	php artisan cache:clear
	php artisan config:clear
	php artisan route:clear
	php artisan view:clear

optimize: ## Optimize the application for production
	php artisan config:cache
	php artisan route:cache
	php artisan view:cache

##@ Utilities

tinker: ## Open Laravel Tinker REPL
	php artisan tinker

queue: ## Start queue worker
	php artisan queue:listen --tries=1

logs: ## Tail application logs with Pail
	php artisan pail --timeout=0
