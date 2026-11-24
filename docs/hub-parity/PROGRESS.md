# Tailors Hub audit progress

### My Tasks workspace — 24 November 2026, Codex

- Removed Calendar's open-work-by-stage section. My Tasks now opens specifications and measurements by default, groups the bench note in a themed card, and previews up to three recent messages from the existing membership-scoped production channel. Opening the preview uses the existing full Messages conversation; previews do not mark messages read. Missing or dismissed channels are not created or rejoined automatically.
- TailorsMobile simulator build succeeded. With a fictional local Hub on iPhone 17 Pro, verified the expanded measurement sheet, bench note, recent message, and opening the same conversation. No live Hub data was changed. The screenshot inspection showed the top of the workspace; the conversation was also checked via accessibility navigation.
- Calendar removal was checked in source but not visually rechecked after the simulator control disconnected. Empty/dismissed channel, error/retry, dark mode, accessibility sizes, iPad and live Hub behavior remain unverified. No Android offline files or Hub permission logic changed.

### Calendar and My Tasks navigation — 24 November 2026, Codex

- Calendar now opens the same My Tasks job view when an order is in the signed-in worker's task list. A Calendar back control returns to the calendar. Other orders retain the existing production-order route; no Hub endpoints or permission gates changed.
- The TailorsMobile simulator build succeeded. On iPhone 17 Pro (iOS 27.2), using a fictional local Hub, the assigned shirt opened from Calendar with its stitching progress, measurements and stages, and returned to Calendar. No live Hub data was changed.
- Unassigned-order fallback, assignment changes between requests, network failures, iPad layout, accessibility sizes and live Hub behavior remain unverified in the simulator.

### Production-order layout — 24 November 2026

- Reordered the order page to show its summary, work instructions and measurements, then compact stages; removed its PDF action and Details section at the owner's request. The removed Details section previously displayed fitting/collection dates and a sales-order link; those are no longer shown on this page.
- Added restrained color to the shared measurement icons. The iPhone simulator build succeeded and a fictional order's summary, measurement grid and section sequence were checked. Dark appearance and large text remain unverified.

### Production calendar — 24 November 2026, Codex

- Restyled open-stage workload rows with relevant existing production icons and task-count badges. Checked four fictional stage types on iPhone 17 Pro; an empty `by_stage` response still hides the section.
- Reordered the production-order page so its status and actions come first, followed by stages, work instructions (notes, measurements, specifications and preferences), batches/materials, administrative details and thread. Verified the section sequence with a fictional order in the simulator. No Hub data or permission logic changed.
- Refined calendar styling with quieter summary tiles, a today outline, selected-day contrast for light and dark appearance, a deadline legend and piece counts where provided. iPhone simulator build and sample date selection checked; dark appearance and larger text remain unverified.
- Redesigned `ProductionBoards.swift` with a selectable month grid, due-order agenda, past-due summary and open-stage workload. Existing schedule data is scoped by the server. Tapping a card uses the permission-scoped production-order search and exact order number to resolve its ID; if lookup fails the app reports it.
- On an iPhone 17 Pro simulator with a fictional Hub, selecting 24 November filtered the agenda to one order; tapping the sample card opened the matching order and Back returned to the calendar. No live data was changed.
- Open: the schedule response does not include fitting or collection dates. Those dates appear in order details when available, but are not shown as calendar events. Simulator coverage does not establish live Hub parity or all permissions, failure and accessibility states.

### Tailors Hub app icon — 23 November 2026

- Added a terracotta scissors-and-thread icon to the Apple TailorsMobile app icon catalog. The iPhone simulator build succeeded and the installed icon was inspected on the iPhone 17 Pro home screen. App Store distribution remains separate and unverified.

### In progress — 23 November 2026, Codex

- Refining the Apple production-order Details and Thread UI in `ProductionOrderDetail.swift`, reviewing the time-clock UI in `MeFeatureClock.swift` against the Hub, and auditing the Apple More pages one by one; preserving existing Hub APIs and permissions. Claude has claimed Android offline files separately.
- Local slice: order header separates status badges from the order number; details use labelled rows; the thread composer uses a separate type selector and guards repeated sends. Clock shows an error and retry when status cannot load, and avoids presenting absent summary data as zero. iPhone simulator build succeeded and 121 Swift tests passed. The finished layouts, thread sending/failure and clock actions still need simulator verification. The full More-page audit remains in progress.

