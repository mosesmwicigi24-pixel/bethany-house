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
| 3 | Shop-floor workflow | Closed (rework path proposed, awaiting approval) |
| 4 | Interaction quality | Closed |
| 5 | Apparel-production intelligence | Closed |
| 6 | Mobile / tablet excellence | Closed |
| 7 | Offline and recovery | Next |
| 8 | Accessibility / privacy / trust | |
| 9 | Visual polish and performance | |
| 10 | A full working day (tailor + production manager) | |

---

## Cycle 6 — Mobile / tablet excellence (closed)

**The question:** can a tailor work one-handed on a phone, and read the job at arm's length on the shop tablet?

**Inspection.** I measured every visible control on My Tasks, Order Detail and Production Orders at 390px with Playwright, against a 48px floor target (44px minimum). I also captured the screens at the Tab S9 Ultra's landscape width (≈1480px).

**What the pass found (before):**
- **My Tasks**, the floor's busiest screen:
  - Start/Resume, Pause and Mark done were 32px tall, in 11px type;
  - the piece counters (+1 / +5 / +10 / −1) were about 26px;
  - Add note and View specs were 40px;
  - the order pager dots were 16px.
- **Order Detail on a phone:**
  - the action bar (Assign, Materials, PDF, ⋮) was 30px;
  - the tabs were 42px;
  - the back link was 16px tall;
  - "Allow parallel" was a 16px text link;
  - the stage buttons (`StageActions`) were 32px.
- **Production Orders:** the card's call button was 36px.
- **Tablet landscape:** the Focus card stretched about 1,200px wide, so each measurement's value sat a hand-span from its name, and Start drifted to the far right.
- **Order Detail tabs** scrolled sideways with no hint (recorded in Cycle 4).

### What changed

- **48px floor controls on My Tasks.** Start/Resume, Pause, Mark done, the piece counters (48×48) and Add note / View specs are all at least 48px, with 14px type. The pager dots get a 44px hit area around the same dot.
- **`StageActions`** (Order Detail and the order drawer) is 48px.
- **Order Detail on a phone:**
  - the action bar is 44px (still 36px from `sm`);
  - the tabs are 48px;
  - the back link and "Allow parallel" get 44px hit areas without moving the layout.
- **The tab bar shows a scroll hint.** A sticky right-edge fade on phones signals that more tabs sit off-screen. As the row's last item, it rests beyond the last tab once scrolled to the end.
- **The call button** on order cards is 44px.
- **The Focus column is capped at a readable width** (`max-w-3xl`, centred) on large screens; phones are unchanged.

### Tests

Re-measured at 390px. The only controls still under 44px are:
- the sales-order number, an inline text link;
- Clear and New Order on the Production Orders filter row, at 40px;
- "Allow parallel" at 40px.

`tsc --noEmit` is clean. Captures at 390px and 1480px confirm:
- the counters read at arm's length;
- the Focus card sits as a centred column on the tablet;
- the Order Detail tab bar fades at the right edge.

### Recorded for later cycles (non-blocking)

| Cycle | Finding |
|---|---|
| 9 | The Production Orders filter row (Clear 40px, New Order 40px) and the `btn` component sizes are shared app-wide, so they were left for a whole-app pass. |

## Cycle 5 — Apparel-production intelligence (closed)

**The question:** does each screen read a garment the way a tailor does? That means measurements in the shop's order, materials with what is actually there, and gender as part of who the garment is for.

**Inspection.** Measurements, specs and materials were traced through My Tasks (the Focus card and the View specs drawer), Order Detail and the new-order form, on a men's cassock order.

**What the pass found:**
- **Measurements came in whatever order they were typed.**
  - The Focus card read Neck, Chest, Sleeves, Shoulders.
  - View specs showed them unsorted, with Gender as a measurement tile.
  - Only Order Detail sorted them, and its list (F14) was missing Arm Hole, Upper Arm, Bodice and Blouse Length.
  - It also spelled "hip" where the ladies' sheet says "Hips".
- **Two copies of the clergy sheets.** The new-order form and the product form each held their own.
- **View specs hid shortfalls.** Its materials list showed only the *required* quantity, under the heading "Materials allocated", so a job short of cloth read as fully supplied. The Focus card already showed "allocated / required · short".

