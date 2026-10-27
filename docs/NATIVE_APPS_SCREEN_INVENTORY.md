# Native apps programme — screen-by-screen action and endpoint inventory

Companion to `NATIVE_APPS_AUDIT.md`. Verified 2026-10-27 against `react-admin/src`
(routes in `App.tsx`, actions from each page's button/label strings, endpoints
from `src/api/*.ts` and the pages). Paths are under `/api/v1/admin` unless shown
with `/v1/`. Every native screen must offer every action listed here for a user
who holds the gate; the audit's F/A/R code says only how it is presented.

## auth
- LoginPage: sign in, verify TOTP, staged 2FA setup, send reset link, reset password.
- `auth/login`, `auth/2fa/verify`, `2fa/setup`, `2fa/setup/confirm`, `2fa/recovery-codes`, `forgot-password`, `reset-password`, `me`, `logout`, `pin/unlock`, `step-up`, `step-up/passkey(/options)`, `PUT profile/terminal-pin`.

## dashboard (`dashboard.view`)
- Role-aware greeting; quick actions: New Order, New Customer, New Production Order, Open POS, Receive Goods, Payments to approve.
- `GET dashboard`, `/v1/tailor/tasks`, `reports/dashboard/kpis`. Money window limited to today for non-finance roles (`money.today`).

## approvals (any of 18 approval slugs)
- Tabs: To sign, My submissions, Purchase returns, Payment proofs. Actions: Approve, Reject with reason, Sign, Resubmit, view signed files.
- `approvals/inbox`, `approvals/mine`, `approvals/{id}/sign|reject|resubmit`, `payments/pending-approval`, `payments/{id}/approve|reject`, `purchase-returns/{id}/approve|reject`.

## notifications (`notifications.view`)
- List, mark read, mark all read, delete. `notifications`, `/{id}`, `/{id}/read`, `/read-all`, `/unread-count`.

## comms (ungated)
- Spaces, DMs, conversations; add/remove members; archive; send, edit, delete, forward, react; file paste/upload (compressed); quick replies; AI assist rail; entity chips (orders, production orders); search; translate; long-press action sheet.
- `channels`, `channels/{id}`, `/messages(/{msg}, /forward)`, `/members`, `/read`, `/dismiss`, `/undismiss`, `/assist`, `channels/dm`, `/context`, `/search`, `/entity-search`, `/quick-replies`, `/audiences`, `comments`. Realtime: `channel.{id}`, `presence.channel.{id}` typing whispers.

## me (ungated)
- Today (MePage): clock card, must-reads, shifts, leave balance, hours, pay, to-dos, colleagues, checklists due, handbook, training, to sign, advances, my file; managers also see team-now and requests waiting.
- ClockPage: clock in, clock in with override (`attendance.manage` or `outlets.all_access`), clock out, start/end break, send pending offline punch; live distance and geofence state; outlet choice; flags accuracy_low, edge, sent_late, mock_location, auto_clock_out.
  - `time-clock/status|outlets|clock-in|clock-out|break/start|break/end|my-entries`, `time-clock/trail` (native background trail).
- ShiftsPage: claim open shift, swap, withdraw. `me/shifts(/{id}/claim|swap)`, `me/swaps/{id}`.
- TimesheetPage: submit hours, request a fix. `me/timesheet(/submit, /fixes)`.
- PayslipsPage: download PDF. `me/payslips(/{line}/pdf)`.
- AdvancesPage: request, withdraw. `me/advances`.
- LeavePage: request, preview, withdraw. `me/leave(/preview)`.
- SignPage: sign (signature pad) or decline. `me/sign`, `me/sign/{id}/sign|decline`.
- My file: `me/file(/sensitive)`, `me/directory`.

## updates (ungated; posting needs `updates.post`)
- New post/update/event, read, acknowledge must-read, RSVP, comments, photo, readers, scheduled posts, remove.
- `updates(/{id}/read|acknowledge|rsvp|comments|photo|readers, /people, /scheduled)`.

## forms
- FormsPage (ungated): today's checklists, fill in and send, signature field. `forms/today|mine|overview`, `forms/submissions(/{id}, /{id}/file/{key})`.
- FormsManagePage (`forms.manage`): new form, edit, remove items, view submissions. `forms/templates` CRUD.

## handbook (ungated; write needs `forms.manage`/`updates.post`)
- New, edit, publish, remove. `handbook` CRUD.

