# Bethany House ERP — System Audit & Execution Plan

_Prepared 2026-07-06. Owner: engineering. Status: audit complete, awaiting go-ahead on Phase 5 (development)._

This document is the output of a full system discovery, logic-validation, and quality audit of the
Bethany House / Sonalux Store platform. It is the reference for **what the system is, what is broken,
and the order in which we will fix and finish it.** No application code has been changed to produce it.

Findings carry stable IDs (e.g. `D1.1`, `INV-1`) so they can be tracked to closure in Phase 5.

---

## 0. Executive summary

Bethany House is an omni-channel retail + tailoring ERP for a Kenyan business (Sonalux Store, Nairobi).
The **backend and the staff admin console are deep and largely built (~85%)**; the **customer web
storefront is a 0% empty scaffold**; and the whole system is in **early live production** — but only
through one channel.

**Ground truth from production (2026-07-06):** 38 orders, **all POS** (`order_type='pos'`), ~176,800 KES
across 37 payments (M-Pesa paybill dominant, then cash). **Zero web orders, zero purchase orders, zero
GRNs, zero expenses.** So the code covers far more than the business currently uses: **POS + M-Pesa +
cash + basic production are the only live money paths today.** Procurement, expenses, and the storefront
are built-or-stubbed but unused.

The five things that matter most, in priority order:

1. **Split-brain inventory ledger (CRITICAL).** Two live stock tables — `inventory_items` (what POS/sales
   deduct) and `inventories` (what the admin Inventory UI reads/writes) — hold stock independently and
   drift apart. The admin stock-**transfer** endpoint is additionally broken (queries columns that don't
   exist). Stock numbers cannot be trusted across surfaces.
2. **Money-correctness bugs on live paths.** Voids and refunds don't reconcile `payments`/`payment_status`;
   refunds allow over-refunding; the POS cash-register ledger is never written (0 rows); there is no
   double-entry or reconciliation. Refunds move no gateway money at all (TODO on every gateway).
3. **Security holes reachable today.** Privilege escalation (anyone with `users.edit` can grant
   `super_admin`), 2FA enforced on **nobody**, a seeded `super_admin` with a hardcoded password in active
   daily use, non-expiring full-scope tokens, and a **`sort_by` SQL-injection** in the Orders/Shipments
   list endpoints.
4. **No safety net.** ~0 automated tests on a 13k-line money-handling codebase, and CI has **no test
   gate** — it only builds and deploys. Every change ships unverified.
5. **Structural debt that will compound.** No state machines (illegal status transitions allowed),
   god-object controllers (POS 3.4k lines), a second **live** Livewire admin UI shadowing the React SPA,
   duplicated report controllers, and dead/garbage files committed to the repo.

**Overall honest completion:** breadth ~85% built, but **correctness-complete** (built _and_ trustworthy
for money/stock) is materially lower — call it ~55–60% — because the integrity and money bugs sit under
features that otherwise "look done."

---

## 1. System overview & architecture

**Business purpose.** Run a physical retail + custom-tailoring business end to end from one source of
truth: sell over the counter (POS) and (intended) online; make garments to order (production/tailoring
with bill-of-materials); manage stock across outlets; buy raw materials (procurement); track expenses and
finances; and report on all of it. Multi-currency (KES/USD), multi-language (en/fr/pt in the data model).

**Surfaces (four, over one backend):**
| Surface | Tech | State | Live? |
|---|---|---|---|
| Laravel API | Laravel 11-style, Sanctum, Spatie permissions, PostgreSQL | Deep/mature | Yes |
| Staff admin console | `react-admin/` — Vite + React 18 + TS, TanStack Query, Zustand, Zod, PWA, custom design system | ~95% built | Yes (`/admin`) |
| Customer storefront | `frontend/` — Next.js 16 | **Empty `create-next-app` scaffold (~0%)** | No |
| Legacy admin | Livewire/Blade under `app/Http/Livewire/Admin/*` | Full, **still routed & reachable** | Yes (shadow) |

