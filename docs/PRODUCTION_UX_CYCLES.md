# Production UI/UX — cycle log

The Production module (My Tasks, Production Orders, Order Detail, WIP, Calendar, QC) is improved in ten cycles, as set by the owner's "Bethany Hub Production — UI/UX Master Improvement Prompt" (5 Oct 2026).

**How each cycle runs:**
1. One full pass through the whole route.
2. Build the highest-value improvements for that cycle's theme.
3. Test them.
4. Record the non-blocking findings here and close the cycle.

A cycle is never re-run. A later cycle revisits an area only for a genuine higher-order improvement or a regression.

| # | Theme | Status |
|---|---|---|
| 1 | UI/UX coherence | Closed |
| 2 | Information hierarchy | Closed |
| 3 | Shop-floor workflow | Next |
| 4 | Interaction quality | |
| 5 | Apparel-production intelligence | |
| 6 | Mobile / tablet excellence | |
| 7 | Offline and recovery | |
| 8 | Accessibility / privacy / trust | |
| 9 | Visual polish and performance | |
| 10 | A full working day (tailor + production manager) | |

---

## Cycle 2 — Information hierarchy (closed)

**The question for every screen:** is the most important thing for this person the first thing they see?

**Inspection.** The whole route was captured again for a tailor (Mary) and the admin (Grace), at 390px, 1024px and 1366px:
- Tailor Home, My Tasks, Production Orders, Order Detail (live and cancelled), WIP, Calendar.

What the pass found, beyond the four items Cycle 1 recorded:
- **Tailor Home:** one overdue order was announced three times (red banner, Overdue tile, the task's chip). The banner and four tiles pushed her task list below the fold.
- **Closed orders still counted down.** A cancelled order read "Due in 3d"; a completed one could read "Overdue".
- **Where an order is:** an order with Cutting done and Stitching *paused* read "Not started" on the board and the order page. The server looked only for an in-progress or pending stage.
- **Calendar:**
  - day cells showed "20…", the clipped order number, which names nothing;
  - the stage panel read "Stage 1 / 2 / 3";
  - the panel left paused work out;
  - like WIP, it opened a manager on their own (empty) diary.
- **Stock jobs** led every card with "For stock", so a run of stock work read the same line six times.
- **Orders stat tiles:** six across on a phone ran the labels together ("PendingIn Progress").

### What changed

- **Managers open on the whole floor.** WIP and the Calendar default to everyone for anyone who runs the floor; a tailor still opens on her own work. The rule is the existing `isFloorWorker` helper, so there is no new role test. Where a manager can't browse staff, the static pill reads "All orders", not their name.
- **Tailor Home puts the work first:**
  - My Tasks comes before the stat strip;
  - the duplicate overdue banner is gone for floor workers (it was their only alert);
  - the generic subtitle is hidden on phones;
  - a task's due and in-progress chips stack, so the garment name keeps its width.
- **Closed orders don't count down.** `dueInfo(date, status)` gives a completed or cancelled order its plain date ("8 Oct 2026") with no urgency. Every surface passes the status: list cards and table, WIP, the order drawer, Order Detail, Calendar and Tailor Home. Order Detail's due tile then reads "8 Oct 2026 · DUE" instead of the date twice.
- **Where an order is, truthfully.** `ProductionController::getCurrentStage`: the stage being worked, else the earliest stage by sequence that isn't satisfied (pending, paused or sent back). It was "the first pending task in load order".
  - `stageLabel()` in `productionUi` shows "All stages done" when nothing is open and "Cancelled" on a cancelled order. It is used on the WIP card, the drawer and Order Detail.
  - A paused stage's pip on the WIP card is amber.
- **Calendar:**
  - the schedule feed adds `stage_names` beside `by_stage` (same id → count shape) and counts paused stages as open work;
  - day cells show the garment, with the order number on hover;
  - the title uses the compact heading.
- **Production pages** use the compact `.page-title-sm` heading. The descriptive subtitle is hidden on phones.
- **Job identity:** a customer job leads with the customer and a stock job with the garment ("Clergy Cassock / For stock"), on the order cards and WIP.
- **Orders stat tiles:** two rows of three on a phone, one row from `sm`.
- **Tablet orders:** cards below `xl` (1280px). Beside the sidebar a 1024px tablet left about 730px, and the table wrapped order numbers onto three lines and clipped Status. The table's order number no longer wraps.
- **Title bar:** an order page reads "Production Order", not "#6". This is scoped to `/production/orders/{id}`, so other modules' crumbs are unchanged.

### Tests

- `ProductionStageLineTest` (new), with its fixture written out:
  - paused-stage order → "Stitching";
  - stages created out of sequence → the earliest by sequence;
  - all done → null, with the list and the order page agreeing;
  - the calendar panel counts Cutting 1 · Stitching 2 (paused included) · Finishing 1, by name.
  Both tests fail on the previous controller.
- `OrderProgressEverywhereTest`, `TailorFloorSafetyTest` and `ProductionCountsFollowVisibilityTest` pass unchanged.
- Frontend: `tsc` is clean and `vite build` passes. Before/after captures were taken at 390, 1024 and 1366px.

### Recorded for later cycles (non-blocking)

| Cycle | Finding |
|---|---|
| 9 | A stock job's second line reads "For stock · SKU-…" on WIP; the SKU belongs to the garment line above. |
| 4 | A cancelled order's stages still offer "Ready", "3 waiting" and "Allow parallel". |
| 4 | Order Detail's six tabs still crowd a phone (left from Cycle 1's list; hierarchy work did not need them). |
| 9 | `ProgressBar` at 0% draws a red dot, its minimum width, on a cancelled order. |