## courses (ungated; authoring gated)
- New course, edit, publish, lessons and questions, mark lesson done, take quiz. `courses(/{id}, /progress, /lessons/{l}/done, /quiz)`.

## directory / colleagues (ungated)
- Edit own entry, start a DM. `GET directory`, `PUT me/directory`.

## todos (ungated)
- New, mark done, remove. `todos` CRUD, `todos/people`.

## people (`attendance.view_team`, `hr.view`, `hr.edit`, `hr.view_sensitive` + step-up)
- OverviewPage: team now, open shifts. `people/overview`.
- RequestsPage: decline, mark corrected (timesheet fixes, leave, swaps, advances). `people/requests(/count, /fixes/{id}/{decision})`.
- PeoplePage / PersonPage: employee file edit, sensitive card, documents upload/remove, pay structure, leave, training, signing requests, timesheet. `people/{id}(/sensitive, /documents, /signing, /leave, /pay)`, `people/documents/expiring`.
- AttendancePage (`/settings/attendance`): who is in now, flagged entries, kiosk photo, trail map, kiosks setup. `attendance/entries|live|flagged|kiosks|recognition-points`, `attendance/entries/{id}/trail`.
- Attendance rules, automations: `attendance/rules|automations`.

## roster (`attendance.view_team`; write `roster.manage`)
- Add shift, add open shift, edit, remove, copy last week, publish, templates, swap approve/decline.
- `roster`, `roster/shifts` CRUD, `/copy-week`, `/publish`, `/templates`, `roster/swaps(/{id}/approve|decline)`.

## timesheets (`attendance.view_team`; lock needs `timesheets.lock` + step-up)
- Approve, send back, lock/unlock period, pay groups, export CSV.
- `timesheets(/person/{u}/approve|send-back, /export)`, `pay-groups`, `pay-periods/{id}/lock|unlock`.

## leave (`attendance.view_team`; decide needs `leave.approve`)
- Approve, decline, edit types, record for someone, record as approved.
- `leave/requests(/{id}/approve|decline)`, `leave/types`, `leave/calendar`, `leave/adjustments`.

## payroll (`payroll.view`; writes `payroll.run` + step-up)
- Tabs: Runs, Advances & loans, Returns, Statutory rates. New run, recalculate, approve, pay, payout files, payslip, rates confirm, advances approve/decline/payout/repay.
- `payroll/runs(/{id}/approve|pay|recalculate, /payout, /payout-readiness, /lines/{l}/payslip)`, `payroll/advances/*`, `payroll/rates(/{id}/confirm)`, `payroll/returns`.

## kiosk (kiosk token)
- Pick name, PIN, clock in/out with front-camera snapshot, leave kiosk. `kiosk/board`, `kiosk/punch`, `DELETE kiosk/token`. Setup: `POST attendance/kiosks` (manager, step-up).

## catalogue (`products.view`; create `products.create`; cost `products.view_cost`/`edit_cost`)
- ProductsListPage: edit, delete, archive filter, CSV bulk import (template download, upload).
- ProductFormPage tabs: Content, Images (upload/remove/reorder/primary, video), Pricing (per currency; changes via proposals), Variants (generate from attributes, add/delete), Features, SEO, Measurements, Production Stages, Bill of Materials (new BOM version). Publish, archive.
- CategoriesPage: create/edit/delete, subcategory, drag reorder, image, toggle active.
- `products(/{id}, /archive, /publish, /video, /images(/{img}/primary, /reorder), /variants, /tax-rates, /bom(/{b}/activate))`, `products/bulk-import`, `products/export-template`, `categories(/{id}/image, /toggle, /reorder)`, `product-stages`, `proposals`.

## pos (`pos.access`; per-action `pos.*`; owner may watch but not transact)
- Register: pick outlet, open, close, submit count, morning check. `pos/register/open|close|status|history`, `pos/outlets`.
- Cart: product search, customer search/add/reveal, made-to-order flag, shipping, pending orders. `pos/products(/search)`, `pos/customers/search`, `customers/{id}/reveal`, `pos/suggestions`, `pos/shipping-methods`, `pos/checkout-config`, `pos/pending-order(/open, /{id}/pay)`.
- Payment: split tender, M-PESA STK push + Daraja verify, Paystack. `pos/sales`, `orders/{id}/payment`, `/payments/{p}/verify-mpesa`.
- After sale: receipt print, email receipt. `pos/sales/{id}/email-receipt`, `/dispatch`.
- History & returns: void / request void, refunds, returns; approver PIN. `pos/sales/{id}/void`, `/reversals`, `pos/returns`, `pos/approvals/{id}/approvers|pin-sign`.
- EoD: submit user EoD, print. `pos/reports/daily|user-eod|eod-admin(/{id}/acknowledge)|eod-settings(/test)`, `pos/reports/eod/{id}/comments`.
- TillsPage: verify & finalize, reconcile against payments, record correction. `pos/tills(/{id}/reconcile|finalize|corrections)`.
- OutstandingBalancesPage (`receivables.view`): `pos/outstanding-balances`.