**Deployment.** Docker Compose on a shared Hostinger VPS; live at `https://hub.bethanyhouse.co.ke` behind
host nginx (`/` → Next.js:3011, `/admin` → react-admin:3012, `/api|/sanctum|/storage` → Laravel:8010,
`/app(s)` → reverb ws:9000, `/db` → adminer). CI/CD (added 2026-07-02): push to `main` builds three GHCR
images and SSH-deploys (pull → `migrate --force` → recreate). **CI has no test stage.**

**Scale of the backend.** 54 API controllers, 81 models, 142 applied migrations (144 files), `routes/api.php`
= 1,425 lines. Business logic lives almost entirely **in controllers** (services are infra: PDF, Mpesa,
Notification, Image, Intelligence). Several god-object controllers: `PosController` 3,428 lines,
`ReportController` 2,094, `OrderController` 1,903, `ProductionController` 1,826, `PurchaseOrderController` 1,358.

**Primary data flow (POS sale, the live path):** cashier → `POST /admin/pos/sales` → single DB transaction:
create `orders` (`order_type='pos'`) + `order_items` → deduct `inventory_items` via `adjustQuantity` (writes
`inventory_transactions`) → write `payments` rows → raw-increment `cash_registers` aggregate columns →
(optional) raise `production_orders` for tailored items. Money settles in `payments`; the storefront and
gateway-initiated (STK) flows exist only in `PaymentController`/`PublicPaymentController`, not POS.

---

## 2. Completion status by module

Legend: **Built** = implemented & wired; **Live** = has real production data; **Correct** = no known
integrity/logic defect from this audit.

| Module | Built | Live | Correct | Notes |
|---|---|---|---|---|
| Auth / Sanctum | ✅ | ✅ | ⚠️ | 2FA subsystem exists but enforced on nobody; tokens never expire |
| RBAC (roles/perms) | ✅ | ✅ | ❌ | Privilege escalation; dual permission catalogs; broken `permission:sync` |
| POS | ✅ | ✅ | ❌ | Money paths gated only by `pos.access`; cash ledger never written; void/refund don't reconcile |
| Orders | ✅ | ✅ (POS only) | ❌ | No state machine; over-refund; `payment_status` divergence |
| Payments | ✅ | ✅ (M-Pesa/cash) | ⚠️ | Gateways wired; **refunds unimplemented on all**; M-Pesa callback swallows errors |
| Inventory | ✅ | ✅ | ❌ | **Split-brain** `inventory_items` vs `inventories`; transfer endpoint broken; `quantity_reserved` dead |
| Production / tailoring | ✅ | ✅ (light) | ❌ | BOM never consumed on completion; material deduct hits wrong row; QC dead-ends |
| Procurement (PO→GRN→return) | ✅ | ❌ (0 rows) | ❌ | Return skips stock decrement; approval bypass; valuation last-write-wins |
| Expenses / finance | ✅ | ❌ (0 rows) | ⚠️ | Broken outlet scoping (`$user->outlet_id` doesn't exist) |
| Shipments / shipping | ✅ | — | ⚠️ | `sort_by` SQLi in list; otherwise solid |
| Customers | ✅ | ✅ | ✅ | — |
| Products / catalogue | ✅ | ✅ | ✅ | Variants, images, translations, reviews |
| Reports | ✅ | ✅ | ⚠️ | `ReportController` + `EnhancedReportController` overlap; unbounded queries; CSV export dead client-side |
| Settings / i18n / tax | ✅ | ✅ | ✅ | DB-driven config |
| Chat / channels (Reverb) | ✅ | ✅ | ✅ | Real-time staff comms |
| CMS (content pages) | ✅ | — | ✅ | — |
| Marketing (coupons/promos/banners/campaigns) | ⚠️ models only | ❌ | — | **No REST API** — only legacy Livewire UI; greenfield if SPA-driven |
| Backups / DB management | ✅ | ✅ | ✅ | super_admin-gated |
| Push / notifications | ✅ | ✅ | ✅ | Web push + Expo |
| **Customer storefront** | ❌ (~0%) | ❌ | — | Empty scaffold; entire e-commerce client is greenfield |
| **Automated tests** | ❌ (~0%) | — | — | Only stock `assertTrue(true)` examples; no CI gate |

---

## 3. Findings & root causes

Grouped by theme. Full workflow-defect catalog (`D1.*/D2.*/D3.*/X*`) is retained in the appendix links
below; the load-bearing ones are called out here with root cause.

### A. Data integrity — inventory (highest business risk)
- **INV-1 (CRITICAL): Split-brain stock ledger.** Two live tables. Sales/POS/fulfillment write
  `inventory_items` (`PosController.php:573`, `OrderController.php:456`); the admin Inventory UI, transfers
  and adjustments read/write `inventories` (`InventoryController.php:21,109,229`). They never reconcile.
  **Root cause:** two models (`Inventory`, `InventoryItem`) over two tables (`..._000018` and `..._000062`),
  introduced at different times, never unified.
- **INV-2 (CRITICAL): Stock-transfer endpoint is broken.** `InventoryController::transfer` queries
  columns that don't exist on `inventories` (`variant_id`, `quantity`, `location_type`; real columns are
  `product_variant_id`, `quantity_on_hand`, no location_type) — `InventoryController.php:229-245`. Transfers
  error or silently no-op. Confirms the `inventories` path is stale/unmaintained.
