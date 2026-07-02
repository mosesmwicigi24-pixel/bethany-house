# Bethany House E-commerce Platform

A high-performance, omni-channel retail system for Bethany House - combining an online storefront, a Livewire-powered admin dashboard, and a Point-of-Sale (POS) interface for physical outlets, all backed by a unified Laravel API.

---

## Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)
- [Tech Stack](#tech-stack)
- [Features](#features)
- [User Roles](#user-roles)
- [Prerequisites](#prerequisites)
- [Getting Started](#getting-started)
- [Environment Variables](#environment-variables)
- [Running with Docker](#running-with-docker)
- [Development Setup](#development-setup)
- [Running Tests](#running-tests)
- [CI/CD Pipeline](#cicd-pipeline)
- [Deployment](#deployment)
- [Project Structure](#project-structure)
- [Contributing](#contributing)

---

## Overview

The Bethany House E-commerce Platform (BHEP) is a headless, API-driven system that powers both online and in-store retail operations from a single source of truth. Online customers and in-store clerks interact with the same inventory and order database in real time, ensuring consistency across all channels.

Key highlights:

- **Headless architecture** - Laravel REST API backend consumed by a Next.js storefront and a Livewire admin dashboard
- **Omni-channel** - unified inventory, orders, and reporting across the web store and physical outlets
- **Multi-language** - English (default), French, and Portuguese; extensible for additional locales
- **Multi-currency** - Kenyan Shillings (KES) and US Dollars (USD), with automatic locale-based selection
- **Containerised** - Docker Compose for local development and production parity
- **CI/CD** - automated test, build, and deploy pipeline via GitHub Actions

---

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    Cloud Linux Server                   │
│                                                         │
│  ┌─────────────┐    ┌──────────────────────────────┐   │
│  │  Next.js    │    │       Laravel API + Admin     │   │
│  │  Storefront │◄──►│   (REST API + Livewire UI)    │   │
│  │  (Node.js)  │    │                              │   │
│  └─────────────┘    └──────────────┬───────────────┘   │
│                                    │                    │
│                     ┌──────────────▼───────────────┐   │
│                     │        PostgreSQL DB          │   │
│                     └──────────────────────────────┘   │
│                                                         │
│  All containers managed by Docker Compose               │
│  Reverse proxy via Nginx (ports 80/443 only exposed)    │
└─────────────────────────────────────────────────────────┘
         │                              │
   Customers (HTTPS)            Staff / Admin (HTTPS)
   (web & mobile)          (admin.bethanyhouse.co.ke)
```

External integrations: M-Pesa · Paystack · Flutterwave · SendGrid (email)

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend API | Laravel (PHP), Sanctum (auth), Spatie Permissions (RBAC) |
| Admin Dashboard | Laravel Livewire, Blade, Alpine.js, Tailwind CSS |
| Frontend Storefront | Next.js (React), SSR/ISR, Tailwind CSS |
| Database | PostgreSQL |
| Containerisation | Docker, Docker Compose |
| CI/CD | GitHub Actions |
| Payments | M-Pesa (Safaricom), Paystack, Flutterwave |
| Email | SMTP / SendGrid |

---

## Features

### Customer Storefront (Next.js)
- Server-side rendering (SSR) and Incremental Static Regeneration (ISR) for fast, SEO-optimised pages
- Multi-language UI with Next.js i18n routing (`/en/`, `/fr/`, `/pt/`)
- Currency auto-detection by IP geolocation with manual override
- Product browsing, search, filtering by category and variant
- Shopping cart and guest or authenticated checkout
- Payment via M-Pesa, card (Paystack/Flutterwave), and more
- Customer accounts: order history, address book, profile management

### Admin Dashboard (Livewire)
- Role-based access - each user sees only the modules they're permitted to use
- **Product management** - CRUD, multi-language fields, dual-currency pricing, variants (size/colour), images, SEO metadata, bulk import/export
- **Inventory management** - real-time stock levels per outlet, low-stock alerts, stock transfers
- **Order management** - order lifecycle (pending → processing → shipped → delivered), returns, refunds, invoice generation
- **Production module** - bill of materials (BOM), tailor task assignment, work-in-progress tracking, material consumption logging
- **Procurement** - supplier purchase orders, goods-received notes, raw material inventory
- **Reporting** - sales reports, P&L, inventory valuation, outlet-specific summaries
- **User & role management** - create/edit/disable users, customisable role-permission matrix, 2FA (TOTP) for admin accounts, audit trail
- **Content management** - static pages (About, Terms, Privacy), banners
- **Settings** - payment gateway credentials, shipping zones, tax rates, email templates, locale configuration

### POS Module
- Product search by name or SKU
- Add-to-cart with quantity adjustment and discount application
- Multiple payment methods per sale: Cash, M-Pesa, Card; split payments supported
- Customer creation/lookup at point of sale
- Thermal receipt printing
- Cash register open/close with end-of-day reconciliation
- In-store returns and store-credit issuance

---

## User Roles

| Role | Access |
|---|---|
| **Super Admin** | Full system access including settings, user management, and all modules |
| **Admin** | Core e-commerce functions: products, orders, inventory, production, reports |
| **Outlet Manager** | POS, outlet-specific inventory and sales reports, day-end reconciliation |
| **POS Clerk** | POS interface only - create sales, view their outlet's stock |
| **Tailor** | Production module only - view assigned tasks, update stage status, log materials |
| **Customer** | Storefront - order history, profile, address management |

Roles are fully configurable by Super Admin via the permissions matrix.

---

## Prerequisites

Ensure the following are installed on your machine:

- [Docker](https://docs.docker.com/get-docker/) ≥ 24
- [Docker Compose](https://docs.docker.com/compose/) ≥ 2
- [Git](https://git-scm.com/)
- (Optional, for local dev without Docker) PHP ≥ 8.2, Node.js ≥ 20, Composer ≥ 2, PostgreSQL ≥ 15

---

## Getting Started

```bash
# 1. Clone the repository
git clone https://github.com/your-org/bethany-house.git
cd bethany-house

# 2. Copy environment files
cp .env.example .env
cp frontend/.env.example frontend/.env.local

# 3. Build and start all containers
docker compose up -d --build

# 4. Install PHP dependencies & generate app key
docker compose exec app composer install
docker compose exec app php artisan key:generate

# 5. Run database migrations and seeders
docker compose exec app php artisan migrate --seed

# 6. Link storage
docker compose exec app php artisan storage:link
```

The services will be available at:

| Service | URL |
|---|---|
| Storefront (Next.js) | http://localhost:3000 |
| Admin Dashboard | http://localhost:8000/admin |
| Laravel API | http://localhost:8000/api |
| PostgreSQL | localhost:5432 |

Default Super Admin credentials (seeded):

```
Email:    superadmin@bethanyhouse.co.ke
Password: password   ← change immediately after first login
```

---

## Environment Variables

### Laravel (`/.env`)

```dotenv
APP_NAME="Bethany House"
APP_ENV=local
APP_KEY=                        # generated by artisan key:generate
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=bethany_house
DB_USERNAME=postgres
DB_PASSWORD=secret

# M-Pesa
MPESA_CONSUMER_KEY=
MPESA_CONSUMER_SECRET=
MPESA_SHORTCODE=
MPESA_PASSKEY=
MPESA_ENV=sandbox               # sandbox | production

# Paystack
PAYSTACK_SECRET_KEY=
PAYSTACK_PUBLIC_KEY=

# Flutterwave
FLUTTERWAVE_SECRET_KEY=
FLUTTERWAVE_PUBLIC_KEY=

# Email
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=noreply@bethanyhouse.co.ke
```

### Next.js (`/frontend/.env.local`)

```dotenv
NEXT_PUBLIC_API_URL=http://localhost:8000/api
NEXT_PUBLIC_SITE_URL=http://localhost:3000
NEXT_PUBLIC_DEFAULT_LOCALE=en
NEXT_PUBLIC_DEFAULT_CURRENCY=KES
```

> **Never commit real secrets.** Use `.env.example` files as templates only.

---

## Running with Docker

```bash
# Start all services in the background
docker compose up -d

# View logs
docker compose logs -f

# Stop all services
docker compose down

# Stop and remove volumes (wipes database)
docker compose down -v
```

### Docker services

| Service | Description |
|---|---|
| `app` | Laravel PHP-FPM application |
| `nginx` | Reverse proxy (ports 80 / 443) |
| `nextjs` | Next.js storefront (Node.js) |
| `db` | PostgreSQL database |
| `queue` | Laravel queue worker |
| `scheduler` | Laravel task scheduler (cron) |

---

## Development Setup

### Backend (Laravel)

```bash
# Run artisan commands inside the container
docker compose exec app php artisan <command>

# Example: create a migration
docker compose exec app php artisan make:migration create_products_table

# Run migrations
docker compose exec app php artisan migrate

# Seed the database
docker compose exec app php artisan db:seed

# Clear caches
docker compose exec app php artisan optimize:clear
```

### Frontend (Next.js)

```bash
# Install dependencies (first time or after package.json changes)
docker compose exec nextjs npm install

# The dev server hot-reloads automatically; or run locally:
cd frontend
npm install
npm run dev
```

### Livewire Admin

The admin dashboard runs within the Laravel container. No additional build step is required - Livewire components are server-rendered. For Tailwind CSS compilation during development:

```bash
docker compose exec app npm run dev
```

---

## Running Tests

### Laravel (PHPUnit)

```bash
# Run the full test suite
docker compose exec app php artisan test

# Run a specific test file
docker compose exec app php artisan test --filter=ProductTest

# With coverage (requires Xdebug or PCOV)
docker compose exec app php artisan test --coverage
```

### Next.js (Jest)

```bash
docker compose exec nextjs npm test

# Watch mode
docker compose exec nextjs npm test -- --watch
```

### Laravel Dusk (browser tests)

```bash
docker compose exec app php artisan dusk
```

Target test coverage: **≥ 80%** across unit, feature, and API tests.

---

## CI/CD Pipeline

GitHub Actions runs on every push and pull request:

1. **Test** - PHPUnit (Laravel) + Jest (Next.js)
2. **Build** - Docker images tagged with the commit SHA and pushed to the container registry
3. **Deploy** - SSH into the production server, pull latest images, run `docker compose up -d`, execute pending migrations

Branches:

- `main` → production deployment (protected; requires PR review)
- `staging` → staging deployment
- Feature branches → test and build only

Secrets required in GitHub repository settings: `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`, `REGISTRY_TOKEN`, and all payment/email credentials.

---

## Deployment

```bash
# On the production server (first-time setup)
git clone https://github.com/your-org/bethany-house.git /var/www/bethany-house
cd /var/www/bethany-house
cp .env.example .env   # fill in production values
docker compose -f docker-compose.yml up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
docker compose exec app php artisan storage:link

# Subsequent deploys (handled automatically by GitHub Actions, or manually)
docker compose pull
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan optimize
```

SSL certificates are managed by Certbot / Let's Encrypt on the host and mounted into the Nginx container.

---

## Project Structure

```
bethany-house/
├── app/                    # Laravel application (Models, Controllers, Services)
├── bootstrap/
├── config/
├── database/
│   ├── migrations/
│   └── seeders/
├── frontend/               # Next.js storefront
│   ├── components/
│   ├── pages/
│   ├── public/
│   └── styles/
├── resources/
│   ├── views/              # Blade templates & Livewire components
│   └── lang/               # Translation files (en, fr, pt)
├── routes/
│   ├── api.php
│   └── web.php
├── storage/
├── tests/
│   ├── Feature/
│   └── Unit/
├── .github/
│   └── workflows/          # GitHub Actions CI/CD
├── docker-compose.yml
├── Dockerfile
├── .env.example
├── .gitignore
└── README.md
```

---

## Contributing

1. Fork the repository and create a feature branch: `git checkout -b feature/your-feature`
2. Follow Laravel coding standards and PSR-12 for PHP; ESLint + Prettier for JavaScript/TypeScript
3. Write tests for all new functionality (aim for full coverage of new code)
4. Run the full test suite before opening a PR
5. Submit a pull request against the `staging` branch with a clear description of the change


---

> Built for Bethany House · Kenya 🇰🇪