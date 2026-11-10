# Migration progress — native macOS and iPadOS

The running log of the native conversion. Read this, then
`docs/SYSTEM_INVENTORY.md` and `docs/MIGRATION_PARITY_MATRIX.md`, at the start of
every session, and check them against the repository before continuing:

```sh
git log --oneline -15 main
OUT=$(mktemp -d); (cd backend && php artisan route:list --json) > "$OUT/routes.json"
python3 tools/parity/xref.py . "$OUT" && python3 tools/parity/xref_runtime.py . "$OUT" | head -1
```

If the counts differ from the matrix summary, regenerate the matrix before
picking the next slice.

## Standing constraints

- Never touch production: no deploys, live data writes, credential changes or
  migrations without the owner's explicit approval of that specific action.
- The apps talk only to the Hub API with the signed-in person's bearer token;
  no database access, no embedded secrets.
- The apps never compute money; they show the Hub's figures in their own
  currency (`Money` has no arithmetic).
- A console page in a web view (`ConsoleBridgeView`) is not a converted
  screen.
- No Apple status goes above "Implemented but Unverified" until an Xcode
  build and a run on the platform have been done and recorded here.

## History (from `git log`)

| PR | What it did for the Apple apps |
|---|---|
| #513 | Native apps programme: Phase 1 audit, inventory, first parity matrix (`docs/NATIVE_APPS_*`) |
| #514 | Backend contract for the native clients (native apps Step 2) |
| #515 | Native Apple apps: Swift package, macOS and iOS/iPadOS shells, the module screens |
| #521 | Native iPad clock-in kiosk |
| #534 | Customer amounts read as the console reads them (`Money.customerFormatted`) |
| #536 | End-of-day detail and the cashier's own screen state the day by the money rules |
| #543 | The till bills a customer abroad in their currency (Bills in choice) |
| #544 | M-Pesa receipts checked with Safaricom on the order page; five smaller parity fixes |

Console and backend work merged since then that the apps do not yet mirror is
listed as gaps in the matrix (#545–#551: bill currency after payment, owner
corrects or deletes a payment, order discount edit, receipt PDF, super admin
roles).

## Sessions

### 2026-11-10 — directive received; tracking established; slice 1

Done:
1. Inventoried the backend, routes and clients (`docs/SYSTEM_INVENTORY.md`).
2. Built the endpoint cross-reference and committed it (`tools/parity/`).
   Correction found on the way: the first literal-only pass reported 109
   console endpoints missing in Swift. Swift builds many action paths at
   runtime (`/\(action)`), so 36 of those are called; 71 have no Apple caller.
3. Mapped all 152 console paths through `Route.from(consolePath:)`: every
   staff page is native except `handoff(id:)` (web view) and the costing deep
   link.
4. Wrote `docs/MIGRATION_PARITY_MATRIX.md` (94 controller rows, statuses per
   platform, prioritised backlog).
5. **Slice 1 — Payment proofs on Approvals.** The console's Approvals page has
   four tabs; the Apple page had two, so a checker on a Mac or iPad could not
   approve or reject a bank transfer awaiting proof. Added:
   - `BethanyKit/Approvals.swift`: `ApprovalsTab` (each tab gated by the key
     the server checks: To sign = any signing key, Purchase returns =
     `procurement.approve`, Payment proofs = `payments.approve_international`)
     and `PendingPaymentProof` (one row of `payments/pending-approval`, amounts
     in the payment's own currency).
   - `BethanyUI/Features/ApprovalsPanels.swift`: the Payment proofs and
     Purchase returns panels, and the checker's decision sheet (approve with
     optional notes; reject needs a reason). Proofs open through the existing
     signed-link helper. The Hub keeps every rule: maker ≠ checker, the
     order's next state, the money.
   - `WorkspaceFeature.swift`: the Approvals page shows the tabs the person may
     use, opening on To sign for a signer, as the console does.

Verified:
- `ApprovalsTests` (6) pass on the Linux Swift 6.0.3 toolchain, with
  `PermissionsTests` and `CustomerMoneyTests` (16 in total). The row fixture
  is a real `payments/pending-approval` response from a local Hub on a scratch
  database (`bh_e2e`), not an invented one.
- `swiftc -parse` passes for the changed SwiftUI files.

Not verified (and not claimed):
- No Xcode build in this environment and no run on macOS or iPadOS.
  Status: Implemented but Unverified.