- **INV-3 (HIGH): `quantity_reserved` is read everywhere, written nowhere.** `reserve()/release()`
  (`InventoryItem.php:116-134`) have no callers; every "available = on_hand − reserved" calculation in
  reports/alerts subtracts a permanently-zero column. **Root cause:** reservation designed but never wired;
  checkout deducts `on_hand` directly instead.
- **INV-4 (HIGH): Three inconsistent stock-mutation styles** (`X1`): logged `adjustQuantity`, raw
  `quantity_on_hand += ; save()` (no log), and raw `increment/decrement` (no log) — so
  `inventory_transactions` is an incomplete audit trail and stock can't be reconstructed from it.
- **INV-5 (HIGH): No concurrency control on stock.** Checkout reads availability then deducts with no row
  lock (`D1.2`); `adjustQuantity` has no zero-floor, so concurrent sales drive stock negative silently.

### B. Money correctness (live paths)
- **MON-1 (HIGH): Void/refund don't reconcile payments.** `voidOrder` voids payment rows but never
  recomputes `payment_status` (`D1.6`); POS void leaves `payments.status='paid'` (`PosController`), so
  payment-based reports double-count voided sales. Refund writes one payment row and skips
  `syncPaymentStatus` (`D1.4`).
- **MON-2 (HIGH): Over-refund possible.** `OrderController::refund` validates `amount|min:0` with **no
  upper bound** vs collected total (`OrderController.php:883`). You can refund more than was paid.
- **MON-3 (HIGH): POS cash ledger never written.** `cash_register_transactions` has a full model API
  (`recordSale/recordCashIn/...`) that POS never calls; it uses raw `expected_cash += amount`. **Live table
  has 0 rows** despite registers showing totals — no per-movement audit, no double-entry, no reconciliation.
- **MON-4 (HIGH): Refunds move no money.** Gateway refund is a TODO on every gateway
  (`PaymentController.php:852`, `ReturnController.php:575`). A return can be "completed" while no money leaves.
- **MON-5 (MED): `store_credit` refunds issue nothing** — accepted as a method, but no credit ledger/record
  is created. Customer is owed money with no system record.
- **MON-6 (MED): M-Pesa callback swallows exceptions and returns success** (`PaymentController.php:551-554`),
  so a failed callback tells Safaricom "accepted" without recording — silent money-state divergence.
- **MON-7 (MED): Procurement valuation is last-write-wins and currency-blind** (`D3.4`) — each receipt
  overwrites cost basis for all on-hand stock; PO currency isn't converted into KES valuation.