### What changed

- **One measurement order.** `CLERGY_SHEETS` (men and ladies) and `orderMeasurements()` live in `productionUi`.
  - The two sheets are merged top of the body down: Neck, Shoulders, Sleeves, Wrist, Arm Hole, Upper Arm, Chest/Bodice, Stomach, Waist, Hips, Shirt/Blouse Length, Full Length.
  - Custom fields follow, in the order they were typed.
  - Older spellings map onto the sheet: hip → Hips, sleeve length → Sleeves.
  - Gender is pulled out of the list.
  - The Focus card, View specs ("Measurements · Men") and Order Detail all use it, and the new-order form reads its sheets from it.
- **View specs shows what is there.** Each material reads `allocated/required unit`, amber with "· short" when short, matching the Focus card.

### Tests

These are screen-only changes; the console has no unit-test runner.
- `tsc --noEmit` and `vite build` are clean.
- Visual check at 390px, on the cassock (typed as Neck, Chest, Sleeves, Shoulders, Full Length):
  - the Focus card and View specs both read Neck, Shoulders, Sleeves, Chest, Full Length;
  - the gender sits in the heading.

### Recorded for later cycles (non-blocking)

| Cycle | Finding |
|---|---|
| — | The catalogue's product form still has its own copy of the clergy sheets (`ProductFormPage`). It is outside Production, so it was left alone. |
| Owner | QC can't pass part of an order (say 9 of 10). This depends on the rework decision proposed in Cycle 3. |

## Cycle 4 — Interaction quality (closed)

**The question:** does every control do what it says, look the same wherever it appears, and only appear when it can work?

**Inspection.** I walked My Tasks (Focus and Queue), the order drawer on Production Orders / WIP, and Order Detail, on a live order, a cancelled order and an order in QC. I compared what each screen offers with what the server will accept.

**What the pass found:**
- **"Your stages 0/1 done" after finishing a stage.** The Active list held only open tasks, so the checklist never counted her finished ones (recorded in Cycle 1).
- **A cancelled order still looked workable.** Its stages read "Ready" and "3 waiting", with the manager's "Allow parallel" link. The server refuses all of it (`ProductionOrder::FLOOR_WORK_STATUSES`).
- **Two copies of the stage buttons in two styles.** Order Detail and the order drawer each had their own Start / Mark done / Pause. The drawer's:
  - showed even on a closed order or a stage waiting on another bench;
  - printed the raw status ("in_progress") beside the stage name;
  - used a grey Pause where My Tasks uses amber.
- The Queue header counted orders that only wait on QC as work (recorded in Cycle 3).

### What changed

- **The checklist counts.** My Tasks asks for `include_order_context=true`: her open tasks plus her finished stages on every order still in motion (in work, in QC or back from it).
  - Focus and Queue build each order from all her stages, so the checklist reads "Your stages 1/2 done", with the finished stage ticked.
  - Only open stages are ranked by deadline risk; finished ones follow. Focus's order matches Home's.
  - The Cycle 3 name `include_awaiting_qc` is still accepted, from a cached console.
  - An order where she has nothing left to do leaves Active, so the header counts open work only.
- **Closed orders read as history.** `acceptsFloorWork()` in `productionUi` mirrors the server's floor-work statuses. On Order Detail, a closed order's stages show "Stopped" (cancelled) or "Not done", with:
  - no Ready;
  - no pile warning;
  - no "Allow parallel";
  - no distribution chips on a cancelled order;
  - no buttons.
- **One set of stage buttons.** `StageActions` in `productionUi`: Start/Resume (brand), Mark done (green), Pause (amber, as on My Tasks), at a 36px height.
  - Order Detail and the order drawer both use it. It renders nothing for someone who is not on the stage, on a closed order, or on a blocked stage.
  - The drawer shows the stage's `StatusBadge` instead of the raw status.

### Tests

- `MyTasksOrderContextTest` (new). The risk-score fixture is written out in the docblock:
  - her finished stage arrives with its order (A: completed + in progress), and a cancelled order's does not;
  - Home's list stays open work;
  - Focus ranks orders exactly as Home does, so a finished 20-hour stage cannot lift its order;
  - the Cycle 3 flag still works.
  The first and last tests fail on the previous code. The middle two are guards that pass on both.
