# Native apps programme — Phase 1 audit, inventory and feature-parity matrix

Status: DRAFT, read-only audit. Verified 2026-10-27 against `main` at commit
0b923c4 by inspection of the repository. Nothing in this document is a change
to the system. Owner: Moses Mwicigi.

Targets: (1) macOS, (2) iPhone, (3) iPad, (4) Android phone, (5) Samsung
(Android) tablet.

---

## 1. What the application is

Bethany House is a Kenyan retailer/manufacturer. The repository holds:

| Surface | Path | Role | Native-app scope |
|---|---|---|---|
| Laravel 12 API | `backend/` | Single source of truth (Sanctum 4, Spatie permission 6, Reverb, DomPDF, WebAuthn, Google2FA). ~998 route definitions under `/api/v1`, 112 controllers, 149 models, 287 migrations, 341 test files. | **The backend for all five apps.** |
| Hub console (staff) | `react-admin/` | React + Vite SPA served at `hub.bethanyhouse.co.ke/admin/`. 152 routes, 139 page files, 38 modules. PWA with service worker, web push, offline punch. | **This is "the Laravel application" the apps must reach parity with.** |
| `frontend/` | `frontend/` | An **untouched `create-next-app` scaffold**, not deployed (`deploy/box-deploy.sh` builds only laravel + react-admin). The real customer storefront is a separate repo at `bethanyhouse.co.ke` that calls `/api/v1/storefront/*`. | Out of scope. Customer token pages (pay/quote/order/track) stay web (§3.14). |
| Store app (existing) | `mobile/` | Expo/React Native **WebView shell** around the Hub console (bundle/package `ke.co.bethanyhouse.hub`, scheme `bethanyhub`). Adds phone location + mock-GPS flag, background trail on shift, Expo push, secure device id, biometric re-lock. | To be **replaced** by the native apps; its bridge contract (§4) lists the native capabilities the backend already expects. |

