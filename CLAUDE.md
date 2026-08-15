# CLAUDE.md

Guidance for AI assistants (Claude Code and others) working in this repository.
Read this before making changes. When you learn something that contradicts or
extends what's here, update this file.

## What this is

**Bethany House E-commerce Platform (BHEP)** — an omni-channel retail + custom-tailoring
ERP for a Kenyan business (Sonalux Store, Nairobi). One Laravel backend serves four
surfaces: a REST API, a legacy Livewire admin, a modern React admin SPA, and a (mostly
unbuilt) Next.js storefront. It handles POS sales, online orders, inventory across
outlets, production/tailoring with bills-of-materials, procurement, expenses, and
reporting. Multi-currency (KES/USD) and multi-language (en/fr/pt in the data model).

**Read `docs/SYSTEM_AUDIT_AND_ROADMAP.md` before non-trivial work.** It is a ground-truth
audit (dated 2026-07) of what actually works, what is broken, and the fix order. Findings
carry stable IDs (`D1.1`, `INV-1`, `ST-1`, …) referenced in commits and PRs.

## Repository layout

```
backend/       Laravel 12 app — the source of truth (API + Livewire admin + jobs/commands)
react-admin/   Vite + React 18 TypeScript SPA — the primary staff admin (PWA)
frontend/      Next.js 16 / React 19 customer storefront — largely an empty scaffold (0%)
docker/        Nginx, PHP-FPM, and other container configs
docs/          SYSTEM_AUDIT_AND_ROADMAP.md — read this
docker-compose.yml       Production compose (GHCR images)
docker-compose.dev.yml   Local development compose
Makefile       Wraps common docker-compose + artisan workflows (run `make help`)
setup.sh       One-shot local bootstrap
```

Note the split: `composer.json`/`composer.lock`/`vendor/` at the repo root are a thin
wrapper; the real Laravel application lives in **`backend/`** with its own
`composer.json`, `artisan`, `tests/`, and migrations. Do backend work inside `backend/`.

## The four surfaces (important)

| Surface | Path | Tech | State |
|---|---|---|---|
| REST API | `backend/routes/api.php` | Laravel 12, Sanctum, Spatie Permission | live |
| React admin SPA | `react-admin/` | Vite, React 18, TanStack Query, Zustand | live, primary admin |
| Livewire admin | `backend/app/Http/Livewire/`, `routes/web.php` | Livewire 4, Blade, Alpine, Tailwind | live, **legacy/shadow UI** |
| Storefront | `frontend/` | Next.js 16 | scaffold only |

There are **two live admin UIs** (React SPA + Livewire) over the same API/DB — this is
known tech debt. New admin features generally go in the **React SPA** (`react-admin/`),
which talks to the API. Confirm which surface a change targets before starting.

## Backend architecture (`backend/`)

- **Framework:** Laravel 12, PHP 8.2+.
- **Auth:** Laravel Sanctum (Bearer tokens). Customer auth under `/api/v1/auth`, staff
  under `/api/v1/admin/auth`. 2FA is TOTP via `pragmarx/google2fa-laravel`
  (`EnsureTwoFactorIsSetup` middleware).
- **Authorization:** `spatie/laravel-permission` (RBAC). Admin API routes are guarded by
  **`permission:`** middleware (e.g. `permission:products.edit,sanctum`), not by `role:`,
  except a few super-admin-only groups (e.g. `trash`). Permissions are seeded and synced
  (`php artisan permissions:sync`, `PermissionsSeeder`, `SyncPermissions` command).
- **Routing/config:** `bootstrap/app.php` (Laravel 12 style — no HTTP Kernel file).
  Middleware aliases (`api.key`, `role`, `permission`, `ensure.2fa`), the scheduler, and
  registered console commands all live there.