- `QcResultReachesMakersTest`, `TailorMyTasksPayloadTest` and `TailorFloorSafetyTest` pass unchanged.
- Visual check at 390px:
  - Focus reads "Your stages 1/2 done", with Stitching ticked and Finishing ready to start;
  - the cancelled order's stages read "Stopped" with nothing to tap.

### Recorded for later cycles (non-blocking)

| Cycle | Finding |
|---|---|
| 6 | With six tabs (Stages, Batches, Materials, Specs, Notes, Audit), Order Detail's tab bar scrolls sideways on a phone with no hint that more tabs exist. |
| 6 | My Tasks' floor buttons are larger than `StageActions` (same colours, different size); the 48px target applies to both. |

## Cycle 3 — Shop-floor workflow (closed)

**The question:** does the work flow on the floor without anyone getting stuck or left in the dark?

**Inspection.** The route was traced from assignment to stock: confirm → assign → start → count → hand-off to QC → QC result → complete. Each step was followed through the server (`ProductionController`, `NotificationService`) and the tailor's and manager's screens.

**What the pass found:**
- **A failed QC is a dead end.** No route moves an order out of `qc_failed`. Floor work is refused ("a manager must decide what happens next"), but there is no screen or endpoint for that decision. This is ST-2 in `SYSTEM_AUDIT_AND_ROADMAP.md`.
- **The tailor never hears the result.** The QC notice goes to owners and managers only. Her stages on the order are all done, so a failed order sat in her **Completed** lane, and only under "All", never under "Active".
- **New work was invisible on the WIP board.** It showed in progress → QC, but not *pending*: a confirmed, assigned job nobody had started appeared nowhere until someone pressed Start.
- The QC endpoint answered "QC failed. Order placed on hold." Nothing is put on hold; the order goes to `qc_failed`.

### What changed

- **The makers hear the QC result.** `NotificationService::productionQcResultForMakers` tells every assignee of a stage on the order, pass or fail. A fail includes the inspector's notes. It opens My Tasks. Managers keep their own notice; the inspector is not sent the bench notice.
- **A failed order stays in front of the tailor.**
  - My Tasks' Active queue asks for `include_awaiting_qc=true`, which adds her finished stages on orders in `qc_pending` or `qc_failed`. The flag is opt-in, so Home's top-four list stays open work.
  - A new **Failed QC** lane, second after In progress, says "Your manager will decide the rework — nothing to do yet".
  - "Ready for QC" now shows under Active too.
- **WIP leads with Pending**, so newly assigned work is visible before anyone starts it.
- The QC failure response reads "QC failed. A manager will decide the rework."

### Tests

- `QcResultReachesMakersTest` (new), on a fixture of one QC order with a cutter and a stitcher, a third tailor not on it, and an admin inspector:
  - a fail tells both makers once, with the order number, the notes and the My Tasks link; the bystander and the inspector get no bench notice;
  - a pass tells the makers too;
  - the failed order's finished stage is in her Active queue with the flag, and absent from Home's list without it.
  All three fail on the previous code.
- `TailorQcSegregationTest`, `TailorMyTasksPayloadTest` and `TailorFloorSafetyTest` pass unchanged.

### Proposed, not built — the rework path (needs the owner's approval)

Leaving `qc_failed` changes stage state and piece counts, which feed completion and the stock-in. Under CLAUDE.md §1 that is a consequential change, so it is proposed with a decision audit, not built.

The shape proposed:
- a manager action, **"Send back for rework"**, from `qc_failed` to `in_progress`;
- the manager picks the stages to redo and how many pieces;
- the order re-enters QC through the existing all-stages-done hand-off;
- the failed QC record stays as history.

### Recorded for later cycles (non-blocking)

| Cycle | Finding |
|---|---|
| 4 | The Queue header counts "3 orders · 3 tasks" including orders that only wait on QC. It should count open work. |
| 5 | QC records `passed_quantity` / `failed_quantity`, but a fail fails the whole order. A partial pass (9 of 10) has no path. |

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
