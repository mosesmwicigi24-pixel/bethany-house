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
  first thing ever to compile the SwiftUI code; its result on this PR is
  recorded below once it has run. A green build proves compilation only.
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
