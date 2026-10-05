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
| 1 | UI/UX coherence | Closed (this PR) |
| 2 | Information hierarchy | Next |
| 3 | Shop-floor workflow | |
| 4 | Interaction quality | |
| 5 | Apparel-production intelligence | |
| 6 | Mobile / tablet excellence | |
| 7 | Offline and recovery | |
| 8 | Accessibility / privacy / trust | |
| 9 | Visual polish and performance | |
| 10 | A full working day (tailor + production manager) | |

---

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