## sales (`orders.view`, `quotations.view`, `customers.view`, `shipment.view`; contacts masked)
- OrdersListPage (till/web/chat/quoted/all): confirm, mark processed, mark completed, generate & copy payment link. PendingQueue: confirm, open Neema chat.
- OrderDetailPage: status changes, add/remove items, price changes, set deposit, shipping fee, currency; attach/add customer; record payment, upload proof, verify M-PESA/Paystack, reverse payment, refund, void; invoice; download order/invoice PDF; share PDF; print; resend confirmation; payment link; notes; comments thread; send to production; shipments; revisions.
- `orders(/{id}/status|items|items/{i}/price|items/{i}/production|payments|payments/{p}/reverse|verify-mpesa|verify-paystack|refund|void|invoice|notes|payment-link|set-deposit|shipping-fee|update-currency|attach-customer|resend-confirmation|audit-log, /export, /pending-queue, /shipping-methods)`, `payments/{id}/upload-proof`.
- QuotationsPage: create, edit, revise, delete, void, email, copy customer link, reprice. `quotations(/{id}/revise|revisions, /reprice)`.
- InvoicesPage: void, copy pay link. `invoices/{id}(/void)`.
- InterestCartsPage: read-only. `interest-carts`.
- CustomersPage/Detail: create, edit, delete, status, invite to portal, CSV, Google Contacts export. `customers(/{id}/status|orders|invite-to-portal, /quick-create, /export/google-contacts)`.
- Shipments: copy tracking link, add tracking event, edit, attachments, mark delivered, cancel, PDF. `shipments/{id}(/cancel|mark-delivered|attachments|audit-log)`.

## production (`production.view`; worker `production.worker`; QC `submit_qc`/`approve_qc`; BOM `view_bom`/`bom.edit`)
- ProductionPage: create production order, assign tasks, generate tasks from stages, BOM create/new version, QC pass/fail, complete & stock.
- ProductionOrderDetailPage: assign tailors, batches and photos, materials, QC, send back for rework, complete & stock, cancel, confirm, force, delete, measurements, order chat (Echo).
- TailorWorkspacePage (My Tasks): start, pause, done, note, piece counts, unlock, haptics, offline queue.
- ProductionCalendarPage: schedule.
- `production-orders(/{id}/assign|batches|batches/{b}/images|materials|qc|rework|complete|cancel|confirm|force|messages|regenerate-tasks|audit-log)`, `production-tasks`, `production/schedule`, `/v1/tailor/tasks(/{id}/status|progress|note|unlock)`.

## inventory (`inventory.view`; writes `inventory.adjust`/`transfer`/`approve`)
- Stock levels: opening stock, history. Serials: reconcile. Adjustments: new, submit, approve, reject, reverse, PDF, audit log. Raw materials: CRUD, receive, adjust, opening. Transfers: new, approve, dispatch, receive, cancel, PDF. Low stock: edit threshold.
- `inventory/stock-levels(/{id}, /history, /by-product/{p}, /opening)`, `inventory/adjustments(/pending, /reason-codes, /{id}/approve|reject|reverse, /audit-log)`, `inventory/materials(/{id}/receive|adjust|transactions, /low-stock)`, `inventory/transfers(/{id}/approve|dispatch|receive|cancel, /audit-log)`, `inventory/low-stock-alerts(/{id}/threshold)`, `product-serials(/reconcile)`.

## procurement (`procurement.view`; `create`/`approve`/`receive`/`pay`/`pay_void`)
- Suppliers: CRUD, rating, export, contacts, documents, performance. POs: create, variant grid, from suggestions; submit, approve, reject, cancel, receive, return, revise, PDF, record supplier invoice, supplier payments record/void. GRN: record, print/PDF, corrections, revisions. Returns: new, approve, reject, complete, PDF.
- `suppliers(/{id}/contacts|documents|performance|purchase-orders|rating, /export)`, `purchase-orders(/{id}/submit|approve|reject|cancel|receive|return|revise|status|revisions|audit-log|payments, /statistics, /payables, /currencies, /from-suggestions, /item-costs/{p})`, `grn(/{id}/pdf|print|corrections|revisions|audit-log)`, `purchase-returns(/{id}/approve|reject|complete|audit-log)`.

