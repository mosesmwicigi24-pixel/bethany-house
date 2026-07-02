.PHONY: help build up down restart logs ps shell-laravel shell-nextjs shell-postgres migrate migrate-fresh seed fresh install composer npm test clean prod dev

# Default target
.DEFAULT_GOAL := help

# Colors for output
COLOR_RESET = \033[0m
COLOR_INFO = \033[36m
COLOR_SUCCESS = \033[32m
COLOR_WARNING = \033[33m

# Variables
COMPOSE = docker-compose
COMPOSE_DEV = docker-compose -f docker-compose.yml
COMPOSE_PROD = docker-compose -f docker-compose.yml
LARAVEL_CONTAINER = bethany_laravel
NEXTJS_CONTAINER = bethany_nextjs
POSTGRES_CONTAINER = bethany_postgres

help: ## Show this help message
	@echo "$(COLOR_INFO)Bethany House E-commerce Platform - Docker Management$(COLOR_RESET)"
	@echo ""
	@echo "$(COLOR_SUCCESS)Available commands:$(COLOR_RESET)"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(COLOR_INFO)%-20s$(COLOR_RESET) %s\n", $$1, $$2}'

## Development Commands
dev: ## Start development environment
	@echo "$(COLOR_INFO)Starting development environment...$(COLOR_RESET)"
	@cp -n .env.example .env 2>/dev/null || true
	@$(COMPOSE_DEV) up -d
	@echo "$(COLOR_SUCCESS)Development environment started!$(COLOR_RESET)"
	@$(MAKE) --no-print-directory status

build: ## Build all Docker images
	@echo "$(COLOR_INFO)Building Docker images...$(COLOR_RESET)"
	@$(COMPOSE) build --no-cache
	@echo "$(COLOR_SUCCESS)Build completed!$(COLOR_RESET)"

up: ## Start all containers
	@echo "$(COLOR_INFO)Starting containers...$(COLOR_RESET)"
	@$(COMPOSE) up -d
	@echo "$(COLOR_SUCCESS)Containers started!$(COLOR_RESET)"

down: ## Stop all containers
	@echo "$(COLOR_WARNING)Stopping containers...$(COLOR_RESET)"
	@$(COMPOSE) down
	@echo "$(COLOR_SUCCESS)Containers stopped!$(COLOR_RESET)"

restart: ## Restart all containers
	@$(MAKE) --no-print-directory down
	@$(MAKE) --no-print-directory up

stop: ## Stop containers without removing them
	@echo "$(COLOR_WARNING)Stopping containers...$(COLOR_RESET)"
	@$(COMPOSE) stop

start: ## Start stopped containers
	@echo "$(COLOR_INFO)Starting stopped containers...$(COLOR_RESET)"
	@$(COMPOSE) start

status: ## Show container status
	@echo "$(COLOR_INFO)Container Status:$(COLOR_RESET)"
	@$(COMPOSE) ps

logs: ## Show logs from all containers
	@$(COMPOSE) logs -f

logs-laravel: ## Show Laravel logs
	@$(COMPOSE) logs -f laravel

logs-nextjs: ## Show Next.js logs
	@$(COMPOSE) logs -f nextjs

logs-nginx: ## Show Nginx logs
	@$(COMPOSE) logs -f nginx

## Shell Access
shell-laravel: ## Access Laravel container shell
	@echo "$(COLOR_INFO)Accessing Laravel container...$(COLOR_RESET)"
	@$(COMPOSE) exec laravel /bin/sh

shell-nextjs: ## Access Next.js container shell
	@echo "$(COLOR_INFO)Accessing Next.js container...$(COLOR_RESET)"
	@$(COMPOSE) exec nextjs /bin/sh

shell-postgres: ## Access PostgreSQL container shell
	@echo "$(COLOR_INFO)Accessing PostgreSQL container...$(COLOR_RESET)"
	@$(COMPOSE) exec postgres psql -U bethany_user -d bethany_house

shell-redis: ## Access Redis CLI
	@echo "$(COLOR_INFO)Accessing Redis CLI...$(COLOR_RESET)"
	@$(COMPOSE) exec redis redis-cli -a changeme

## Laravel Commands
install: ## Install Laravel dependencies and setup
	@echo "$(COLOR_INFO)Installing Laravel dependencies...$(COLOR_RESET)"
	@$(COMPOSE) exec laravel composer install
	@$(COMPOSE) exec laravel php artisan key:generate
	@$(COMPOSE) exec laravel php artisan storage:link
	@echo "$(COLOR_SUCCESS)Laravel installation completed!$(COLOR_RESET)"

composer: ## Run composer command (usage: make composer CMD="install package/name")
	@$(COMPOSE) exec laravel composer $(CMD)

migrate: ## Run database migrations
	@echo "$(COLOR_INFO)Running migrations...$(COLOR_RESET)"
	@$(COMPOSE) exec laravel php artisan migrate
	@echo "$(COLOR_SUCCESS)Migrations completed!$(COLOR_RESET)"

migrate-fresh: ## Fresh migration (WARNING: drops all tables)
	@echo "$(COLOR_WARNING)Running fresh migrations (this will drop all tables)...$(COLOR_RESET)"
	@$(COMPOSE) exec laravel php artisan migrate:fresh
	@echo "$(COLOR_SUCCESS)Fresh migrations completed!$(COLOR_RESET)"

seed: ## Run database seeders
	@echo "$(COLOR_INFO)Seeding database...$(COLOR_RESET)"
	@$(COMPOSE) exec laravel php artisan db:seed
	@echo "$(COLOR_SUCCESS)Database seeded!$(COLOR_RESET)"

fresh: migrate-fresh seed ## Fresh migration with seeding

artisan: ## Run artisan command (usage: make artisan CMD="route:list")
	@$(COMPOSE) exec laravel php artisan $(CMD)

tinker: ## Run Laravel Tinker
	@$(COMPOSE) exec laravel php artisan tinker

cache-clear: ## Clear all Laravel caches
	@echo "$(COLOR_INFO)Clearing caches...$(COLOR_RESET)"
	@$(COMPOSE) exec laravel php artisan cache:clear
	@$(COMPOSE) exec laravel php artisan config:clear
	@$(COMPOSE) exec laravel php artisan route:clear
	@$(COMPOSE) exec laravel php artisan view:clear
	@echo "$(COLOR_SUCCESS)Caches cleared!$(COLOR_RESET)"

optimize: ## Optimize Laravel
	@echo "$(COLOR_INFO)Optimizing Laravel...$(COLOR_RESET)"
	@$(COMPOSE) exec laravel php artisan config:cache
	@$(COMPOSE) exec laravel php artisan route:cache
	@$(COMPOSE) exec laravel php artisan view:cache
	@echo "$(COLOR_SUCCESS)Optimization completed!$(COLOR_RESET)"

queue-work: ## Start queue worker
	@$(COMPOSE) exec laravel php artisan queue:work

queue-restart: ## Restart queue workers
	@$(COMPOSE) exec laravel php artisan queue:restart

## Next.js Commands
npm: ## Run npm command in Next.js (usage: make npm CMD="install package")
	@$(COMPOSE) exec nextjs npm $(CMD)

npm-install: ## Install Next.js dependencies
	@echo "$(COLOR_INFO)Installing Next.js dependencies...$(COLOR_RESET)"
	@$(COMPOSE) exec nextjs npm install
	@echo "$(COLOR_SUCCESS)Dependencies installed!$(COLOR_RESET)"

npm-build: ## Build Next.js for production
	@echo "$(COLOR_INFO)Building Next.js...$(COLOR_RESET)"
	@$(COMPOSE) exec nextjs npm run build
	@echo "$(COLOR_SUCCESS)Build completed!$(COLOR_RESET)"

## Database Commands
db-backup: ## Backup database
	@echo "$(COLOR_INFO)Creating database backup...$(COLOR_RESET)"
	@mkdir -p backups
	@$(COMPOSE) exec postgres pg_dump -U bethany_user bethany_house > backups/backup_$$(date +%Y%m%d_%H%M%S).sql
	@echo "$(COLOR_SUCCESS)Database backup created!$(COLOR_RESET)"

db-restore: ## Restore database from backup (usage: make db-restore FILE=backups/backup.sql)
	@echo "$(COLOR_WARNING)Restoring database from $(FILE)...$(COLOR_RESET)"
	@$(COMPOSE) exec -T postgres psql -U bethany_user -d bethany_house < $(FILE)
	@echo "$(COLOR_SUCCESS)Database restored!$(COLOR_RESET)"

## Testing Commands
test: ## Run Laravel tests
	@echo "$(COLOR_INFO)Running tests...$(COLOR_RESET)"
	@$(COMPOSE) exec laravel php artisan test

test-coverage: ## Run tests with coverage
	@echo "$(COLOR_INFO)Running tests with coverage...$(COLOR_RESET)"
	@$(COMPOSE) exec laravel php artisan test --coverage

## Production Commands
prod: ## Start production environment
	@echo "$(COLOR_INFO)Starting production environment...$(COLOR_RESET)"
	@APP_ENV=production $(COMPOSE_PROD) up -d
	@echo "$(COLOR_SUCCESS)Production environment started!$(COLOR_RESET)"

prod-build: ## Build production images
	@echo "$(COLOR_INFO)Building production images...$(COLOR_RESET)"
	@APP_ENV=production $(COMPOSE_PROD) build --no-cache
	@echo "$(COLOR_SUCCESS)Production build completed!$(COLOR_RESET)"

deploy: prod-build ## Deploy to production (build and start)
	@$(MAKE) --no-print-directory prod
	@$(MAKE) --no-print-directory migrate
	@$(MAKE) --no-print-directory optimize

## Cleanup Commands
clean: ## Remove all containers, volumes, and images
	@echo "$(COLOR_WARNING)Cleaning up Docker resources...$(COLOR_RESET)"
	@$(COMPOSE) down -v --remove-orphans
	@docker system prune -af --volumes
	@echo "$(COLOR_SUCCESS)Cleanup completed!$(COLOR_RESET)"

clean-volumes: ## Remove all volumes (WARNING: deletes all data)
	@echo "$(COLOR_WARNING)Removing all volumes...$(COLOR_RESET)"
	@$(COMPOSE) down -v
	@echo "$(COLOR_SUCCESS)Volumes removed!$(COLOR_RESET)"

## Monitoring
monitor: ## Show resource usage
	@docker stats $(docker ps --format '{{.Names}}' | grep bethany)

ps: ## Show running containers
	@$(COMPOSE) ps

top: ## Show running processes
	@$(COMPOSE) top
