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
| Messages | B | — | — | — | — |
| Me: Today | B | — | — | — | — |
| Me: Updates, Checklists, Handbook, Training, To sign, To-dos, Colleagues | B | — | — | — | — |
| Me: Clock in/out, shifts, leave, hours, payslips, advances | B | — | — | — | — |
| Profile (sessions, devices, password) | N (2FA/passkey/PIN management: B) | — | — | — | — |
| People: overview, requests, attendance, roster, timesheets, leave, files, payroll, checklists | B | — | — | — | — |
| Catalog: products, categories | B | — | — | — | — |
| Sales: POS, order lists, order detail, quotations, invoices, carts, shipments, customers, balances, tills, EoD | B | — | — | — | — |
| Production: my tasks, orders, WIP, QC, calendar, BOM | B | — | — | — | — |
| Inventory: stock, serials, adjustments, transfers, materials, low stock | B | — | — | — | — |
| Procurement: POs, suppliers, GRN, returns | B | — | — | — | — |
| Expenses, imprest, categories, analytics | B | — | — | — | — |
| Marketing, home front, insights, intelligence | B | — | — | — | — |
| Finance: payment transactions | B | — | — | — | — |
| Reports (11 pages, PDF, CSV) | B | — | — | — | — |
| Setup (all pages) | B | — | — | — | — |
| Kiosk | — | — | — | — | — |

## Backend (Step 2, merged in #514)

| Item | Status |
|---|---|
| Session renewal endpoint | DONE, tested |
| APNs + FCM senders, device registration | DONE, tested against faked providers |
| App-link association files | DONE, 404 until identifiers set |
| OpenAPI contract + CI check | DONE |
| VAPID defaults removed | DONE; owner to add fresh pair to server .env |