- New CI gate `.github/workflows/apple.yml` (macOS runner): `swift build`,
  `swift test`, and unsigned `xcodebuild` of HubMac and HubMobile. It is the
  first thing ever to compile the SwiftUI code. A green build proves
  compilation only.

First Apple CI results (PR #554, Xcode 16.4 / Swift 6.1.2, macOS 15 runner):
- Run 1 failed: 14 compile errors in screens written before this PR (none
  in the new code). They showed the Apple apps had never compiled. Causes:
  a project `TableColumn` struct shadowing SwiftUI's (every macOS table),
  `catch { error = … }` assigning to the catch's own constant (sign-in),
  `await` inside `??` and optional chaining on a non-optional (till load),
  `.frame(width:minHeight:)` (roster), and two views too large to
  type-check (GRN detail, Me "team now"). Fixed in c92c426.
- Run 2: `swift build` passed; `swift test` failed to compile because a
  pure main-actor function was called from a nonisolated test. Made
  `SessionStore.renewalDelay` nonisolated (8a58ac5).
- Run 3 (8a58ac5): **green**. 37 BethanyKit tests pass on macOS; HubMac
  and HubMobile (generic iOS device, which covers iPadOS) report
  `** BUILD SUCCEEDED **`, unsigned. 225 compiler warnings remain, not yet
  triaged.

What this does and does not move: every Apple screen now compiles for
macOS and iPadOS. None has been launched; statuses stay "Implemented but
Unverified" until a screen is run and checked against the console.
- The proof viewer opens the file (macOS) or a share sheet (iPadOS); the
  console previews it in a modal. Recorded as a difference, not a defect.

Noticed, not changed: `PaymentApprovalController::pendingApprovals` says
"ordered oldest first" but orders newest first; the console and the apps show
the server's order. The response also carries `proof_of_payment_path` (a
storage path) that no client uses.

Open items carried over:
- PR #553 (resume the Android APK artifact) awaits the owner's merge.
- The read-only production SQL check in `docs/NATIVE_APPS_CHECKLIST.md` has
  not been run; this environment has no production access.

Next: slice 2 (order money parity with #546/#547) after this slice is
reviewed.

### 2026-11-10 (continued) — #554 merged; slice 2

Owner's mandate received (§19): continue to complete, verified parity on
macOS and iPadOS, Laravel unchanged, a Laravel dependency is raised for
authorization rather than changed.

- #554 merged (99056fd) on the owner's approval, all checks green.
- **Slice 2 — order money parity with #546/#547** (branch
  `apple/order-money-parity`):
  - `BethanyKit/OrderBilling.swift`: when the order page offers "Bill in"
    (an international order with a country, a real choice of the
    customer's own sale currency or USD, not closed, `orders.edit`, and
    after payment has started only the super admin), and who may delete a
    payment (super admin, not already deleted). Mirrors
    `BillCurrencyControl` / `DeletePaymentModal` in OrderDetailPage.tsx.
  - Order page: "Bill in" menu in Totals → `BillCurrencySheet`
    (`POST orders/{id}/bill-currency`, reason required once payment has
    started); "Delete (owner)…" on each live payment → `DeletePaymentSheet`
    (`POST orders/{id}/payments/{p}/remove`, reason ≥ 5); deleted payments
    hidden behind "Show deleted (n)", shown dimmed with date and reason and
    with no actions.
  - Contract checked live on the scratch Hub: removal answers
    `step_up_required` until confirmed, then voids the payment, stamps
    `removed_at`/`removed_reason` and returns the order to `pending`
    (USD 250 balance); `update-currency {country_code: ZM}` then
    `bill-currency {currency_code: USD}` answers "Billed in USD now."
  - `OrderBillingTests` (7) pass on Linux; fixtures are those responses.
  - `orders/{id}/invoice` dropped from the gap list: the console helper
    POSTs to a GET-only route and no page calls it.
- Matrix regenerated: 69 console endpoints with no Apple caller (was 71);
  50 controllers Implemented but Unverified, 37 Partially Implemented.