### iPhone simulator review — 23 November 2026

- Reviewed commit `571a6dc4` on iPhone 17 Pro simulator, iOS 27.2, against the live Hub. The review used read-only navigation and no task, attendance or employee data was submitted.
- Xcode `TailorsMobile` Debug simulator build succeeded. Its sole warning was: `warning: Metadata extraction skipped, no AppIntents.framework dependency found`.
- The signed-in account showed My Tasks, Calendar, Messages and Clock in, plus More. More showed workday links and no People section. The account's assigned role was not independently verified; the menu and access test reflect its observed permissions.
- A task showed a Men badge before nine measurements, in the same order as the previously supplied screenshot. Independent comparison against the Hub's original measurement sheet remains unverified.
- The custom-scheme link `tailorshub://hub/admin/people/5` displayed `You don't have access to this page`. An actual HTTPS universal link was not tested.
- HR/payroll account navigation and employee-file access remain unverified pending an appropriate sign-in. Screenshots of the task, measurements, More and access-denied screens were saved locally under `/private/tmp/tailors-review-571a6dc4-screens/`; they contain live data and are not committed.

## 23 November 2026

- Inspected the user-provided brief, repository governance, existing working tree, prior native checklist and audit backlog, route allowlist and registrations.
- The native checklist is dated 29 October and predates later simulator work. Its device verification status requires reconciliation with current evidence.
- Preserved the pre-existing dirty working tree, including entitlement and generated plist changes.
- Created an initial workflow inventory. All rows remain partial or divergent until the requested end-to-end checks are complete.
- Traced the offline queue and clock resend paths. An unreadable queue is silently represented as empty. Sign-out retains the queue, but its guard can incorrectly report that no changes are pending. Task and clock paths can also fail to recognize pending work. This requires a full design and regression checks.
- Corrected manual clock resend so temporary HTTP errors and authentication expiry retain a queued punch. Existing queue refusal classification is reused. An end-to-end resend test remains outstanding.
- Previous conversation evidence includes selected fictional iPhone/iPad flows and 96 Swift tests; those observations do not establish whole-app parity.

### Offline retry verification

- iOS simulator target built successfully with the clock resend classification change.
- Swift suite passed 96 tests, including existing temporary-versus-final refusal classification tests.
- The simulator has not yet reproduced an offline punch followed by HTTP 500 or 401. This path remains unverified end to end.
- Added a throwing queue read for paths that must distinguish unreadable saved work from an empty queue. Task changes and new clock punches now stop with a visible message when that check fails. The sign-out guard, pending badge and general replay still use the older non-throwing read and remain open.
- The simulator initially exposed a missing offline store immediately after sign-in. Task and clock actions now resolve the signed-in user's store on demand; the underlying session-change observation and its effects on realtime and other offline features still require investigation.
- On an iPhone 18 Pro Max simulator, a fictional damaged queue blocked a task count update, displayed an actionable error and retained the count draft. After restoring the local test fixture, a separate fictional Hub accepted one count update from 4 to 5 and the app showed “Count saved.” This does not verify the live Hub.
- Manual punch resend and automatic queue replay each send independently. Concurrent execution can therefore issue the same pending request twice. The server checks whether a user is already clocked in, but the inspected controller does not establish request idempotency or prove concurrent safety. Investigate and test before relying on this path.
- Queue deletion errors are currently suppressed, so a reported successful send may remain queued and be retried. This remains open.
- After the latest changes, the shared Mac app target built successfully with code signing disabled, and the Swift suite passed 96 tests. A Mac runtime walkthrough and the remaining iPad and iPhone scenarios have not been completed.

### Offline queue P0 fixes (23 November 2026, later)

