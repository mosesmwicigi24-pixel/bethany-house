# Customer country-of-origin + canonical E.164 phone storage

**Branch:** `feat/customer-country-e164` (off `main`). `main` auto-deploys to prod
(entrypoint runs `php artisan migrate --force`), so this stays isolated until it's a
tested, deployable unit, then fuses to `main`.

## Why

Customer phones are stored in mixed formats (~85% local `0712…`, some `+254…`,
`254…`), with **no country** recorded on the customer. This blocks reliable
identity matching between walk-in/POS customers and the WhatsApp agent (Neema),
and — critically — matching on trailing digits **collides across countries**
(Kenyan `+254 712…` vs Ugandan `+256 712…` share the last 9 digits). Bethany House
takes international orders, so the country code is the disambiguator.

**Fix at the source:** capture country of origin at contact entry (a dropdown,
default Kenya) and store the number canonically as **E.164 (`+254712345678`)**, so
downstream matching is an exact, collision-free comparison.

## Scope (agreed: entry + storage + backfill)

### 1. Schema
- Add `customers.country_code` (CHAR(2), ISO-3166 alpha-2, nullable; default `KE`
  is reasonable for the home market). Migration auto-runs on deploy.
- `orders` already has `customer_country_code` + `is_international` — reuse.

### 2. Backend — canonical storage (Laravel)
- Add **`giggsey/libphonenumber-for-php`** (NOT currently installed — `composer
  require`). A small `PhoneService::toE164($raw, $iso)` wrapper.
- Normalize on every save path:
  - `CustomerController::store` and `quickCreate` (validation + create).
  - `PosController` new-customer path (pending-order `new_customer`).
  - Order intake `customer_phone` (+ set `customer_country_code`).
- Store E.164 + the ISO country on the customer.

### 3. Frontend — country dropdown (react-admin/, Vite/React)
- POS add-customer flow: `react-admin/src/pages/pos/` (PosPage + customer modal).
- Admin customer create/edit form (customers pages).
- Add a country selector (default 🇰🇪 Kenya +254, searchable). Reuse any existing
  country list/select in the codebase if present; else add one (ISO2 + calling code
  + flag). The `/v1/countries` API reportedly returns `phone_code` — verify and reuse.
- API payloads: `react-admin/src/api/customers.ts`, `pos.ts` — add `country_code`.

### 4. Backfill (one-time, careful, reversible)
- Artisan command `customers:normalize-phones` — **dry-run by default**, `--commit`
  to apply. Run manually over SSH after reviewing the dry-run; do NOT auto-run on
  deploy.
- Normalize existing `customers.phone` + `orders.customer_phone` to E.164 using each
  record's country where present (`orders.customer_country_code` / `is_international`),
  else default Kenya. Log every change; keep it reversible.
- Data reality (audit, 48 customers): 41 `0…`, 4 `+…`, 2 `254…`, 1 short; ~5
  duplicate groups by normalized number (dedup is a *separate*, later step — not in
  this branch).

## Downstream (Neema, separate repo)
Once the hub stores country-correct E.164, Neema's matcher (`app/core/phone.py`)
becomes an exact E.164 comparison and can drop the "assume Kenya" fallback for
un-country-tagged numbers. Coordinate that small follow-up when this ships.

## Edit-target notes (from investigation)
- `backend/app/Http/Controllers/Api/CustomerController.php` — `store` (~120),
  `quickCreate`, `show` (81), `customerOrders` (387).
- `backend/app/Http/Controllers/Api/PosController.php` — `searchCustomers` (1492),
  pending-order/new-customer path.
- `backend/database/migrations/2024_01_01_000059_create_customers_table.php` — no
  country column today; `phone` VARCHAR(20).
- `backend/docker/entrypoint.sh` — runs `php artisan migrate --force` on start.
- Frontend: `react-admin/src/pages/pos/PosPage.tsx`, customer forms,
  `react-admin/src/api/{customers,pos}.ts`.