- **Slice 3 — till hand-over and suggestions** (same branch, PR #555):
  - `BethanyKit/TillHandover.swift`: `OrderDispatch` (a paid, open till
    sale awaits dispatch; `orders.authorize_dispatch` releases it) and
    `TillSuggestion` (rows of `pos/suggestions`, the cart's id key, and the
    query as indexed keys `product_ids[0]=…`, since the client's query is a
    dictionary; checked on the scratch Hub that Laravel reads them as the
    same array).
  - Order page: "Awaiting dispatch authorization" banner with "Confirm
    dispatch" for holders of the key, the console's notice for everyone
    else, and "Dispatched … on <time>" afterwards.
  - Till: "Usually bought together" chips under the cart, after a half
    second's pause like the console; a chip finds the product through the
    normal search (outlet stock, sale-currency prices) and adds it by the
    same path as a tap on its tile (variant picker when needed).
  - Live on the scratch Hub: five Cope+Stole baskets made Stole a
    suggestion for Cope at 100 %; dispatch released order 17
    (`confirmed` → `completed`, `dispatched_at` set) and a second call was
    refused ("already dispatched").
  - `TillHandoverTests` (6) pass; 29 kit tests pass on Linux in total.
  - `pos/register/history` is a console helper no page calls; not a gap.
- Matrix regenerated: 67 console endpoints with no Apple caller.
- **Slice 4 — payroll People & pay and Commissions** (same branch, PR #555):
  the console's payroll has six tabs; the apps had four.
  - `BethanyKit/PayrollPeople.swift`: `PayPerson` (salary, the deductions
    switch and a scheduled change, advances and commission owed, last pay),
    `CommissionRow` / `CommissionsPage` (status labels and details as the
    console prints them, the server's totals and `can_*` flags), and
    calendar-date text with no time-zone shift.
  - Payroll screen: "People & pay" (search; ⋯ menu: add/stop government
    deductions → `POST payroll/people/{user}/deductions` from a date with a
    reason, step-up on the server; record commission; salary and file go to
    the employee file) and "Commissions" (filter, the three totals, PAYE on
    commission switch for approvers, record, approve, void with a reason).
  - Live on the scratch Hub: a super admin's commission is recorded and
    approved at once (KES 1,500 owed); deductions for Evans from 1 Dec 2026
    show as "Start 1 Dec 2026" beside "No deductions · contract".
  - `PayrollPeopleTests` (8) pass on Linux.
- Parity tool fix (interpolations with quotes inside) — see the matrix
  notes. Console endpoints with no Apple caller: 45. (Correction: break
  start/end was first reported as a gap; it is native in
  `MeFeatureClock.swift:339`. A truncated grep hid it.)
- **PR #555 CI (a9a31fa): green.** 58 BethanyKit tests on macOS; HubMac and
  HubMobile `** BUILD SUCCEEDED **`. Awaiting the owner's merge approval.

### Next checkpoint — slice 5 (planned, not started in code)

Profile page parity. The console's ProfilePage has 7 tabs; the apps' ProfileView
(152 lines) has sessions, devices and password only. Verified by reading
`react-admin/src/pages/profile/ProfilePage.tsx` and `api/profile.ts`:

| Console tab | Endpoints | Apple today |
|---|---|---|
| Profile | `GET/PUT profile`, `POST profile/avatar` | check |
| My employment (`MyFileTab`) | `GET/PUT me/file`, `GET me/file/sensitive`, `me/file/documents/{doc}` (signed) | missing |
| Password | `POST profile/password` | present |
| 2FA Security | `auth/2fa/enable|confirm|disable|recovery-codes`, `profile/passkeys*`, `profile/terminal-pin` | missing — **authentication: separate slice 6, owner review** |
| Sessions | `profile/sessions*`, `profile/devices` + `PUT/DELETE profile/devices/{id}` | rename/remove missing |
| Preferences | check | check |
| My Activity | `GET profile/activity` | missing |

Break start/end is already native (`MeFeatureClock.swift:339`). Not gaps (declared, no page calls them):
`time-clock/my-entries`, `me/timesheet/fixes/{id}` DELETE. Real but small:
`people/requests/count` (the sidebar's requests badge).

### Slice 5 — profile page and My employment (branch `apple/profile-parity`, stacked on #555)

- `BethanyKit/ProfileModels.swift`: `ProfileDevice` (an admin's name is
  locked; the device in hand cannot be removed; names 2–60 characters or
  cleared), `ProfileActivity`, `MyEmployment` (the file keeper's fields,
  mine to keep, masked identifiers, documents).
- Profile screen gains tabs: "Profile & security" (now with Edit details &
  preferences → `PUT profile`, and device rename/remove), "My employment"
  (`GET/PUT me/file`, identifiers in full after step-up via
  `GET me/file/sensitive`, documents through signed links) and "My
  activity" (`GET profile/activity`).
- Live on the scratch Hub: `PUT profile` saved; `PUT me/file` refused a
  placeholder emergency phone with a field error, then saved
  "+254 722 555 931" normalised; `me/file/sensitive` answered
  `step_up_required`.
- `ProfileModelsTests` (5) pass on Linux.
- Left for slice 6 (authentication, owner review): 2FA enable/confirm/
  disable/recovery codes, passkeys, terminal PIN. Also still open:
  `profile/avatar` upload, `people/requests/count` badge.
- Noticed, not changed (Laravel): `GET profile` returns the user model
  with `two_factor_secret_temp` (null here). A pending 2FA secret should
  not travel on a profile read; raised for the owner.
### Slice 7 — Messages: attention, notices, Handled, hide/restore, Assist (branch `apple/messages-parity`)

- `BethanyKit/ChannelAttention.swift`: `ChannelAttention` (GET
  `channels/attention`: what waits on me, floating notices, levels) and
  `ChannelAssist` (the four actions' request bodies and answers).
- Messages list: "Waiting on you" notices at the top (open, or dismiss the
  notice — `notice-dismiss`, which leaves the message unread and waiting),
  and "Recently hidden" with Restore (`undismiss`) for order/production
  threads hidden this session (`dismiss` now goes through the store).
- Thread: "Handled" (`acknowledge`) when the thread waits on my reply;
  "Assist" sheet (summarise, translate my draft, draft to the team or —
  on an order thread — the customer, suggest replies; English/Kiswahili);
  the answer is put into the message box, never sent by itself.
- Live on the scratch Hub with two users: a DM to the admin showed
  pending 1 with a notice; `notice-dismiss` removed the notice but left
  unread 1 / pending 1; `acknowledge` cleared both; `assist` answered 503
  "Assist is not set up on this server yet…" (no model key), which the
  sheet shows as the server words it.
- `ChannelAttentionTests` (5) pass on Linux.
- Owner approved the authentication slice (6) on 2026-11-10: next.

### Slice 6 — sign-in safety (owner approved 2026-11-10; branch `apple/auth-parity`, stacked on #556)

- `BethanyKit/SecurityModels.swift` (builds everywhere): `TwoFactor`
  (setup answer, the 6-digit check, recovery codes), `TerminalPinRule`
  (word for word `TerminalPin::problem`, including the 9→0 wrap),
  `PasskeyCreationOptions` (the server's options and the credential JSON the
  console sends), `PasskeyRow`; `Data(base64URL:)` moved here from
  `PasskeyStepUp.swift` so it can be tested.
- `BethanyKit/PasskeyRegistration.swift` (Apple only): the platform
  passkey prompt, then `POST profile/passkeys {name, credential}`.
- Profile › Profile & security: Two-step sign-in (turn on with a QR code
  drawn on the device and the key; confirm a code; recovery codes shown
  once; new recovery codes; turn off with the password), Passkeys (list,
  add, remove) and Terminal PIN (set/change with the password). The note
  that sent people to the console is gone.
- Live on the scratch Hub: `2fa/enable` then `2fa/confirm` with a TOTP
  code computed from the issued key → enabled, 8 recovery codes;
  `2fa/recovery-codes` after step-up → 8 new codes; `2fa/disable` refused
  a wrong password (field error) then disabled; `terminal-pin` refused
  "3456" ("a run of consecutive digits") and saved "2580"; passkey options
  read (rp, challenge, user handle). A passkey cannot be created from this
  environment — registration on a real device is unverified.
- `SecurityModelsTests` (4) pass on Linux.
- **Finding for the owner (Laravel, not changed):** `User::$hidden` lists
  `two_factor_secret` and `two_factor_recovery_codes` but not
  `two_factor_secret_temp`. During a 2FA setup, `GET profile` (and any
  response serialising the user) carries the pending secret, encrypted
  with the app key (seen: 228 characters, `eyJpdiI6…`). Proposed fix: add
  it to `$hidden`. Needs authorization.

### Slice 8 — sidebar counts and profile photo (branch `apple/counts-avatar`, stacked on #558)

- `AppEnvironment.refreshCounts()`: `GET notifications/unread-count` and,
  for `attendance.view_team`, `GET people/requests/count`, as background
  requests (no idle-lock activity), every minute while the Mac or iPad
  sidebar is up — the console's Sidebar/Topbar cadence. Badges on
  Notifications and People › Requests (Mac sidebar, iPad sidebar, phone
  "More" list). Before this the notification badge started at 0 and only
  counted realtime pushes.
- Profile: "Change photo" → `POST profile/avatar` (multipart `avatar`;
  jpeg/png/webp; 2 MB checked before sending, the server's limit).
- Live on the scratch Hub: both counts answered `{"count":1}`; a PNG
  upload answered "Avatar uploaded." (stored as webp).
- Console endpoints with no Apple caller after this branch: 27.

### Checkpoint 2026-11-10 11:15 UTC — #556–#560 merged; remaining gaps classified

All of #556 (profile), #557 (messages), #558 (sign-in safety), #559 (badges,
photo) and #560 (Laravel: `two_factor_secret_temp` hidden, owner approved)
are on `main`, each green on the combined code before merging.

23 literal gaps remain (`tools/parity`), classified by whether a console
*page* calls the endpoint (not just an `api/*.ts` helper):

| Kind | Endpoints |
|---|---|
| Not applicable to native | `push/subscribe`, `push/subscribe-expo`, `push/unsubscribe`, `push/unsubscribe-expo`, `push/vapid-public-key` (browser/Expo; the apps use `push/subscribe-device`) |
| Console helper no page calls | `orders/{id}/invoice`, `pos/register/history`, `time-clock/my-entries`, `me/timesheet/fixes/{id}`, `inventory/stock-levels/by-product/{id}`, `updates/{id}/readers` |
| Blocked on the owner | `database/wipe` (destructive), `currencies-management/{id}/rates` (FX, CLAUDE.md §6) |
| **Slice 9** — catalogue & purchasing | `grn/{id}/pdf`, `grn/{id}/print` (GoodsReceiptDetailPage), `products/{id}/images/{imageId}/primary` and `proposals/preview` (ProductFormPage), `suppliers/{id}/purchase-orders` (ProcurementReportPage) |
| **Slice 10** — dashboards & lookups | `reports/dashboard/kpis` (DashboardPage), `reports/customers/analytics` (CustomersReportPage), `activity-logs/record/{type}/{id}` (AuditParts), `intelligence/entity-previews` (EntityChipWithPreview), `users/role/{role}` (ClerkPinsPanel) |

### Slices 9–10 — the last page-level gaps (branch `apple/stock-purchasing-parity`)

Checked one by one against the console's *pages*; six of the eleven
candidates turned out not to be gaps:

- `grn/{id}/pdf` and `grn/{id}/print`: `PurchaseOrderController::printGRN`
  and `grnPDF` both return `DocumentPdfController::grn`, the same PDF the
  apps already open at `pdf/grn/{id}`; and GoodsReceiptDetailPage calls
  neither (only the audit log).
- `products/{id}/images/{imageId}/primary`: native already
  (`ProductFormTabs.swift` builds the path relatively, `images/\(iid)/primary`).
- `proposals/preview`: `previewChange` is declared; no page calls it.
- `suppliers/{id}/purchase-orders`: `suppliersApi.purchaseOrders` is never
  called; ProcurementReportPage uses `reportsApi.purchaseOrders`.
- `reports/dashboard/kpis`: DashboardPage fetches it but renders nothing
  from it (the revenue row is `salesSummary`).
- `intelligence/entity-previews`: the console fetches on hover; the server
  already sends `entity_previews` with every message (list and realtime),
  which the apps show inline. Same outcome.

Built:
- **Customers report › Overview**: "Who they are" (Active and VIP customers,
  segments) and spend brackets from `reports/customers/analytics?period=<days>`,
  the period counted as the console counts it.
  `BethanyKit/CustomerAnalytics.swift`, `CustomerAnalyticsTests` (3).
- **History** (super admin; order, customer, product): every change to the
  record, twenty at a time, with the field-level old → new values parsed from
  `properties` (a JSON string). `BethanyKit/RecordHistory.swift`,
  `RecordHistoryTests` (2).
- **Clerk PINs** tab on Attendance for the super admin and outlet managers:
  the manager's outlet's clerks (`users/role/pos_clerk`), "Clear PIN"
  (`users/{id}/terminal-pin/reset`). `BethanyKit/ClerkPins.swift`,
  `ClerkPinsTests` (2).
- Live on the scratch Hub: analytics answered
  `{"period_days":30,"stats":{…},"segments":{"Repeat":1},"spend_brackets":{"10k-50k":1}}`;
  order 5's history showed "payment_status partial → pending"; clearing
  Evans's PIN answered "PIN cleared. The clerk sets a new one from their
  profile."

Literal gaps left: 20 — every one now classified as not applicable to native,
a console helper no page calls, a gap closed by another route (above), or
blocked on the owner (`database/wipe`, `currencies-management/{id}/rates`).
**No page-level console feature remains without an Apple equivalent**, by
this cross-reference and the page checks above. Verification on a Mac/iPad
remains to be done.