### C. Workflow / state management
- **ST-1 (HIGH): No state machines anywhere.** Order/production/procurement statuses are ad-hoc strings.
  `updateStatus` validates only the target value, not the current one (`D1.3`) → a delivered order can go
  back to pending; a cancelled order (already restocked) can be shipped.
- **ST-2 (HIGH): Production has no stage ordering & QC dead-ends** (`D2.3`, `D2.4`) — tasks completable out
  of order; `qc_failed`/`on_hold` have no exit path → permanently stuck orders.
- **ST-3 (HIGH): Production completion never consumes BOM** (`D2.1`) — finished goods added to stock, raw
  materials never deducted; `MaterialAllocation.quantity_used` stays 0. Inflated material inventory,
  understated COGS.
- **ST-4 (HIGH): Purchase return removes goods without decrementing stock** (`D3.1`) when qty is fractional
  or the inventory row is missing, yet still reduces `quantity_received` → goods can be received again.
- **ST-5 (MED): Purchase-return stock movement happens at creation, not approval** (`D3.7`) — rejecting a
  return does **not** restore stock. Approval workflow is decorative for stock.

### D. Security (reachable now)
- **SEC-1 (CRITICAL): Privilege escalation.** Role assignment is gated by `users.edit`, not `roles.edit`,
  with no ceiling — anyone who can edit users can grant `super_admin`, which bypasses everything via
  `Gate::before`.
- **SEC-2 (CRITICAL): 2FA enforced on nobody.** All 15 users have `two_factor_enabled=false` and
  `must_setup_2fa` turned off (except one). Four `super_admin`s protected by password alone.
- **SEC-3 (HIGH): `sort_by` SQL injection** in `OrderController::index`/export (`:61,:114`) and
  `ShipmentController` (`orderBy("s.{$sortBy}")`). Identifiers aren't bound. Fix pattern already exists in
  `ProductionController:69` (whitelist).
- **SEC-4 (HIGH): Seeded weak/known credentials.** _Partially remediated:_ the super_admin is now seeded from
  `SUPER_ADMIN_EMAIL`/`SUPER_ADMIN_PASSWORD` (neutral default email, random password when unset) and the former
  `nyorojnr@gmail.com` account has been purged from prod (migration `2026_07_07_100000`). Still weak:
  `staff@bethanyhouse.co.ke`/`password` (the most-used live login) and sample customer/`password`.
- **SEC-5 (HIGH): Non-expiring, full-scope tokens.** `SANCTUM_TOKEN_EXPIRY` null; every token minted with
  `['*']`. 74 live tokens, none can expire.
- **SEC-6 (MED): Outlet scope hole.** `authoriseOutletAccess` passes for a user with zero outlet
  assignments → an unassigned staffer with `pos.access` can transact against any outlet.
- **SEC-7 (MED): `permission:sync` broken.** The scheduler runs a mistyped `permissions:sync` **hourly**
  (recurring prod ERROR every :10) and the deploy hook does too — new permissions never sync on deploy.
- **SEC-8 (MED): CORS `supports_credentials:true` with placeholder origins** — verify prod `FRONTEND_URL`
  is set and `yourdomain.com` placeholders removed.

### E. Performance
- **PERF-1 (MED): Unbounded report/dashboard queries.** Many report endpoints `->get()` with no
  limit/pagination (`ReportController` ~35 sites); `stockOnHand` returns the entire table; the dashboard
  fires 30+ uncached COUNT/SUM queries per load. Fine at today's scale (49 products, 38 orders), will hurt
  as data grows. **Main CRUD list endpoints are correctly paginated & eager-loaded — no classic N+1 found.**
- **PERF-2 (LOW): Order CSV export loads up to 10k eager-hydrated rows into memory** (`OrderController.php:118`).
- **PERF-3 (LOW): Missing indexes on some FK columns** (`order_items.production_order_id`,
  `channels.last_message_id`, `channel_members.last_read_message_id`).