## expenses (`expenses.view`; `create`/`edit`/`approve`/`budgets`/`export`)
- ExpensesPage: new, submit, approve, reject, request changes, mark paid, pay from imprest, bulk. Detail: edit, submit, reject, request changes, mark paid, PDF, attachments, receipt camera capture. Categories & budgets CRUD. Analytics + share. Imprest: request top-up, mark sent, confirm received, decline, record count, approve adjustment.
- `expenses(/{id}, /submit, /approve, /reject, /request-changes, /cancel, /mark-paid, /receipt, /attachments)`, `expenses/bulk|summary|suggestions|categories|budgets`, `expenses/imprest/{id}(/counts, /topups, /topups/direct)`, `imprest/topups/{uuid}/send|receive|decline|cancel`, `imprest/counts/{id}/decide`, `expenses/{id}/imprest-resolution`.

## finance (`payments.transactions`)
- Ledger with analytics: request void, request reassign, refund, export. `payment-transactions(/analytics, /export, /{id}/void-request|reassign-request|refund)`.

## marketing / home-front / insights / intelligence
- Seasons, campaigns CRUD (`marketing.view`/`manage`): `marketing/seasons`, `marketing/promotions`.
- Home page blocks and product page sections (add/edit/delete/copy, image upload): `marketing/banners(/{id}, /{id}/image)`.
- Insights (`reports.customers`): `analytics/overview?days=`.
- Signals (`reports.signals`): churn risk, reorder suggestions, smart tasks, budget warnings, material shortages (+preflight), tailor workload, auto-reorder. `intelligence/*`.

## reports (one `reports.<page>` per page; export `reports.export`)
- Shared: date presets, outlet filter, export CSV, download PDF, print, drill-down "records behind this number", scheduled reports.
- Customers tabs: ltv, retention, secondpurchase, winback, outreachlog, institutions, geography, channels, neema. Finance tabs: pl, cashflow, expenses, tax, trends, intelligence, replenishment.
- `reports/*` (executive, engine-room, outcomes, dashboard/kpis, sales/*, customers/*, financial/*, inventory/*, production/*, costing/{id}, purchase-orders, performance, explorer(/orders), data-quality, drill/{metric}, schedules CRUD, win-back(/outreach), outlets).

## setup
- Business (+logo), countries (+regions), currencies (rates via proposals, default, toggle), languages, tax rates, payment methods (+config), outlets (+statistics), roles (+permissions, duplicate), users (create, edit, status, reset password, assign outlet, promote to staff, sign-in security: sessions, devices, 2FA reset, PIN reset, unlock), shipping zones/methods, discount limits (owner), holidays, production stages (reorder), auto-assignees, activity logs (filters, integrity, export), trash (restore, restore all, delete forever, empty), database (backups download/delete/restore, clear, schedule, storage test).
- `settings(/logo)`, `countries`, `currencies-management`, `languages`, `tax-rates`, `payment-methods-management`, `outlets`, `roles`, `permissions`, `users(/assignable, /role/{r}, /{id}/sessions|devices|2fa/reset|terminal-pin/reset|unlock)`, `shipping/zones|methods`, `settings/discount-limits`, `holidays`, `product-stages`, `production/auto-assignees`, `activity-logs`, `trash/{model}...`, `database/*`.

## profile (ungated)
- Tabs: Profile, My employment, Password, 2FA, Sessions, Preferences, My activity. Avatar, change password, enable/confirm/disable 2FA, recovery codes, passkeys add/remove, devices rename/remove, revoke session(s), terminal PIN.
- `profile(/avatar, /password, /activity, /sessions(/{t}/revoke, /revoke-all), /devices, /passkeys(/options))`, `auth/2fa/enable|confirm|disable`.

## downloads
- My downloads; approvals (approve, decline, approvers, archived copy). `downloads/capabilities|mine|all`, `downloads/requests(/{uuid}/approve|deny|cancel|token)`, `downloads/{uuid}/archive`, `downloads/approvers`.

## shared
- Command palette: `GET search?q=&types[]=`. Proposals: `proposals(/preview, /{id}/withdraw)`. Comments: `comments(/users)`. Signed files: `/v1/files/*`. PDFs: `pdf/{type}/{id}(/invoice|/receipt)`.