## Cycle 1 — UI/UX coherence (closed)

**Inspection.** Every Production screen was captured for a tailor and a manager, at 390px and 1024px, on Nairobi time. A code inventory was taken of how each surface names, colours, dates and measures an order. The main finding: the surfaces disagreed about the same order.

**Status:**
- 7 separate status maps.
- "in progress" appeared in four colours.
- "qc_failed" read both "QC Failed" and "Rework Needed" on the same card.

**Progress — three meanings for one 10-piece order:**
- My Tasks: pieces (53%).
- Order header: finished stages ÷ stages (33%), beside its own pieces-based "finished" tile.
- A tailor's order page: 0%, because her payload holds only her own stage.

**Due dates:**
- 8 renderers: "3d late", "3d ago", "3d overdue", "Overdue by 3d", "Overdue 3d"…
- Each did its own 24-hour arithmetic in the device's time zone.
- On the Calendar, today's ring sat on tomorrow's cell.

**Identity:** a job with no customer read "Name missing", "For stock", "Stock" or "Customer order", under two different rules for what counts as a customer job.

**Copy:** "Task started!" and "Stage started!" for the same action, and "QC Failed – order on hold" when the order is not put on hold.

### What changed

- **One progress figure.** `App\Support\OrderProgress` computes it: pieces passed across the whole pipeline ÷ (quantity × stages), using the canonical `ProductionTask::effectivePassed`, read past the viewer's own-task scope.
  - The order page, orders list, calendar schedule feed and My Tasks all send it, as `progress {percent, finished, stages}` and `completion_percentage`.
  - `ProductionOrder::getCompletionPercentage()` delegates to it. There is one definition, and the stage-count `calcCompletion` was removed.
- **One design language.** `react-admin/src/components/production/productionUi.tsx` provides:
  - order and task status labels and colours;
  - priority;
  - `DueBadge` / `dueInfo` ("Overdue 3d" · "Due today" · "Due in 2d", 2-day warning);
  - `ProgressBar`;
  - the job identity (`isCustomerJob`, `jobFor`).

  The Production Orders list, WIP, QC, Order Detail, My Tasks, Calendar and Tailor Home use it instead of their own copies.
- **Colour rule.** Neutral = not started or closed; brand = being worked; amber = paused or on hold; purple = with QC; green = passed or done; red = failed. Cancelled is struck through.
- **Business calendar.** `lib/businessDate.ts` gains `businessToday` and `businessDaysUntil`. Due dates count calendar days in Africa/Nairobi, whatever the device's zone.
  - The Calendar keys cells by their own day and compares due dates on the business calendar, so "today" is right.
  - The My Tasks delivery week does the same.
- **QC tiles** read whole-floor counts from the server (`stats.qc_passed`, `stats.qc_failed`), not the open tab's rows.
- **Order Detail:**
  - finished pieces come from the server figure;
  - the stage distribution chips show only when every stage is on the page (a tailor's payload holds just hers).
- **Copy:**
  - "Stage" is the noun on every screen.
  - The verbs are Start · Pause · Mark done.
  - Toasts read "Stage started / paused / done".
  - A failed QC says "QC failed — recorded on the order".

### Tests

- `OrderProgressEverywhereTest`: one 10-piece, three-stage fixture, with the expected figures written out (53%, 0 finished, 3 stages). They must match on the order page, the list, the schedule feed, My Tasks and the model helper, for a manager and for a tailor who holds one stage. It also covers the no-stages case and the QC tiles across tabs. All four tests fail on the previous code.
- The existing `ProductionPieceProgressTest` and `TailorMyTasksPayloadTest` pass unchanged.

### Recorded for later cycles (non-blocking)

| Cycle | Finding |
|---|---|
| 2 | For a manager, WIP opens filtered to "Me", so the board is empty until the filter is cleared. |
| 2 | On a 1024px tablet the Production Orders table is too wide: order numbers wrap to three lines and Status scrolls off. The phone layout of cards is better than the tablet table. |
| 2 | Order Detail's title bar reads "#1" instead of the order number. Its six tabs crowd a phone. |
| 2 | Very large page headings ("Production Orders", "Work in Progress") push the work below the fold on phones. |
| 3 | The QC result never reaches the tailor; a failed order sits in her "Completed" lane. There is no rework path (B21). |
| 3 | WIP never shows newly assigned (pending) work. |
| 4 | My Tasks "Your stages n/m done" reads 0/N, because finished tasks are filtered out before counting. |
| 4 | The Start, Pause and Mark done buttons are styled three different ways across My Tasks, the order drawer and Order Detail. |
| 5 | Measurements are not in Clergy-sheet order (F14), and View specs doesn't sort them at all. |
| 6 | Floor buttons are about 22–28px tall; the target is 48px. |
| 7 | The offline queue breaks on a fresh device; piece taps are not queued; My Tasks is not cached offline. |
| 8 | Colour contrast fails on every screen (axe). There are two QC loopholes. The calendar shows the whole floor to tailors. Signing out doesn't clear data on shared tablets. |
| 9 | One full page load fires enough requests that six reloads in a minute hit an admin's rate limit, and the start-up screen then reads "Can't reach". The duplicated modals in the Production Orders page and Order Detail (Assign, Issue Materials, QC, Complete) still exist. |