### F. Quality / hygiene / debt
- **Q-1 (HIGH): No tests + no CI test gate** — the single largest risk for a money app. `tests/` holds only
  `assertTrue(true)` examples; `deploy.yml` has no `artisan test` step.
- **Q-2 (HIGH): Two live admin UIs.** The Livewire/Blade admin (`routes/web.php:121-337`, 60+ components) is
  still routed behind `auth`+`ensure.2fa`, doubling RBAC/attack surface and maintenance. Decide: retire it,
  or freeze it.
- **Q-3 (MED): Malformed migration timestamps** `2026_15_06_*`, `2026_16_06_*` (×3) sort after `2026_06_24`
  → run last on a fresh DB; ordering hazard that won't reproduce on the already-migrated prod box.
- **Q-4 (MED): Duplicated reporting** (`ReportController` vs `EnhancedReportController`, both routed).
- **Q-5 (MED): CSV export dead across all reports** — `ExportCsvButton` stubbed to `return null` pending an
  nginx `Authorization`-forwarding fix; backend export endpoints exist but are UI-unreachable.
- **Q-6 (LOW): Committed cruft** — `routes/routes/*` stale duplicate (895 vs 1425 lines), `test_backup.php`
  symlink to `/proc/self/fd/0`, `:Zone.Identifier` files, `.backup` files.
- **Q-7 (LOW): Stale README** — describes a Livewire dashboard as the admin; the real console is react-admin.
- **Q-8 (LOW): Two broadcast backends** (`pusher-php-server` + `laravel/reverb`) — drop the unused one.
- **Q-9 (LOW): Peripheral stubs** — FX rate sync, test-email, order-confirmation & return emails, POS emailed
  receipt, supplier Excel export, customer/inventory aging reports.

---

## 4. Recommended architectural improvements

These are the structural moves that make the fixes durable rather than whack-a-mole:

