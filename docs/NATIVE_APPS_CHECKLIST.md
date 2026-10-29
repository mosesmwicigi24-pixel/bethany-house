# Native apps — implementation checklist

Durable, per-screen status across the five targets. Codes: **N** native
screen registered in the app; **B** reachable through the console bridge
(signed-in web page inside the native shell, not yet ported); **—** not
offered on this target (reason in the audit's parity matrix). "Verified"
means run on a device or simulator, which has not happened for any row yet
(no Xcode or Android SDK in the authoring environment).

Last updated: 2026-10-27 (Step 3, first increment).

## Shell and cross-cutting

| Capability | macOS | iPhone | iPad | Android | Tablet |
|---|---|---|---|---|---|
| Sign-in, 2FA, forced 2FA setup, password reset | N | — | — | — | — |
| Session policy mirror (idle, PIN lock, renewal) | N | — | — | — | — |
| Step-up (password, TOTP, passkey) | N | — | — | — | — |
| Biometric re-lock | N (Touch ID) | — | — | — | — |
| Sidebar by permission, home path, ⌘K palette, ⌘1–9 | N | — | — | — | — |
| Deep links (bethanyhub://, universal links) | N | — | — | — | — |
| APNs registration and tap-to-route | N | — | — | — | — |
| Reverb realtime (user channel → badge, toast) | N | — | — | — | — |
| Offline read cache, pending queue, replay | N (kit) | — | — | — | — |
| Download gate (approval hold) | N | — | — | — | — |

## Screens

| Screen | macOS | iPhone | iPad | Android | Tablet |
|---|---|---|---|---|---|
| Dashboard | N | — | — | — | — |
| Approvals (inbox, mine, sign, reject, resubmit) | N | — | — | — | — |
| Notifications | N | — | — | — | — |
| Messages (spaces, DMs, realtime, attachments, reactions, quick replies, entity chips) | N (AI assist rail: B) | — | — | — | — |
| Me: Today | N | — | — | — | — |
| Me: Updates, Checklists, Handbook, Training, To sign, To-dos, Colleagues | N | — | — | — | — |
| Me: Clock in/out (geofence; trail iOS-only), shifts, leave, hours, payslips, advances | N | — | — | — | — |
| Profile (sessions, devices, password) | N (2FA/passkey/PIN self-management: B) | — | — | — | — |
| People: overview, requests, attendance (trail map, kiosks), roster, timesheets, leave, employee files, payroll, checklist templates | N | — | — | — | — |
| Catalog: products list, product form (images, pricing via proposals, variants, SEO, stages, BOM), bulk import, categories | N | — | — | — | — |
| POS (register, sale, split tender, M-PESA/Paystack, receipt print, history, voids/returns via PIN, user EoD) | N | — | — | — | — |
| Tills (verify/finalize, reconcile, corrections, per-currency) and EoD reports/settings | N | — | — | — | — |
| Sales: order lists, pending queue, order detail (payments, items, proposals, shipments, comments, PDFs), quotations, invoices, interest carts, shipments, customers, balances | N (handoff: B) | — | — | — | — |
| Production: my tasks (offline queue), orders, order detail, WIP, QC, calendar, BOM | N | — | — | — | — |
| Inventory: stock, serials (scan on iOS), adjustments, transfers, materials, low stock | N | — | — | — | — |
| Procurement: POs (detail, receive, return, payments), suppliers, GRN, returns | N | — | — | — | — |
| Expenses (list, detail, approvals, attachments/receipt capture, bulk), imprest, categories and budgets, analytics | N | — | — | — | — |
| Marketing: seasons, campaigns, home front, product pages, insights | N | — | — | — | — |
| Finance: payment transactions ledger (refund, void/reassign requests, export) | N | — | — | — | — |
| Reports (11 pages with tabs, drill-down, PDF, CSV, print, schedules; signals with actions) | N | — | — | — | — |
| Setup: business, outlets (geofence map), attendance rules, automations, holidays, roles matrix, users and sign-in security, countries, currencies (pricing vs reporting rate), languages, taxes, discount limits, payment methods, shipping, stages, auto-assignees, activity logs, trash, database, downloads | N (database wipe deliberately omitted) | — | — | — | — |
| Kiosk | — | — | — | — | — |

## Backend (Step 2, merged in #514)

| Item | Status |
|---|---|
| Session renewal endpoint | DONE, tested |
| APNs + FCM senders, device registration | DONE, tested against faked providers |
| App-link association files | DONE, 404 until identifiers set |
| OpenAPI contract + CI check | DONE |
| VAPID defaults removed | DONE; owner to add fresh pair to server .env |

## Findings surfaced by the Apple port (for the backend/console backlog)

| # | Finding | Where | Native handling |
|---|---|---|---|
| F1 | `POST production-orders/{id}/qc` validates `images[]` but never stores them: QC photos are dropped silently. | `ProductionController::qualityCheck` | QC screen directs photos to batch images, which persist. |
| F2 | POS payment modal sends the M-PESA verification code under `code`; the server validates `transaction_code` (the order-detail path sends the right key). | `react-admin/src/pages/pos/components/PaymentModal.tsx`, `PaymentController::verifyMpesa` | App sends `transaction_code`. UNKNOWN whether the console path ever worked. |
| F3 | `GET suppliers/export` answers JSON with a `// TODO: Generate Excel/CSV`. | `SupplierController` | Saved as `.json`. |
| F4 | `pos/reports/eod-admin/{id}` detail totals are raw sums across currencies while the list uses `MoneyByCurrency`. | `PosController` | Shown as plain figures with a caption. Same defect family as the Step 5 currency fixes. |
| F5 | `pos/reports/user-eod` totals carry no currency key. | `PosController` | Labelled in the outlet's currency. |
| F6 | `GET orders/export` is gated `orders.view` + step-up only; the console's CSV button is a no-op (backlog Q-5). | `routes/api.php` | Native export works through the same route. |
| F7 | Supplier document rows carry no download URL. | `SupplierController::documents` | Listed, not opened. |
| F8 | `/auth/me` sends `first_name` and `last_name`, not `name`; `Me(json:)` required `name` and threw, so sign-in would fail against the real backend. Found by the Android live render check (2026-10-28). | `AuthController::me` payload | Fixed: display name built from first and last name as the console does, `name` kept as a fallback; contract test added. Swift unverified until an Xcode build. |

## Deliberately not ported

- Kiosk mode (door device): out of scope on Mac and phone; a tablet-only mode for Step 5 / Step 7.
- `POST database/wipe`: the most dangerous endpoint; stays console-only until a confirm-phrase + re-auth design is agreed.
- Comms AI assist rail and per-message translate; order handoff page; mention autocomplete in comment threads; drag-and-drop reorder (move up/down buttons call the same endpoints).