- **Key directories under `backend/app/`:**
  - `Http/Controllers/Api/` — ~60 API controllers, the bulk of the logic. Some are
    god-objects (e.g. `PosController` is very large); prefer extracting to services over
    growing them.
  - `Http/Livewire/Admin/` — Livewire components for the legacy admin.
  - `Models/` — ~90 Eloquent models.
  - `Services/` — `MpesaService`, `TaxCalculationService`, `NotificationService`,
    `PdfService`, `ImageService`, `IntelligenceService`, `PermissionDependencyService`, etc.
  - `Support/` — pure logic helpers: **`OrderStatusMachine`** (legal order-status
    transitions, audit ST-1) and `SortResolver` (whitelists sort columns — guards against
    the historical `sort_by` SQL-injection).
  - `Console/Commands/` + scheduler — EoD reports, overdue-production notifications,
    activity-log purge, scheduled DB backups, intelligence checks.
  - `Jobs/`, `Events/`, `Listeners/`, `Notifications/`, `Policies/`, `Enums/`.
- **Realtime:** Laravel Reverb + Pusher protocol; `laravel-echo` on the client. Channels
  in `routes/channels.php`. Used for chat channels, comments, notifications.
- **PDF:** `barryvdh/laravel-dompdf` (invoices, reports, documents).
- **Database:** **PostgreSQL** (schema uses Postgres-specific features — generated
  columns, `inet`, JSON, CHECK constraints — so sqlite cannot run the migrations). 144+
  migrations. Seeders: `Countries`, `Permissions`, `SuperAdmin`, `DatabaseSeeder`.

### Money & inventory — handle with care

Per the audit, these are the highest-risk areas. Before touching them, read the relevant
audit findings and the existing characterization tests:

- **Inventory is split-brain:** `inventory_items` (what POS/sales deduct) and `inventories`
  (what parts of the admin read/write) can drift. Know which table a code path uses.
- **Payments/refunds/voids** must keep `payments` and `Order.payment_status` consistent.
  `Order::recalculatePaymentStatus()` derives status (`pending`/`deposit`/`partial`/`paid`)
  from non-voided payments. Don't allow over-refunding.
- **Order status transitions** must go through `OrderStatusMachine::assertCanTransition()`
  — never set `status` to an arbitrary target.
- **POS cash register** ledger (`CashRegister`, `CashRegisterTransaction`) must be written
  on cash movements for end-of-day reconciliation.

## React admin SPA (`react-admin/`)

- **Stack:** Vite, React 18, TypeScript, React Router 6, **TanStack Query** (server state),
  **Zustand** (client state), React Hook Form + Zod (forms/validation), Axios, Tailwind,
  Recharts (charts), TipTap (rich text), `laravel-echo`/`pusher-js` (realtime). PWA via
  `vite-plugin-pwa` + Workbox.
- **API client:** `src/api/client.ts` — Axios instance, base URL from `VITE_API_URL`
  (default `/api`), injects Bearer token from `localStorage` key `bh_admin_token`.
  Per-domain API modules live in `src/api/*.ts`.
- **Structure:** `src/pages/` (feature-grouped: pos, orders, inventory, production,
  procurement, expenses, reports, intelligence, setup, …), `src/components/`, `src/hooks/`,
  `src/store/`, `src/lib/`, `src/types/`.