1. **One inventory ledger.** Pick `inventory_items` (it's where live sales already write) as the single
   source of truth; migrate/retire `inventories`; route all reads/writes (admin UI, transfers, adjustments,
   procurement receipt, production) through `InventoryItem::adjustQuantity` so every movement is logged.
   This closes INV-1/2/4 and X1/X2 together.
2. **A real money/stock invariant layer.** Centralize stock and payment mutations behind service methods
   with row-locking, zero-floors, and mandatory `inventory_transactions`/`cash_register_transactions`
   writes. Make void/refund emit reversing records. This closes the MON-* and INV-5 classes.
3. **Explicit state machines** for order, production, and PO status — a single transition table that every
   `updateStatus` consults. Closes ST-1/ST-2.
4. **Test harness + CI gate before further money-path edits.** Stand up Pest/PHPUnit with a Postgres test DB,
   write characterization tests for the live paths (POS sale, void, refund, GRN receive, production
   complete), and add an `artisan test` stage to `deploy.yml` that blocks deploy on failure.
5. **Collapse to one admin UI.** Commit to the react-admin SPA; retire or freeze the Livewire admin (Q-2)
   after porting the only API-less feature it owns (Marketing).
6. **Harden the permission model** — assign-role ceiling, real 2FA enforcement, token TTL, fix the
   `permission:sync` name and remove it from swallow-all.
7. **Decompose god-object controllers incrementally** (extract POS/Order/Production services) — opportunistic,
   alongside the fixes above, not as a big-bang refactor.

---

## 5. Prioritized implementation roadmap

Sequenced by risk-to-live-business first. Each item references the findings it closes. **Rule: no money/
stock logic change ships without a test (per improvement #4), and each lands as its own reviewed PR.**

### P0 — Critical (data corruption / money / security, reachable on live paths)
0. **Stand up the test harness + CI test gate** (Q-1, improvement #4) — prerequisite for safely touching the rest.
1. **Fix `permission:sync`** name in scheduler + deploy hook (SEC-7) — trivial, stops hourly prod errors, unblocks permission deploys. _(One-line; safe first shippable win.)_
2. **Security hardening pass** (SEC-1,2,4,5): assign-role ceiling + gate behind `roles.edit`; enforce 2FA for staff/super_admin; rotate/kill seeded creds & stop the seeder running in prod; set token TTL.
3. **`sort_by` SQL-injection fix** (SEC-3) — whitelist columns in Orders/Shipments, mirroring Production.
4. **Unify the inventory ledger** (INV-1,2,4): choose `inventory_items`, fix/retire the broken `inventories` transfer path, route all stock mutations through the logged adjuster. _(Highest business-data risk.)_
5. **Money reconciliation on void/refund** (MON-1,2,3): reversing `payments` rows, recompute `payment_status`, bound refunds to collected total, write the `cash_register_transactions` ledger.

### P1 — High (correctness of built-but-near-live modules & durability)
6. **Order/production/PO state machines** (ST-1,2) — block illegal transitions; give QC/on-hold an exit.
7. **Production BOM consumption on completion** (ST-3) + fix material deduction to the correct row with locking (`D2.2`).
8. **Procurement correctness** (ST-4,5, MON-7): return decrements stock atomically or fails cleanly; move stock effects to approval; sane valuation (moving-average, currency-aware).
9. **Concurrency & zero-floors on stock** (INV-5) — row locks + DB-guarded non-negative on all deduct paths.
10. **Expenses outlet-scoping fix** (uses non-existent `$user->outlet_id`) before the module goes live.
11. **Gateway refunds** (MON-4) + store-credit ledger (MON-5) + M-Pesa callback error handling (MON-6).

### P2 — Medium (performance, consolidation, hygiene, decisions)
12. **Report/dashboard performance** (PERF-1,2): paginate/limit report queries, cache dashboard aggregates.
13. **Consolidate reporting controllers** (Q-4) and **re-enable CSV export** (Q-5, nginx `Authorization` fwd).
14. **Migration hygiene** (Q-3): rename the malformed-timestamp migrations for correct fresh-install ordering; add missing FK indexes (PERF-3).
15. **Retire/freeze the Livewire admin** (Q-2) after porting Marketing to a REST API (the one API-less feature).
16. **Repo cleanup** (Q-6,7,8): delete `routes/routes/*`, the `test_backup.php` symlink, `:Zone.Identifier`/`.backup` files; refresh README; drop the unused broadcaster.
17. **DECISION — customer storefront.** All orders are POS today; `frontend/` is empty. Decide whether online selling is a real near-term goal. If yes, it's a greenfield build against the existing API (reuse the admin's pay/track flows); if no, deprioritize and remove it from the deploy set.

### P3 — Nice-to-have (optimizations & polish)
18. Peripheral stubs (Q-9): FX sync, transactional emails, POS emailed receipts, supplier export, aging reports.
19. Incremental controller decomposition (improvement #7) as touched.
20. Double-entry general-ledger layer for finance-grade reconciliation (only if the finance module is expanded).

---

## 6. How Phase 5 (development) will run

- **Test-first on money/stock.** Characterization test → fix → green, for every P0/P1 money or stock item.
- **One reviewed PR per finding** (referencing its ID), through the existing CI/CD pipeline. Merges to `main`
  auto-deploy, so PRs are batched deliberately and reviewed before merge.
- **Preserve the live POS path.** POS + M-Pesa + cash are the only live money channels — changes there are
  guarded by tests and shipped in the smallest safe increments; cashier workflow must not break.
- **Regression discipline.** The CI test gate (P0.0) is the backstop; no red build deploys.
- **Every material change documented** — this file is updated as findings close (ID → PR link → status).

**Recommended first shippable slice:** P0.0 (test harness + CI gate) → P0.1 (`permission:sync` one-liner) →
P0.3 (`sort_by` SQLi) — smallest, safest, highest-signal wins that also prove the pipeline end-to-end,
before the larger INV-1 unification and money-reconciliation work.
