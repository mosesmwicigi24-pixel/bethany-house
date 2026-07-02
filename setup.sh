#!/bin/bash

# Bethany House Platform - Automated Setup Script
# This script automates the initial setup of the platform

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Functions
print_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

check_command() {
    if ! command -v $1 &> /dev/null; then
        print_error "$1 is not installed. Please install it first."
        exit 1
    fi
}

generate_password() {
    openssl rand -base64 32 | tr -d "=+/" | cut -c1-25
}

# Banner
echo "
╔══════════════════════════════════════════════════════════╗
║                                                          ║
║           Bethany House E-commerce Platform             ║
║              Automated Setup Script                     ║
║                                                          ║
╚══════════════════════════════════════════════════════════╝
"

# Check prerequisites
print_info "Checking prerequisites..."
check_command docker
check_command docker-compose
check_command git

# Check Docker is running
if ! docker info > /dev/null 2>&1; then
    print_error "Docker is not running. Please start Docker first."
    exit 1
fi

print_success "All prerequisites are met!"

# Environment setup
print_info "Setting up environment..."

if [ ! -f .env ]; then
    print_info "Creating .env file from template..."
    cp .env.example .env
    
    # Generate secure passwords
    DB_PASSWORD=$(generate_password)
    REDIS_PASSWORD=$(generate_password)
    
    # Update .env with generated passwords
    sed -i "s/DB_PASSWORD=changeme/DB_PASSWORD=$DB_PASSWORD/" .env
    sed -i "s/REDIS_PASSWORD=changeme_secure_redis_password/REDIS_PASSWORD=$REDIS_PASSWORD/" .env
    
    print_success ".env file created with secure passwords"
    print_warning "Please update payment gateway credentials in .env before deployment"
else
    print_warning ".env file already exists. Skipping creation."
fi

# Ask for environment type
echo ""
read -p "Select environment type [dev/prod] (default: dev): " ENV_TYPE
ENV_TYPE=${ENV_TYPE:-dev}

if [ "$ENV_TYPE" = "prod" ]; then
    export APP_ENV=production
    export NODE_ENV=production
    print_info "Setting up PRODUCTION environment"
    
    # Additional production checks
    read -p "Have you configured SSL certificates? [y/N]: " SSL_CONFIGURED
    if [ "$SSL_CONFIGURED" != "y" ]; then
        print_warning "SSL certificates not configured. Please set up SSL before deploying to production."
        print_info "See DEPLOYMENT.md for SSL setup instructions."
    fi
else
    export APP_ENV=development
    export NODE_ENV=development
    print_info "Setting up DEVELOPMENT environment"
fi

# Create necessary directories
print_info "Creating necessary directories..."
mkdir -p docker/nginx/ssl
mkdir -p docker/nginx/conf.d/includes
mkdir -p docker/postgres/init
mkdir -p docker/php/conf.d
mkdir -p docker/php/php-fpm.d
mkdir -p docker/supervisor
mkdir -p backups
print_success "Directories created"

# Build Docker images
print_info "Building Docker images... (this may take several minutes)"
docker-compose build --no-cache

print_success "Docker images built successfully"

# Start services
print_info "Starting Docker containers..."
docker-compose up -d

print_success "Containers started"

# Wait for services to be ready
print_info "Waiting for services to be ready..."
sleep 15

# Check if containers are running
RUNNING=$(docker-compose ps --services --filter "status=running" | wc -l)
TOTAL=$(docker-compose ps --services | wc -l)

if [ "$RUNNING" -eq "$TOTAL" ]; then
    print_success "All containers are running"
else
    print_warning "Some containers may not be running. Check with: docker-compose ps"
fi

# Laravel setup
print_info "Setting up Laravel application..."

# Install dependencies
print_info "Installing Composer dependencies..."
docker-compose exec -T laravel composer install --no-interaction

# Generate application key if not set
if ! grep -q "APP_KEY=base64:" .env; then
    print_info "Generating application key..."
    docker-compose exec -T laravel php artisan key:generate
fi

# Create storage link
print_info "Creating storage symbolic link..."
docker-compose exec -T laravel php artisan storage:link

# Run migrations
read -p "Run database migrations? [Y/n]: " RUN_MIGRATIONS
RUN_MIGRATIONS=${RUN_MIGRATIONS:-Y}

if [ "$RUN_MIGRATIONS" = "Y" ] || [ "$RUN_MIGRATIONS" = "y" ]; then
    print_info "Running database migrations..."
    docker-compose exec -T laravel php artisan migrate --force
    print_success "Migrations completed"
    
    # Ask about seeding
    if [ "$ENV_TYPE" = "dev" ]; then
        read -p "Seed database with sample data? [Y/n]: " SEED_DB
        SEED_DB=${SEED_DB:-Y}
        
        if [ "$SEED_DB" = "Y" ] || [ "$SEED_DB" = "y" ]; then
            print_info "Seeding database..."
            docker-compose exec -T laravel php artisan db:seed
            print_success "Database seeded"
        fi
    fi
fi

# Next.js setup
if [ -d "frontend" ]; then
    print_info "Setting up Next.js application..."
    
    # Install dependencies
    print_info "Installing npm dependencies..."
    docker-compose exec -T nextjs npm install
    print_success "npm dependencies installed"
fi

# Clear and optimize caches
if [ "$ENV_TYPE" = "prod" ]; then
    print_info "Optimizing application for production..."
    docker-compose exec -T laravel php artisan config:cache
    docker-compose exec -T laravel php artisan route:cache
    docker-compose exec -T laravel php artisan view:cache
    print_success "Application optimized"
fi

# Create initial admin user (for development)
if [ "$ENV_TYPE" = "dev" ]; then
    read -p "Create admin user? [Y/n]: " CREATE_ADMIN
    CREATE_ADMIN=${CREATE_ADMIN:-Y}
    
    if [ "$CREATE_ADMIN" = "Y" ] || [ "$CREATE_ADMIN" = "y" ]; then
        print_info "Creating admin user..."
        # This assumes you have a command to create admin user
        # docker-compose exec -T laravel php artisan make:admin
        print_warning "Please create admin user manually or via seeder"
    fi
fi

# Display access information
echo ""
print_success "═══════════════════════════════════════════════════════"
print_success "Setup completed successfully!"
print_success "═══════════════════════════════════════════════════════"
echo ""
print_info "Access your application at:"
echo "  Frontend:    http://localhost"
echo "  Admin:       http://localhost/admin"
echo "  API:         http://localhost/api"
if [ "$ENV_TYPE" = "dev" ]; then
    echo "  Adminer:     http://localhost:8080"
fi
echo ""
print_info "Useful commands:"
echo "  View logs:           make logs"
echo "  Stop containers:     make down"
echo "  Restart:             make restart"
echo "  Run migrations:      make migrate"
echo "  Access shell:        make shell-laravel"
echo "  View all commands:   make help"
echo ""

if [ "$ENV_TYPE" = "prod" ]; then
    print_warning "PRODUCTION CHECKLIST:"
    echo "  [ ] Configure SSL certificates"
    echo "  [ ] Update payment gateway credentials"
    echo "  [ ] Configure email settings"
    echo "  [ ] Set up automated backups"
    echo "  [ ] Configure monitoring"
    echo "  [ ] Review security settings"
    echo ""
    print_info "See DEPLOYMENT.md for detailed production setup guide"
fi

print_info "For detailed documentation, see README.md"
echo ""