- **Auth/guarding:** `components/auth/` — `ProtectedRoute`, `RequireAuth`, `PermissionGate`
  (client-side permission gating mirrors the API's permission middleware).
- Path alias `@/` → `src/`.

## Storefront (`frontend/`)

Next.js 16 / React 19 / Tailwind 4 scaffold. Effectively unbuilt (0% per the audit). If
asked to build storefront features, expect to create most structure from scratch and wire
it to the API (`NEXT_PUBLIC_API_URL`).

## Development workflows

Everything is containerized. `make help` lists all targets. Common ones:

```bash
make dev            # start dev environment (docker-compose)
make up / down      # start / stop containers
make migrate        # php artisan migrate
make fresh          # migrate:fresh + seed (drops all data)
make seed           # db:seed
make test           # backend: php artisan test
make shell-laravel  # shell into the Laravel container
make artisan CMD="route:list"
make composer CMD="require vendor/pkg"
make npm CMD="install"     # Next.js container
make logs-laravel / logs-nextjs
```

Docker compose **service** names: `laravel`, `queue`, `scheduler`, `reverb`, `nextjs`,
`react_admin`, `postgres`, `redis`, `nginx`, `adminer` (container names are
`bethany_*`). Note the README occasionally says `app`; the actual service is `laravel`.

Running pieces directly (inside `backend/`, without Docker):

```bash
composer install
php artisan migrate --seed
php artisan test                     # runs the suite
php artisan test --filter=SomeTest   # single test/class
composer dev                         # concurrent: serve + queue + pail logs + vite
```

Frontends:

```bash
cd react-admin && npm install && npm run dev      # Vite dev server
npm run build       # tsc && vite build
npm run type-check  # tsc --noEmit
npm run lint        # eslint, zero-warnings enforced

cd frontend && npm run dev / build / lint
```

## Testing

- **Backend uses PHPUnit** (`backend/phpunit.xml`), suites `Unit` and `Feature`. Run with
  `php artisan test` or `make test`.
- **Tests require PostgreSQL** — the DB connection comes from the environment (CI runs a
  `postgres:16` service). sqlite `:memory:` will not run the migrations. Test env forces
  `array`/`sync`/`null` drivers for cache/queue/broadcast/mail.
- Existing tests are mostly **characterization + regression** tests pinning the money/stock
  and security fixes: `PaymentReconciliationCharacterizationTest`, `PosCashLedgerTest`,
  `InventoryAdjustQuantityTest`, `InventoryLowStockEndpointTest`, `PrivilegeEscalationTest`,
  `TokenTtlTest`, `MigrationsSmokeTest`, `OrderStatusMachineTest`, `SortResolverTest`.
  Coverage is thin overall — **add tests when you fix a bug**, especially on money/stock paths.

## CI/CD & deployment

- **CI:** `.github/workflows/deploy.yml`. On every push/PR it runs the **backend test suite**
  against a Postgres service (`php artisan test`). On push to `main` it builds three images
  (laravel, nextjs, react-admin), pushes to **GHCR**, then SSHes to the Hostinger VPS,
  syncs `docker-compose.yml`, pulls images, runs migrations, and recreates containers.
- The test job is a real gate now — **keep `php artisan test` green** or the pipeline fails.
- Production runs behind Nginx (ports 80/443), with separate `queue`, `scheduler`, and
  `reverb` containers off the same Laravel image.

## Conventions

- **PHP:** PSR-4, Laravel conventions. Formatter is **Laravel Pint** (`laravel/pint` in
  dev deps) — run `./vendor/bin/pint` before committing backend changes.
- **TypeScript:** ESLint is configured with `--max-warnings 0` in react-admin; keep it
  clean and `npm run type-check` passing.
- **Comments:** the codebase favors substantial explanatory comments on non-obvious logic
  (see `OrderStatusMachine`, `client.ts`). Match that density — explain *why*, not *what*.
- **Audit IDs:** when fixing an audited issue, reference its ID (e.g. `ST-1`, `INV-1`) in
  the commit/PR so it can be tracked to closure.
- **Secrets:** never commit real secrets. `.env.example` files are templates only. The
  seeded super-admin password is a known dev-only default — do not rely on it in prod.

## Git & PR workflow

- Work happens on feature branches; PRs merge to `main` (which auto-deploys). Keep PRs
  scoped to one audited concern where possible.
- CI must pass (backend tests) before merge.
- After pushing, open a **draft** PR if one doesn't already exist for the branch. There is
  no PR template in this repo — write a clear body describing the change and any audit ID.

## Gotchas / things to verify first

- **Which admin surface?** React SPA vs. Livewire — they shadow each other. Don't fix a bug
  in one and assume the other is covered.
- **Which inventory table?** `inventory_items` vs. `inventories` — they can disagree.
- **Postgres-only** — don't switch tests or migrations to sqlite.
- **Backend lives in `backend/`**, not the repo root, despite the root `composer.json`.
- Read `docs/SYSTEM_AUDIT_AND_ROADMAP.md` for the current honest state before assuming a
  feature is complete and correct — breadth is ~85% but money/stock correctness is lower.