VERIFIED: the existing "app" is a WebView wrapper (`docs/STORE_APP.md`: "the
console is the product, the app is the phone's wrapper"). The assignment
explicitly rules out WebView as the core, so the new apps are a rewrite of the
client, not of the backend.

## 2. Backend facts the apps depend on

| Topic | Verified fact | Evidence |
|---|---|---|
| Auth | Laravel Sanctum bearer tokens for the Hub (`POST /api/v1/admin/auth/login`, `/2fa/verify`, `/2fa/setup`, `/2fa/setup/confirm`, `/forgot-password`, `/reset-password`); customer auth under `/api/v1/auth/*`. Staged TOTP 2FA by role. Passkeys exist (`app/Models/Passkey.php`, `AccountSecurityController`). | `backend/routes/api.php:62-108` |
| Token life | Per-token `expires_at` stamped at mint (`SANCTUM_ACCESS_TTL_MINUTES`, default 7 days). **No refresh endpoint.** On top, per-role idle/absolute limits enforced on every request by `SessionPolicy::admit()` (e.g. super_admin 15 min idle / 8 h absolute; pos_clerk 5 min idle with PIN lock / 12 h). Expired = token deleted = 401. PIN lock = **423** `{reason:"pin_locked"}`, unlocked via `admin/auth/pin/unlock`. `X-Background-Request: 1` marks polling that does not count as activity. | `config/sanctum.php`, `config/security.php`, `app/Services/Auth/SessionPolicy.php`, `AuthServiceProvider.php:53-59` |
| Step-up | `step.up` middleware, 5-minute window; 403 `{code:"step_up_required", method: passkey\|totp\|password}`. Passkeys are **step-up and enrolment only**, there is no passkey login route. | `app/Services/Auth/StepUp.php`, `routes/api.php:140-148` |
| Lockout | 5 failures → 15 min lock; 10 in 24 h → account lock; 423 `{reason:"account_locked"}`. | `AuthController.php:820-831` |
| Devices | `X-Device-Id` / `X-Device-Touch` headers; tokens store ip, agent, device_id, last_active_at; `admin/profile/sessions*` and `devices*` list/revoke. New device → email. | `app/Services/Auth/Devices.php`, `ProfileController.php` |
| Login response | `POST admin/auth/login` returns one of `{requires_2fa, user_id, challenge}`, `{requires_2fa_setup, user_id, setup_token}`, `{message, user, token}`. `GET admin/auth/me` returns `{user}` with `permissions` (effective, money pages stripped by `FinancialVisibility`), `money_view`, `outlet`, `session_policy`, `terminal_pin_set`, `two_factor_required`, `current_device`, `discount_cap_percent`, `promotion_cap_percent`, `is_owner`, `roles`. | `AuthController.php:446-547, 744-795` |
| Rate limits | `throttle:auth` on login routes, `throttle:api` on authenticated. | `routes/api.php:65,83` |
| Authorization | Spatie permission (guard `sanctum`); catalogue, bundles and role map in `app/Console/Commands/SyncPermissions.php`; `Gate::before` lets super_admin pass everything; data scope own/outlet/all/none via `DataScopeResolver` + `ViewerScope`. Permission slugs (e.g. `orders.view`, `pos.access`, `payroll.view`, `reports.financial`), `super_admin` role bypass, outlet scope, `FinancialVisibility`. Navigation gates in the console carry the same slugs the API checks (`react-admin/src/lib/navGate.ts`; `npm run check:nav` enforces). | `navGate.ts`, `Sidebar.tsx`, CLAUDE.md appendix |
| Push | Web Push (VAPID) **and** Expo push tokens via `POST /api/v1/admin/push/subscribe-expo`; `push_subscriptions.token_type` in `{webpush, expo}`. No FCM/APNs direct sender. | `PushController.php` |
| Real-time | **Reverb in production** (`docker-compose.yml:62,274`, host nginx `/app(s)` → :9000). Channels `user.{id}`, `thread.{model}.{id}`, `channel.{id}`, `presence.channel.{id}`; events `message.sent`, `reaction.updated`, `read.updated`, `comment.posted`, `notification.pushed`. Auth `POST /api/broadcasting/auth`. | `routes/channels.php`, `app/Events/*` |
| Response shape | **No envelope, no API Resources.** ~629 responses lead with `message`, ~82 with `data`, many with a named key. Pagination is raw Laravel `paginate()` (61 sites). Errors: 422 default, 401 default, 403 variants (`step_up_required`, `download_approval_required`), 404 `{reason: deleted\|not_found}`, 423, 429 `{retry_after_seconds}`, 503 `{reason:"report_timeout"}`. | agent audit of 112 controllers |
| Files | Short-lived relative signed URLs (`SignedFiles::issue`, ≤5 min) at `/api/v1/files/*`; every staff file response passes `DownloadGate` (approval-gated + watermarked, except invoice/quotation/receipt). PDFs via DomPDF; CSV via `ExportsCsv` (`?export`). | `app/Support/SignedFiles.php`, `DownloadGate` |
| Rate limits (all) | auth 5/min IP; api 120/min user; admin-api 300/min user; search 20/min; public-api 60–100/min; webhooks 100/min. | `RouteServiceProvider.php` |
| Location | `POST /time-clock/*` punches carry a fix and a `mock_location` flag; `POST /time-clock/trail` background trail while clocked in, retention 90 days. | `docs/STORE_APP.md` |
| Devices | Named devices appear under Setup › Devices, user agent `BethanyHub/<version>`. | `docs/STORE_APP.md` |
| Timezone | `Africa/Nairobi`, DB timestamps in Nairobi wall-clock. | `backend/config/app.php` |
| Money | Pricing rate vs reporting rate are separate (`currencies.exchange_rate`, `currencies.reporting_rate_to_kes`); `MetricEngine` is the only metric source. Clients must never compute money; they display server figures. | CLAUDE.md appendix |
| API docs | **None.** No OpenAPI/Swagger/Scribe/Postman files. `docs/HUB_CONTRACT.md` referenced at `routes/api.php:249` does not exist. | agent audit |
| Route registration | `routes/api.php` is registered by `bootstrap/app.php` **and** again by `RouteServiceProvider.php:29-31`; effect UNKNOWN — NEEDS VERIFICATION (`php artisan route:list`). | — |
| Toolchain here | Linux container: PHP 8.3, Node 22, Java + Gradle 8.14 present; **no Xcode/Swift, no Android SDK (`ANDROID_HOME` unset)**. | shell |

## 3. Console module inventory and parity matrix

Treatment codes per target:

- **F** — full parity, native UI.
- **A** — adapted: every capability kept, presentation redesigned for the form factor (lists → cards, multi-step forms, bottom sheets, camera capture).
- **R** — read and review (view, search, filter, approve/reject, share PDF); create/edit of large records deferred to a larger screen. Capability is *not* removed from the API; the screen is read-first.
- **K** — kiosk/door device mode only.
- **W** — stays a web page (customer-facing or public token link); apps open it in the system browser or share it.
- **—** — not offered on this target; reason given.

Gate = the permission slug the console and API both check (`Sidebar.tsx`).
"ungated" = every signed-in staff member, API scoped to the person.

### 3.1 Sign-in and session (all targets F)

| Screen | Gate | macOS | iPhone | iPad | Android | Tablet |
|---|---|---|---|---|---|---|
| Login (email+password) | public | F | F | F | F | F |
| 2FA verify / forced 2FA setup | public (staged) | F | F | F | F | F |
| Passkey step-up | ungated | F (platform passkeys) | F | F | F | F |
| Biometric re-lock after background | ungated | F (Touch ID) | F | F | F | F |
| Forgot / reset password | public | F | F | F | F | F |
| Device registration + push token | ungated | F (APNs via server-side change) | F | F | F (FCM) | F |

### 3.2 Workspace

| Screen | Route | Gate | macOS | iPhone | iPad | Android | Tablet |
|---|---|---|---|---|---|---|---|
| Dashboard | `/dashboard` | `dashboard.view` | F | A | F | A | F |
| Approvals queue | `/approvals` | any-of approval perms | F | F | F | F | F |
| Notifications | `/notifications` | `notifications.view` | F | F | F | F | F |
| Messages (channels, threads) | `/comms`, `/comms/:channelId` | ungated | F | F | F | F | F |
| Global search / command palette | — | ungated | F (⌘K) | F | F (⌘K) | F | F |

### 3.3 Me (staff self-service; all ungated)

| Screen | Route | macOS | iPhone | iPad | Android | Tablet |
|---|---|---|---|---|---|---|
| Today | `/me` | F | F | F | F | F |
| Updates (posts, RSVP, read receipts) | `/updates`, `/updates/:id` | F | F | F | F | F |
| Checklists / forms to fill | `/forms` | F | F | F | F | F |
| Handbook | `/handbook`, `/handbook/:id` | F | F | F | F | F |
| Training (courses, lessons, quizzes) | `/courses`, `/courses/:id` | F | F | F | F | F |
| To sign (document signing) | `/me/sign`, `/me/sign/:id` | F | F (finger) | F (Pencil) | F | F (S Pen) |
| To-dos | `/todos` | F | F | F | F | F |
| Colleagues directory | `/colleagues` | F | F | F | F | F |
| Clock in/out (geofence, mock-GPS flag, on-shift trail) | `/me/clock` | R (no geofence punch from a Mac — policy: UNKNOWN) | F | F | F | F |
| My shifts | `/me/shifts` | F | F | F | F | F |
| My leave (request, balances) | `/me/leave` | F | F | F | F | F |
| My hours / timesheet | `/me/timesheet` | F | F | F | F | F |
| My payslips (PDF) | `/me/payslips` | F | F | F | F | F |
| My advances | `/me/advances` | F | F | F | F | F |
| Profile & security (password, 2FA, passkeys, devices) | `/settings/profile` | F | F | F | F | F |

### 3.4 People (managers)

| Screen | Route | Gate | macOS | iPhone | iPad | Android | Tablet |
|---|---|---|---|---|---|---|---|
| Overview | `/people/overview` | `attendance.view_team` | F | A | F | A | F |
| Requests (leave/adjustment approvals) | `/people/requests` | `attendance.view_team` | F | F | F | F | F |
| Attendance (punches, trails, map) | `/settings/attendance` | `attendance.view_team` | F | A | F | A | F |
| Checklists management (templates) | `/people/forms` | `forms.manage` | F | R | F | R | F |
| Roster (shift templates, publish) | `/roster` | `attendance.view_team` | F | A | F | A | F |
| Timesheets (approve, lock period) | `/timesheets` | `attendance.view_team` | F | A | F | A | F |
| Leave (team calendar, types) | `/leave` | `attendance.view_team` | F | A | F | A | F |
| Employee files (profile, documents, contract) | `/people`, `/people/:id` | `hr.view` | F | A | F | A | F |
| Payroll (runs, lines, lock, email CSV) | `/payroll` | `payroll.view` | F | R | F | R | F |
| Kiosk (door tablet: name + PIN + camera snapshot; set up by a manager from Attendance › Kiosks, kiosk-only token) | `/kiosk` | kiosk token | — | — | K | — | K |

### 3.5 Catalog

| Screen | Route | Gate | macOS | iPhone | iPad | Android | Tablet |
|---|---|---|---|---|---|---|---|
| Products list | `/catalogue/products` | `products.view` | F | A | F | A | F |
| Product edit/new (tabs: content, images, pricing, variants, features, SEO, measurements, production stages, BOM) | `/catalogue/products/:id`, `/new` | `products.view` / `products.create` | F | A (camera capture, sectioned form) | F | A | F |
| Categories | `/catalogue/categories` | `products.view` | F | A | F | A | F |

### 3.6 Sales and POS

| Screen | Route | Gate | macOS | iPhone | iPad | Android | Tablet |
|---|---|---|---|---|---|---|---|
| POS (cart, discounts within limits, multi-currency tender, receipt) | `/pos` | `pos.access` | F | A (quick sale) | F | A | F |
| Receipt printing | POS | `pos.access` | F (AirPrint/CUPS) | A (AirPrint) | F | A | F |
| Till Sales / Web / Chat / Quoted / Pending queue | `/sales/*-orders`, `/sales/pending-queue` | `orders.view` | F | F | F | F | F |
| Order detail (status transitions, corrections, payments, shipments, PDF) | `/sales/orders/:id` | `orders.*` | F | A | F | A | F |
| Quotations (build, send, convert) | `/sales/quotations` | `quotations.view` | F | A | F | A | F |
| Invoices | `/sales/invoices` | `orders.view` | F | R | F | R | F |
| Interest carts | `/sales/interest-carts` | `orders.view` | F | R | F | R | F |
| Shipments | `/sales/shipments`, `/:id` | `shipment.view` | F | F | F | F | F |
| Customers (list, detail, balances) | `/sales/customers`, `/:id` | `customers.view` | F | F | F | F | F |
| Balances (receivables) | `/pos/outstanding-balances` | `receivables.view` | F | A | F | A | F |
| Tills (open/count/verify/reconcile/correct, per-currency) | `/pos/tills` | any-of `pos.access`, `pos.tills_view_all`, `pos.reconcile`, `pos.till_correction` | F | F | F | F | F |
| EoD reports | `/pos/eod-reports` | `pos.eod_review` | F | R | F | R | F |
| EoD settings | `/pos/eod-settings` | all-of `pos.access` + `settings.edit` | F | — (setup) | F | — | F |
| Handoff (collection) | `/handoff/orders/:id` | `orders.view` | F | F | F | F | F |

### 3.7 Production

| Screen | Route | Gate | macOS | iPhone | iPad | Android | Tablet |
|---|---|---|---|---|---|---|---|
| My Tasks (floor worker home) | `/production/my-tasks` | `production.worker` | F | F | F | F | F |
| Production orders (list, detail, stages, assignees, confirm) | `/production/orders`, `/:id` | `production.view` | F | A | F | A | F |
| Work in progress board | `/production/wip` | `production.view` | F | A | F | A | F |
| Quality control (submit / review) | `/production/qc` | any-of `production.submit_qc`, `production.approve_qc` | F | F (camera) | F | F | F |
| Calendar | `/production/calendar` | `production.view` | F | A | F | A | F |
| Bill of materials | `/production/bom` | `production.view_bom` | F | R | F | R | F |

### 3.8 Inventory

| Screen | Route | Gate | macOS | iPhone | iPad | Android | Tablet |
|---|---|---|---|---|---|---|---|
| Stock levels | `/inventory/stock-levels` | `inventory.view` | F | F | F | F | F |
| Product serials (reconcile; **no barcode scanning exists today**, native scan is an addition) | `/inventory/serials` | `inventory.view` | F | F (camera scan) | F | F | F |
| Stock adjustments | `/inventory/adjustments`, `/:id` | `inventory.*` | F | F | F | F | F |
| Stock transfers | `/inventory/transfers`, `/:id` | `inventory.*` | F | F | F | F | F |
| Raw materials & allocations | `/inventory/materials` | `inventory.view` | F | A | F | A | F |
| Low stock alerts | `/inventory/low-stock` | `inventory.view` | F | F | F | F | F |

### 3.9 Procurement

| Screen | Route | Gate | macOS | iPhone | iPad | Android | Tablet |
|---|---|---|---|---|---|---|---|
| Purchase orders (multi-currency, approve) | `/procurement/purchase-orders`, `/:id` | `procurement.view` | F | A | F | A | F |
| Suppliers | `/procurement/suppliers` | `procurement.view` | F | F | F | F | F |
| Goods receipt (GRN) | `/procurement/goods-receipt`, `/:id` | `procurement.*` | F | F (receiving with camera) | F | F | F |
| Purchase returns | `/procurement/returns`, `/:id` | `procurement.*` | F | A | F | A | F |

### 3.10 Expenses

| Screen | Route | Gate | macOS | iPhone | iPad | Android | Tablet |
|---|---|---|---|---|---|---|---|
| Expenses (claim, attachments, approval chain) | `/expenses`, `/:id` | `expenses.view` | F | F (receipt camera) | F | F | F |
| Imprest (accounts, top-ups, cash counts) | `/expenses/imprest` | `expenses.view` | F | A | F | A | F |
| Categories & budgets | `/expenses/settings` | `expenses.budgets` | F | R | F | R | F |
| Analytics | `/expenses/analytics` | `expenses.view` | F | R | F | R | F |

### 3.11 Marketing, Home Front, Insights, Finance

| Screen | Route | Gate | macOS | iPhone | iPad | Android | Tablet |
|---|---|---|---|---|---|---|---|
| Seasons | `/marketing/seasons` | `marketing.view` | F | A | F | A | F |
| Campaigns (email) | `/marketing/campaigns` | `marketing.view` | F | R | F | R | F |
| Home Front: home page builder | `/home-front/home` | `marketing.view` | F | — (page builder needs a large canvas) | F | — | F |
| Home Front: product pages | `/home-front/products` | `marketing.view` | F | — | F | — | F |
| Storefront insights | `/insights` | `reports.customers` | F | R | F | R | F |
| Intelligence (channels, geography) | `/intelligence/*` | reports perms | F | R | F | R | F |
| Payment transactions ledger (record takings, approvals) | `/finance/transactions` | `payments.transactions` | F | A | F | A | F |

### 3.12 Reports (one permission per page; money visibility by `FinancialVisibility`)

| Screen | Route | Gate | macOS | iPhone | iPad | Android | Tablet |
|---|---|---|---|---|---|---|---|
| Executive overview | `/reports` | `reports.executive` | F | A | F | A | F |
| Sales & orders | `/reports/sales` | `reports.sales` | F | A | F | A | F |
| Customers & Neema | `/reports/customers` | `reports.customers` | F | A | F | A | F |
| Finance & cash | `/reports/finance` | `reports.financial` | F | A | F | A | F |
| Production & fulfilment | `/reports/production`; costing `/:id` needs all-of `reports.production` + `reports.financial` | `reports.production` | F | A | F | A | F |
| Inventory | `/reports/inventory` | `reports.inventory` | F | A | F | A | F |
| Procurement & suppliers | `/reports/procurement` | `reports.procurement` | F | A | F | A | F |
| Staff, outlets & performance | `/reports/performance` | `reports.performance` | F | A | F | A | F |
| Signals | `/reports/signals` | `reports.signals` | F | A | F | A | F |
| Business explorer | `/reports/explorer` | `reports.explorer` | F | R | F | R | F |
| Audit & data quality | `/reports/data-quality` | `reports.data_quality` | F | R | F | R | F |
| PDF export / share | `/reports/pdf/*` | same | F (print + save) | F (share sheet) | F | F | F |
| CSV export | `/*/export` | same | F | F (share) | F | F | F |

Rule for every report screen on every target: numbers come from the API
(`MetricEngine`); the client never sums, converts or rounds money.

### 3.13 Setup

| Screen | Route | Gate | macOS | iPhone | iPad | Android | Tablet |
|---|---|---|---|---|---|---|---|
| Business settings | `/settings/business` | `settings.view` | F | R | F | R | F |
| Outlets (geofence radius) | `/settings/outlets` | `outlets.view` | F | A (map) | F | A | F |
| Attendance rules, automations | `/settings/attendance-rules`, `/automations` | `attendance.view_team` |
| Holidays | `/settings/holidays` | ungated | F | R | F | R | F |
| Roles & permissions | `/settings/roles` | `roles.view` | F | — (matrix editor) | F | — | F |
| Users (invite, role, outlet, reports-to, devices) | `/settings/users` | `users.view` | F | A | F | A | F |
| Countries, languages, shipping | `/settings/*` | `settings.view` or `setup.technical` | F | R | F | R | F |
| Currencies (pricing & reporting rate proposals) | `/settings/currencies` | any-of settings/propose | F | R | F | R | F |
| Tax rates, payment methods | `/settings/taxes`, `/payment-methods` | any-of | F | R | F | R | F |
| Discount limits | `/settings/discount-limits` | super admin only | F | R | F | R | F |
| Production stages, auto-assignees | `/settings/production/*` | `production.configure_auto_assignees` | F | R | F | R | F |
| Activity logs | `/settings/activity-logs` | super admin (role) | F | R | F | R | F |
| Database management (backups) | `/settings/database` | super admin (role) | F | — (destructive admin) | F | — | F |
| Trash (restore) | `/settings/trash` | setup perm | F | R | F | R | F |
| Downloads (approvals, my downloads) | `/settings/downloads`, `/my-downloads` | download perms | F | F | F | F | F |

### 3.14 Public / customer token pages (stay web)

| Page | Route | Treatment |
|---|---|---|
| Pay link, quote view, order view, tracking | `/pay/:token`, `/quote/:token`, `/order/:token`, `/track/:token` | W — customer pages; apps share the link. |
| Privacy policy | `/privacy` | W — linked from store listings. |

### 3.15 Cross-cutting native capabilities

| Capability | Backend today | macOS | iPhone | iPad | Android | Tablet |
|---|---|---|---|---|---|---|
| Push | Web Push + Expo relay | APNs (needs server-side APNs sender: **new**) | APNs (**new**) | APNs | FCM (**new**) | FCM |
| Geofenced punch, mock-GPS flag | `time-clock` | n/a | CoreLocation | CoreLocation | FusedLocation + `isMock` | same |
| On-shift background trail | `time-clock/trail` | n/a | background location | same | foreground service | same |
| Biometric re-lock | client-side | Touch ID | Face/Touch ID | same | BiometricPrompt | same |
| Passkeys | `AccountSecurityController` | AuthenticationServices | same | same | Credential Manager | same |
| Camera / scan | uploads via `/files` | file picker | camera + barcode | same | CameraX + ML Kit | same |
| Files / PDF | `/pdf/*`, `/reports/pdf/*` | Quick Look, print, save | share sheet | same | share intent | same |
| Printing (receipts, PDFs) | server PDFs | AirPrint/CUPS | AirPrint | AirPrint | Android Print | same |
| Deep links | scheme `bethanyhub`, `https://hub.bethanyhouse.co.ke/admin/*` | universal links | same | same | App Links | same |
| Offline | console: kept punch, pending badge | read cache | read cache + punch queue | same | same | same |
| Real-time | channels + broadcasting (driver UNKNOWN) | WebSocket | same | same | same | same |
| Kiosk mode | `/kiosk` | — | — | guided access | — | lock task mode |

## 4. Existing bridge contract (what the backend already expects a native client to do)

Page → app: `geo:start`, `geo:stop`, `push:register`, `push:token?`,
`open-external {url}`, `trail:start {interval_minutes, distance_meters, token}`,
`trail:stop`. App → page: `geo:fix {latitude, longitude, accuracy, at, mocked}`,
`geo:error {code: denied|unavailable}`, `push:token {token|null}`,
`trail:state {started|stopped|denied|unsupported}` (`mobile/bridge.ts:5-21`).
The native apps absorb these behaviours directly instead of bridging.

## 5. Offline policy (from the financial truth model)

Online-only (authoritative server transactions): POS tender and refund, till
open/close/finalize and corrections, payment recording and approval, order
status transitions, payroll run/lock, expense approval, stock adjustments and
transfers confirmation, any settings write.

Queue-and-sync permitted: clock in/out punches (already so in the console),
on-shift trail points, to-do ticks, course progress, form drafts, expense
drafts with attachments (submitted only online), QC photo capture (submitted
online).

Read cache: everything the user has opened, encrypted at rest, scoped to the
signed-in user, purged on sign-out or token revocation.

## 6. Gaps to close in the backend before clients can be "truly native"

| # | Gap | Severity | Note |
|---|---|---|---|
| B1 | No APNs/FCM sender; only Web Push + Expo relay | High | Add `token_type` `apns`/`fcm` and a sender; keep Expo until the old shell is retired. |
| B2 | No refresh/renewal endpoint; 7-day access token only | High | Decide: sliding renewal endpoint vs refresh token. Consequential (auth) — needs approval. |
| B3 | No OpenAPI specification | High | Generate from routes + FormRequests; contract for both client codebases. |
| B4 | Reverb is live; native clients need a Pusher-protocol WebSocket client and the `/broadcasting/auth` handshake | Low | Verified. |
| B5 | No response envelope or API Resources; payload keys vary per endpoint | High | Native typed models need a contract. Option: OpenAPI generated from current shapes (no backend change) + Resources introduced incrementally. |
| B6 | Device registry exists (`X-Device-Id`, `admin/profile/devices*`) but push tokens are registered separately and only as webpush/expo | Medium | Add `platform`, `model`, `apns`/`fcm` token to the device record. |
| B7 | CSV export dead client-side (Q-5 in `SYSTEM_AUDIT_AND_ROADMAP.md`) | Medium | Backend endpoints exist; native clients can use them directly. |
| B8 | `sort_by` SQL injection noted in roadmap SEC-3 | Closed | VERIFIED: `OrderController::index` resolves sort through the `SortResolver` allow-list (`OrderController.php:208`). |
| B9 | VAPID private key committed as inline default in `docker-compose.yml:80` (backlog 2026-08-08) | High | Rotate and move to env before any store release. |
| B10 | `POST /api/v1/pay/{token}/mpesa-callback` is public with only a throttle | Medium | VERIFIED: no IP allow-list or signature; the handler only acts when the order's `payment_token` matches **and** the callback's `CheckoutRequestID` matches a pending payment's `provider_reference` (`PublicPaymentController.php:180-196`). Spoofing needs both secrets. Hardening (Safaricom IP allow-list) is a backend decision, not a client blocker. |
| B11 | Deep links declared (`bethanyhub://`, Android `autoVerify` for `hub.bethanyhouse.co.ke/admin*`) but never handled; no iOS associated domains; no `assetlinks.json` / `apple-app-site-association` served | Medium | Serve both well-known files from the Hub host; native apps handle links. |
| B12 | Money fields returned as JSON strings from four endpoints (backlog) | Medium | Typed clients must parse decimals as strings everywhere; never as float. |
| B13 | Mobile shell: `trail` posts copy the bearer token out of `localStorage` into SecureStore; `open-external` never sent; `trail:state` never read by the page | Low | Moot once native. |

## 7. Environmental blockers (stated now so nothing is over-claimed later)

- No Xcode / Swift toolchain in this environment: Swift sources can be written
  and structurally checked, but **cannot be compiled, run or signed here**.
- No Android SDK: Kotlin/Gradle sources can be written; a compile requires the
  SDK to be installed (feasible if network policy allows `dl.google.com`).
- No Apple Developer / Play Console credentials in the repo (correct; must
  never be committed).
- Store builds, TestFlight and Play tracks therefore remain owner actions.

## 8. Open questions for the owner

1. Should a Mac be allowed to clock in (no geofence proof)? Current console
   permits browser geolocation; policy for desktop is UNKNOWN.
2. Does the Samsung tablet replace the current door kiosk device?
3. Retire the Expo shell at first native release, or run both for a period?
4. Token renewal policy (B2): sliding 7-day renewal vs refresh tokens, within the per-role idle/absolute limits already enforced.
5. Which of the 2026-08-08 backlog items (M-Pesa callback auth, VAPID key, money-as-string) must close before the first store release?


---

## Appendix A — Console defects found during the audit (not fixed; for the backlog)

| # | Finding | Evidence |
|---|---|---|
| C1 | `/quote/:token` is unreachable: the public-router test only matches `pay\|track\|order\|handoff`, and the admin router has no `/quote` route, so the link falls to `HomeRedirect`. | `react-admin/src/App.tsx:340` |
| C2 | Dead "My Profile" links to `/profile`; the route is `/settings/profile`. | `pages/me/MePage.tsx:45`, `pages/dashboard/DashboardPage.tsx:1059,1071` |
| C3 | Offline POS sale replay exists in the service worker (`pos-sales` store, `pos-sale-sync` tag) but nothing ever writes to that store. | `react-admin/src/sw.ts` |
| C4 | No dark mode: `darkMode: 'class'` is configured, no `dark:` class is used anywhere. | `tailwind.config.js` |
| C5 | Mismatched API base defaults (`usePdfDownload`, `echo.ts` → `http://localhost:8000`; axios → `/api`). | `hooks/usePdfDownload.tsx`, `lib/echo.ts` |
| C6 | No barcode/QR scanning anywhere (no `BarcodeDetector`, no scanner lib). | search |
| C7 | 401 always signs out; there is no renewal, so a 5-minute idle clerk re-enters a PIN and a 7-day token simply dies. | `api/client.ts:161` |

## Appendix B — Client contract the apps must honour (verified)

- Headers on every request: `Authorization: Bearer`, `X-Device-Id` (16–64 chars `[A-Za-z0-9-]`), `X-Device-Touch`, `X-Background-Request: 1` for polling.
- Status handling: 401 → signed out; 423 `pin_locked` → PIN unlock then retry (≤3); 403 `step_up_required` → password/TOTP/passkey step-up then retry (≤2); 403 `download_approval_required` → request-approval flow; 429 → `retry_after_seconds`; 404 `{reason: deleted|not_found}`; 503 `{reason: report_timeout}`.
- Login: three response shapes (plain, `requires_2fa`, `requires_2fa_setup`). Session policy per role is returned in `/me` as `session_policy` and must drive client idle timers.
- Realtime: Reverb (Pusher protocol); channels `user.{id}`, `thread.{model}.{id}`, `channel.{id}`, `presence.channel.{id}`; auth `POST /api/broadcasting/auth`.
- Files: 5-minute relative signed URLs; every staff file passes the download gate (watermarked; approval-gated except invoice/quotation/receipt).
- PDF types: purchase-orders, grn, purchase-returns, orders, returns, shipments, production-orders, stock-transfers, stock-adjustments, expenses, quotations, payslips.
- Money: never computed client-side; decimals arrive as strings on some endpoints (backlog) and must be parsed as decimal, never float. Mixed-currency totals come as `{kes_total, by_currency[], unconverted[]}`.
- Offline today: one pending punch slot; tailor task status/progress queue replayed by Background Sync; nothing else.

## Appendix C — Permission catalogue (139 slugs, `app/Console/Commands/SyncPermissions.php`)

Dashboard: dashboard.view. Orders: orders.view/create/edit/edit_items/cancel/refund/set_shipping_fee/reduce_shipping_fee/set_deposit/authorize_dispatch/manage_returns. Quotations: quotations.view/create/issue/delete. Payments: payments.view/record/upload_proof/approve_international/transactions/void/reassign/request_void/request_reassign. Approvals: approvals.finance_sign/super_sign. Production: production.view/raise_order/confirm_order/manage_assignees/configure_auto_assignees/submit_qc/approve_qc/worker/delete_order/view_bom, bom.edit. Shipments: shipment.view/create/edit/manage_tracking. Customers: customers.view/create/edit/delete/create_without_email/invite/insights. Procurement: procurement.view/create/approve/receive/pay/pay_void. Inventory: inventory.view/adjust/transfer/approve. Catalogue: products.view/create/edit/delete/import/export/view_cost/edit_cost. POS: pos.access/discount/discount_override/discount_campaign/void/open_register/close_register/returns/approve_reversal/cash_management/eod_review/till_verify/reconcile/till_correction/tills_view_all. Marketing: marketing.view/manage. Intelligence: intelligence.view. Receivables: receivables.view. Reports: reports.executive/sales/customers/financial/production/inventory/procurement/performance/signals/data_quality/explorer/export/export_supply. Expenses: expenses.view/create/edit/delete/approve/export/budgets. Outlets: outlets.view/create/edit/delete/all_access. Settings: settings.view/edit/manage_database/manage, setup.technical, settings.financial_propose/pricing_rate_propose. Users & roles: users.view/create/edit/delete, roles.view/edit. Profile: profile.view/edit. Notifications: notifications.view. Attendance: attendance.view_team/manage. People: hr.view/edit/view_sensitive, forms.manage, updates.post, roster.manage, timesheets.lock, leave.approve, payroll.view/run.

Roles (tier, data scope): super_admin (0, all, `*`), admin (1, all), finance_manager (1, all), system_admin (1, all), outlet_manager (2, **outlet**), accountant (2, all), procurement_manager (2, all), production_manager (2, all), pos_clerk (3, **own**), tailor (3, **own**), procurement_officer (3, all), customer. Effective permissions = union of role grants + direct grants, dependency-resolved, minus `ROLE_DENIES`, minus money pages per `FinancialVisibility`. Outlet scope comes from the `outlet_user` pivot (there is no `users.outlet_id`).

## Appendix D — State machines the clients must render (verified)

| Entity | States | Transition endpoints |
|---|---|---|
| Order | pending → processing/confirmed/shipped/delivered/completed/cancelled; delivered/completed → refunded; cancelled, refunded terminal (`OrderStatusMachine`) | `PUT orders/{id}/status`, `POST .../void`, `.../refund`; POS void via approval engine |
| Order.payment_status | pending, unpaid, pending_approval, partial, deposit, paid, refunded | server-derived |
| Payment | pending → processing → paid/failed; voided; `approval_status` pending_review → approved/rejected | `PaymentController`, `PaymentApprovalController`, webhooks |
| Expense | draft → pending_approval → approved/rejected/changes_requested → paid; cancelled | `expenses/{id}/submit\|approve\|reject\|request-changes\|mark-paid\|cancel`, approval engine |
| ApprovalRequest | pending → approved/rejected/expired/cancelled; events: purchase_order, stock_adjustment, serialized_write_off, expense, imprest_topup, payment_void, payment_reassign, stock_transfer, pos_void, pos_refund | `approvals/{id}/sign\|reject\|resubmit`, `pos/approvals/{id}/pin-sign` |
| ChangeProposal | pending → scheduled → applied; rejected/expired/cancelled | `proposals`, `/preview`, `/{id}/withdraw` |
| LeaveRequest | pending → approved/declined; cancelled | `leave/requests/{id}/approve\|decline`, `me/leave` |
| PayrollRun | draft → approved (creates Expense) → paid | `payroll/runs/{id}/approve\|pay\|recalculate` (step-up) |
| CashRegister (till) | open → counted → closed+finalized; then reconcile (matched/mismatch) and corrections; finalized fields DB-locked | `pos/register/open\|close`, `pos/tills/{id}/finalize\|reconcile\|corrections` |
| InventoryTransfer | pending → approved/rejected/returned(72h) → in_transit → completed; cancelled; resubmit | `inventory/transfers/{id}/approve\|dispatch\|receive\|cancel` |
| PurchaseOrder | draft → pending_approval → approved → ordered → partially_received → received → completed; cancelled/rejected | `purchase-orders/{id}/submit\|approve\|reject\|cancel\|receive\|return\|revise` |
| ProductionOrder | draft → pending → in_progress → on_hold/completed; cancelled | `production-orders/{id}/confirm\|assign\|qc\|rework\|complete\|cancel` |
| Timesheet / PayPeriod | draft → submitted → sent_back/approved; period open → locked | `timesheets/person/{u}/approve\|send-back`, `pay-periods/{id}/lock\|unlock` |
| DownloadRequest | auto/held → pending → approved/denied → downloaded; expired/cancelled | `downloads/requests/{uuid}/approve\|deny\|cancel\|token` |
| Shift / swap | draft → published → cancelled; swap pending → approved/declined/withdrawn | `roster/shifts`, `roster/swaps/{id}/approve\|decline` |

## Appendix E — Per-screen action inventory

The console agent's screen-by-screen action and endpoint list (every button, form and API path, by module) is reproduced in `docs/NATIVE_APPS_SCREEN_INVENTORY.md`.