Branch brought up to date with `main` (#578–#582, #553 merged today). Conflicts
were the earlier Tailors Hub code arriving twice (once squashed); the newer
branch side was kept and the menu test follows `main`'s "Me" → "My Workday"
rename.

- **One sending path.** `OfflineStore.send(_:using:)` is now the only way a
  queued operation is sent. Automatic replay and the clock screen's resend
  both use it, and an operation already in flight is never sent a second time
  (`.alreadySending`). A replay that meets an operation in flight stops there,
  so nothing queued after it reaches the server first.
- **Accepted but not cleared.** `dequeue` reports failure instead of
  swallowing it. An operation the server accepted whose removal could not be
  written is held in memory as delivered: hidden from the queue, never resent
  while the app runs, and dropped from disk at the next successful write.
  Replay reports these (`notCleared`) and the app says they will not be sent
  again.
- **Unreadable is never empty.** Replay reports an unreadable queue
  (`unreadable`) and sends nothing. The sign-out guard uses the checked read
  and warns "Saved changes can't be read" instead of signing out silently. The
  pending badge is set only from the checked read (`refreshPendingCount`), and
  `ConnectionMonitor.pendingUnreadable` flags an unreadable queue (shown in the
  Mac sidebar).
- **Tests.** Five new `OfflineStoreTests`: two senders at once send a punch
  once; replay keeps order behind an operation in flight; temporary failure
  keeps and final refusal removes; accepted-but-uncleared is not resent and
  leaves disk at the next write; an unreadable queue replays nothing and is
  reported. All 8 offline-store tests pass on Linux (Swift 6.0.3, APIClient
  stubbed). Mutation check: removing the in-flight guard fails the two
  concurrency tests (the punch is sent twice); removing the delivered filter
  fails the uncleared test.
- **Not verified here:** the SwiftUI changes (clock screen, sign-out alert,
  Mac badge) compile only in Apple CI; no simulator or device run.

Still open:
- Restart durability: an accepted-but-uncleared operation survives on disk
  only until the next successful write; if the app is killed first, it can be
  resent after restart. The server side (punch state check, absolute progress
  counts) limits the effect, but request idempotency is not established
  server-side — UNKNOWN — NEEDS VERIFICATION.
- Android has its own offline store and sign-out guard; none of these fixes is
  ported there yet.
- ~~Tailors Hub scope~~ decided by the owner (2026-11-23): the workshop app
  for everyone; the own-workday screens stay (advances, checklists, handbook,
  to sign, to-dos, colleagues added); People administration, employee files
  and team payroll removed from Tailors Hub on both platforms, whatever the
  person's permissions. `AppFlavorTests` / `AppFlavorTest` pin a manager with
  HR and payroll rights. Today (`.me`) is left out: not in the owner's list.
  Tailor and manager experiences still need a run on a device.

### People administration restored (owner, 2026-11-23, later)

The owner approved restoring People administration, employee files and team
payroll to Tailors Hub for those whose existing Hub permissions open them.

- `TailorsHub.allows` (Swift and Kotlin) includes People overview, requests,
  attendance, checklist management, roster, timesheets, leave, employee files
  (list and detail) and payroll again. Each keeps its catalogue gate
  (`attendance.view_team`, `forms.manage`, `hr.view`, `payroll.view`). No
  server permission, outlet scope, step-up or audit code changed.
- **Detail gate (found by Codex):** Android's route guard looked up the exact
  route, so one employee's file (`People(5)`, not a menu item) had no gate and
  only the server stood between a direct link and the page. Both platforms now
  resolve a screen's gate with `NavCatalogue.gate`: the item's own gate, else
  its list's (`Route.parent`). Apple already inherited the parent gate in
  `RouteView`; the rule moved into BethanyKit (`Route.parent`) so it is shared
  and tested, and `Navigator.parent` forwards to it.
- **Tests (hand-written expectations):** an HR manager sees all nine People
  pages; a team lead without `forms.manage`/`hr.view`/`payroll.view` sees six;
  a tailor without management rights sees none; `/people/5` parses to an
  employee file whose gate is `hr.view` and refuses a tailor and a team lead.
  Swift `AppFlavorTests` 7/7 (Linux, Swift 6.0.3); Kotlin `AppFlavorTest` 8/8,
  core suite 37/37; both Android APKs build. Mutation check on both
  platforms: reverting to the exact-route lookup fails the direct-link test.
- Offline reliability changes (`OfflineStore.send`, checked reads) untouched.
- **Not verified:** the Apple screen layer compiles only in Apple CI; no
  simulator or device run from the cloud. A run on the owner's Mac (TailorsMobile
  in the iPhone simulator) is still to do: the Mac is not connected to this
  session.
