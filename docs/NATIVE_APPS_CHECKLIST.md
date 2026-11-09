# Native apps — implementation checklist

Durable, per-screen status across the five targets. Codes: **N** native
screen registered in the app; **B** reachable through the console bridge
(signed-in web page inside the native shell, not yet ported); **—** not
offered on this target (reason in the audit's parity matrix). "Verified"
means run on a device or simulator, which has not happened for any row yet
(no Xcode or Android SDK in the authoring environment).

Last updated: 2026-10-29. Android phone and tablet columns follow
`docs/NATIVE_APPS_ANDROID.md` (Step 6, merged in #516; kiosk #520). The iPhone
and iPad columns have not been reconciled with the HubMobile target since
Step 3: UNKNOWN — NEEDS VERIFICATION against an Xcode build.

## Shell and cross-cutting

| Capability | macOS | iPhone | iPad | Android | Tablet |
|---|---|---|---|---|---|
| Sign-in, 2FA, forced 2FA setup, password reset | N | — | — | N | N |
| Session policy mirror (idle, PIN lock, renewal) | N | — | — | N | N |
| Step-up (password, TOTP, passkey) | N | — | — | N (passkey: server fallback, A1) | N (passkey: server fallback, A1) |
| Biometric re-lock | N (Touch ID) | — | — | N | N |
| Sidebar by permission, home path, ⌘K palette, ⌘1–9 | N | — | — | N | N |
| Deep links (bethanyhub://, universal links) | N | — | — | N | N |
| APNs registration and tap-to-route | N | — | — | N (FCM) | N (FCM) |
| Reverb realtime (user channel → badge, toast) | N | — | — | N | N |
| Offline read cache, pending queue, replay | N (kit) | — | — | N | N |
| Download gate (approval hold) | N | — | — | N | N |

## Screens

| Screen | macOS | iPhone | iPad | Android | Tablet |
|---|---|---|---|---|---|
| Dashboard | N | — | — | N | N |
| Approvals (inbox, mine, sign, reject, resubmit) | N | — | — | N | N |
| Notifications | N | — | — | N | N |
| Messages (spaces, DMs, realtime, attachments, reactions, quick replies, entity chips) | N (AI assist rail: B) | — | — | N (AI assist rail: B) | N (AI assist rail: B) |
| Me: Today | N | — | — | N | N |
| Me: Updates, Checklists, Handbook, Training, To sign, To-dos, Colleagues | N | — | — | N | N |
| Me: Clock in/out (geofence; trail iOS-only), shifts, leave, hours, payslips, advances | N | — | — | N | N |
| Profile (sessions, devices, password) | N (2FA/passkey/PIN self-management: B) | — | — | N (same: B) | N (same: B) |
| People: overview, requests, attendance (trail map, kiosks), roster, timesheets, leave, employee files, payroll, checklist templates | N | — | — | N | N |
| Catalog: products list, product form (images, pricing via proposals, variants, SEO, stages, BOM), bulk import, categories | N | — | — | N | N |
| POS (register, sale, split tender, M-PESA/Paystack, receipt print, history, voids/returns via PIN, user EoD) | N | — | — | N | N |
| Tills (verify/finalize, reconcile, corrections, per-currency) and EoD reports/settings | N | — | — | N | N |
| Sales: order lists, pending queue, order detail (payments, items, proposals, shipments, comments, PDFs), quotations, invoices, interest carts, shipments, customers, balances | N (handoff: B) | — | — | N (handoff: B) | N (handoff: B) |
| Production: my tasks (offline queue), orders, order detail, WIP, QC, calendar, BOM | N | — | — | N | N |
| Inventory: stock, serials (scan on iOS), adjustments, transfers, materials, low stock | N | — | — | N | N |
| Procurement: POs (detail, receive, return, payments), suppliers, GRN, returns | N | — | — | N | N |
| Expenses (list, detail, approvals, attachments/receipt capture, bulk), imprest, categories and budgets, analytics | N | — | — | N | N |
| Marketing: seasons, campaigns, home front, product pages, insights | N | — | — | N | N |
| Finance: payment transactions ledger (refund, void/reassign requests, export) | N | — | — | N | N |
| Reports (11 pages with tabs, drill-down, PDF, CSV, print, schedules; signals with actions) | N | — | — | N (print: system PDF viewer, A2) | N (print: system PDF viewer, A2) |
| Setup: business, outlets (geofence map), attendance rules, automations, holidays, roles matrix, users and sign-in security, countries, currencies (pricing vs reporting rate), languages, taxes, discount limits, payment methods, shipping, stages, auto-assignees, activity logs, trash, database, downloads | N (database wipe deliberately omitted) | — | — | N (database wipe deliberately omitted) | N (database wipe deliberately omitted) |
| Kiosk (door tablet: board, PIN pad, photo, Guided Access / screen pinning; token never shown) | — | — | N | N | N |

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
| F2 | POS payment modal sends the M-PESA verification code under `code`; the server validates `transaction_code` (the order-detail path sends the right key). | `react-admin/src/pages/pos/components/PaymentModal.tsx`, `PaymentController::verifyMpesa` | Apps send `transaction_code`. Confirmed 2026-10-29: the receipt was never checked from the till or the order page, and a failed check recorded the payment unasked. Fixed in the console: the receipt goes through `ordersApi.verifyMpesa`, "asked Safaricom" shows as waiting, and an unconfirmed code is recorded only on the cashier's explicit choice (then the M-Pesa method's approval rule applies). Pinned by `MpesaVerifyOnResultTest`. |
| F3 | `GET suppliers/export` answers JSON with a `// TODO: Generate Excel/CSV`. | `SupplierController` | Saved as `.json`. |
| F4 | `pos/reports/eod-admin/{id}` detail totals were raw sums across currencies, counted carts as sales and used a hand-written paid rule, so the list and the detail stated the same day differently. | `PosController` | Fixed 2026-11-04: the list, the detail and the cashier's screen share `PosController::eodDayOrders` (SettledPayment net of refunds, recognised sales, `MoneyByCurrency`). Carts stay listed with their notes, marked not a sale. Pinned by `EodReportDetailTest`. Console and both apps label each order in its own currency. |
| F5 | `pos/reports/user-eod` totals carried no currency key and had the F4 defect (found by the 2026-11-04 alignment check). | `PosController` | Fixed with F4: the cashier submits the same figures her supervisor reviews (`EodReportDetailTest`). |
| F6 | `GET orders/export` is gated `orders.view` + step-up only; the console's CSV button is a no-op (backlog Q-5). | `routes/api.php` | Native export works through the same route. |
| F7 | Supplier document rows carry no download URL. | `SupplierController::documents` | Listed, not opened. |
| F8 | `/auth/me` sends `first_name` and `last_name`, not `name`; `Me(json:)` required `name` and threw, so sign-in would fail against the real backend. Found by the Android live render check (2026-10-28). | `AuthController::me` payload | Fixed: display name built from first and last name as the console does, `name` kept as a fallback; contract test added. Swift unverified until an Xcode build. |

## Server changes since the ports (checked 2026-11-04, #523–#532)

| PR | Change | Native apps |
|---|---|---|
| #523 | Rejecting a return no longer answers 500 | Same request and reply; nothing to change. |
| #524, #525 | Mukuru, Western Union / MoneyGram, I&M payment methods | Payment methods are read from the Hub; nothing to change. |
| #527 | Customer prices, discounts, shipping and VAT on top are whole units | Decided on the server; the apps show what the Hub returns (till totals come from the order). Nothing to change. |
| #528 | Sales roles see customer contacts in full | Already carried in the receipt screens. |
| #531 | Customer documents show "KES 4,500", not "KES 4,500.00" | Done: `Money.customerFormatted()` on the till receipt (screen and printed) and the order page, both apps; staff screens keep cents. |
| #530 | Message notices, reply accountability (`GET channels/attention`, dismiss) | Not in the apps yet: existing message calls unchanged; the new notices are console only. |
| #532 | Payroll statutory enrolment per person, real paid date, People & pay | Not in the apps yet: `paid_on` is optional, so paying a run still works; the per-person deductions screen is console only. |

## Alignment check (2026-11-04)

A read-only sweep of the backend's money paths, the two apps against the
server's rules, and the docs against the code. What it found, beyond F4/F5
(fixed above), is recorded here so nothing is lost; none of it is fixed yet.
Each row was reported with its file and line by the sweep; only F5 has been
re-checked by hand so far, and AL1's public pay and tracking pages (verified:
`status = 'paid'` only, no approval clause, no refund netting). The rest are
UNKNOWN — NEEDS VERIFICATION before a fix is proposed.

| # | Finding | Where | Kind |
|---|---|---|---|
| AL1 | "Paid" written by hand (`status = 'paid'`, no approval clause, no refund netting) instead of `SettledPayment` / `Order::totalPaid()` | `PublicPaymentController:47`, `PublicOrderController:49-51, 136-137` (customer pay and tracking pages), `OrderController:949, 971, 993, 1567` (completion guards), `ProductionController:2556-2559`, `TillReversals:240`, `DocumentPdfController:312`, `PaymentController::transactionAnalytics` | money |
| AL2 | Native sums across currencies in a share-of-revenue denominator | `MetricEngine::computeInstitutionalAccounts` (~4710) | money |
| AL3 | Corridor KES figures converted at today's rate, not each order's stored rate | `MetricEngine` (~4947) | money |
| AL4 | Legacy Livewire admin still sums across currencies and uses its own status rules (backlog Q-2) | `Livewire/Admin/Pos/EndOfDay`, `Pos/SalesHistory`, `Payments/Transactions`, `Customers/CustomerGroups` | money |
| AL5 | M-Pesa callback: the audit's "needs both secrets" (B10) is wrong; the payer learns the checkout id from `initiate`. Backlog finding #1 | `PublicPaymentController:180-196, 349-352` | security |
| AL6 | Money returned as strings in nested report rows (B12) | `ReportController` 1130-1136, 1177, 1996, 2090-2096, 2565 | contract |
| AL7 | Apps cannot bill a customer in a currency they asked for (`currency_code` + `reason`, owner 2026-10-29) | Android `OrderSheets.kt:403`, Apple `SalesOrderSheets.swift:411` | parity |
| AL8 | Apple sends typed amounts as binary floats; Android sends exact decimals | Apple `SharedUI.swift:301`, `ModuleSupport.swift:171` | contract |
| AL9 | Mocked location: server flags, never refuses; Android refuses, iOS never flags | `TimeClockController:120-122`, Android `MeClock.kt:241`, Apple `MeFeatureClock.swift:295` | policy |
| AL10 | Till "maker ≠ checker" narrower in the apps (opened_by only) than on the server (opened_by and closed_by) | `TillController:172`, Android `TillsFeature.kt:211`, Apple `TillsFeature.swift:180` | UX (server refuses) |
| AL11 | Docs out of date: audit §6/§8 still list B1–B3, B6, B9, B11 and owner questions Q1–Q4 as open though later work answered them; Q-5/B7 CSV export partly working; cross-reference and date slips | `NATIVE_APPS_AUDIT.md`, `SYSTEM_AUDIT_AND_ROADMAP.md`, `AUDIT_BACKLOG_2026-08-08.md`, `NATIVE_APPS_ANDROID.md` | docs |

Confirmed consistent: every app write call sampled (about 25) sends the
keys the server validates, Android and Apple send the same keys, and the
permission gates sampled match `routes/api.php`. No native work went
beyond the plan; everything left out is recorded below.

## Deliberately not ported

- Kiosk mode on Mac and phone: a door device is a tablet. The iPad and Android tablet kiosks (Step 7) need the backend's own kiosk lifetime (#519, `KIOSK_TOKEN_DAYS`, default 90); before it a kiosk token died under the manager's 30-minute idle limit.
- `POST database/wipe`: the most dangerous endpoint; stays console-only until a confirm-phrase + re-auth design is agreed.
- Comms AI assist rail and per-message translate; order handoff page; mention autocomplete in comment threads; drag-and-drop reorder (move up/down buttons call the same endpoints).
