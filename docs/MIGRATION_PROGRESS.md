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
