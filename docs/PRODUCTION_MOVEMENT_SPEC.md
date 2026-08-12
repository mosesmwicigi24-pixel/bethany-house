# Batch & Piece Movement — Operating Spec (as built)

Where the hundred cassocks actually are, and how the system knows.

This is the implemented specification for Bethany House. It follows the
quantity-transfer design, adapted to this codebase — Laravel 11 on PostgreSQL,
with an existing task system that stays exactly as it is. Section 13 lists every
place the implementation deliberately departs from the reference design, and why.

---

## 1. The separation

| Question | Answered by | Source of truth |
|---|---|---|
| Who should work on what next? | Task system | `production_tasks` (unchanged) |
| Where are the 100 pieces right now? | Movement system | `piece_movements` ledger |

The task system keeps everything it already does — one card per production
order, customer resolution, priority, assignment, the sequence gate,
`concurrent_allowed` unlocks. It loses exactly one responsibility: **counting**.

## 2. Non-negotiable invariants

1. **Conservation.** For every batch, `Σ(qty_ready + qty_held)` across all stages
   `= production_order_batches.loaded_qty`. Structural, not conventional: every
   movement is one debit and one credit inside a single transaction, and no code
   path credits a stage without debiting another.
2. **Exclusivity.** A piece occupies exactly one `(stage, bucket)`.
3. **Non-negativity.** `qty_ready >= 0` and `qty_held >= 0` as CHECK
   constraints, plus a conditional `WHERE qty >= n` on every debit.
4. **Append-only history.** Movements are never updated or deleted. Undo posts
   an inverse row.
5. **Rebuildability.** `fn_rebuild_batch_state(batch_id)` reconstructs the
   current state from the ledger alone. If the projection and the replay
   disagree, the projection is wrong.

`v_batch_integrity` surfaces any violation. Any row with `is_balanced = false`
is a defect, not a business condition — `php artisan production:check-integrity`
exits non-zero on it, and `--deep` additionally replays every batch to catch
drift that a self-consistent-but-wrong projection would hide.

## 3. Why scrap is a stage, not a deletion

Subtracting a damaged garment silently breaks conservation and makes every
historical report unreconcilable. Instead `Scrapped` is a terminal stage at
`seq 9999`, off the main line. Damaged pieces move into it and stay counted,
which gives three numbers a manager needs and would otherwise never see:

- **loaded** — pieces ever introduced
- **deliverable** = loaded − scrapped — the best case still achievable
- **shortfall** = ordered − deliverable — how many must be re-cut to fulfil

A 100-piece order that scraps 3 shows `shortfall: 3` on the card until someone
posts an `INTAKE` with reason `REPLACEMENT`. Nobody discovers the gap at packing
time.

## 4. Why hold is a bucket, not a stage

A cassock waiting for buttons is physically at Button Fixing. Making `On Hold` a
stage would move it somewhere it isn't and destroy the "where is it" answer the
whole system exists to give.

Each `(batch, stage)` row carries two buckets: `qty_ready` (workable) and
`qty_held` (stopped, with a reason). Only `qty_ready` can move forward. The
stage chip shows the total; the alert shows the split.

## 5. Movement types

| Type | From → To | Permission | Reason required |
|---|---|---|---|
| `INTAKE` | ∅ → entry queue | `production.load_pieces` | on replacements |
| `FORWARD` | stage *n* → *n+1* | `production.move_pieces` | no |
| `REWORK` | INSPECT stage → any earlier WORK stage | `production.rework_pieces` | **yes** |
| `SCRAP` | any stage → Scrapped | `production.scrap_pieces` | **yes** |
| `HOLD` | READY → HELD, same stage | `production.move_pieces` | **yes** |
| `RELEASE` | HELD → READY, same stage | `production.rework_pieces` | no |
| `REVERSAL` | inverse of a prior movement | `production.reverse_movement` | inherits |

Rework is the one the original brief omitted and the floor needs on day one. QC
fails 2 of 8 — they go back to Sewing, not forward to Finished. Because rework
always carries a reason code, the defect Pareto (`v_movement_defect_pareto`)
falls out for free: *this month, 60% of rework was seam pucker on the purple
batch.*

Forward movement cannot skip stages. If a garment genuinely bypasses Button
Fixing, that is a different workflow, not a skipped stage — and workflows are
per-product data, so one gets created.

A reason must also *match* the movement it explains: a scrap filed under
"waiting on buttons" is rejected with `REASON_INVALID`, because otherwise the
Pareto fills with noise and stops being worth reading.

## 6. Concurrency

Two tailors at Cutting, 10 pieces available, both submit a move of 8.

```sql
UPDATE batch_stage_states SET qty_ready = qty_ready - 8
 WHERE production_order_batch_id = ? AND production_workflow_stage_id = ? AND qty_ready >= 8;
```

The first matches one row. The second matches zero and the transaction aborts
with `INSUFFICIENT_QUANTITY`. No read-then-write gap, no lost update, no
negative stage. The second tailor sees "Cutting does not have 8 piece(s)
available to move" and refreshes to find 2.

The two row updates are applied in ascending stage id order so a `FORWARD` and a
concurrent `REWORK` across the same pair cannot deadlock.

## 7. Idempotency and offline

Every movement carries a device-generated `client_ref` UUID with a unique index.
A tailor on a weak connection in the workshop will tap Move twice; replaying a
`client_ref` returns the original movement without re-applying it.

Two requests carrying the same ref that arrive *simultaneously* both read
"not applied" and both proceed — the unique index decides the winner, and the
loser re-reads the winner's row after its transaction rolls back. Without that
second step a double-tap surfaces as a 500 instead of the movement the worker
expected.

Client sequence:
1. Generate `client_ref`, apply optimistically to local state.
2. Queue to outbox.
3. On success, reconcile with the server row (every write echoes the batch's
   full new distribution, so no second round trip is needed).
4. On `INSUFFICIENT_QUANTITY`, roll back the optimistic update and refetch —
   someone else moved those pieces first.

## 8. Undo

Never delete. `reverse()` posts a compensating movement with `reverses_id` set,
and a partial unique index prevents reversing the same movement twice. A
movement can only be reversed if the pieces are still standing at the
destination — once they have moved on, the correction has to be made forward,
which is also correct in the real world.

Reversing an `INTAKE` is a one-sided debit: the pieces leave the line and
`loaded_qty` comes back down with them.

## 9. The bridge to tasks

`TaskBridge` runs inside the movement's transaction and re-derives everything
the task system displays:

- **progress** — `passed(stage S) = Σ pieces standing at every LINE stage after S`.
  Scrap is excluded: scrapped pieces have left the line, and counting them as
  "passed" would report work still standing on a garment that no longer exists.
- **OPEN / CLOSE** — task status follows the count, including downwards. Rework
  can pull a completed stage back under its order quantity, and a stage that no
  longer has all its pieces through is genuinely not finished.
- **BLOCK / UNBLOCK** — held quantity sets `production_tasks.blocked_reason`
  with the hold reason, and clears it when the hold lifts.

`production_tasks.quantity_done` and `production_task_batch_progress` are
**never typed and never dual-written**. They are a derived projection of the
ledger, recomputed in the same transaction, so they cannot disagree with it.

**Migration.** `LedgerBackfiller` converts the counters the floor runs on today
into positions:

```
at(stage j) = passed(j−1) − passed(j)
at(entry)   = loaded − passed(first working stage)
at(done)    = passed(last working stage)
```

10 pieces, Cutting passed 6, Stitching passed 2 → 4 in the queue, 4 at
Stitching, 2 at QC. Re-deriving the counters from those positions returns 6 and
2, the numbers we started from: the migration is lossless, so no screen, gate or
report changes the day it ships. Batches already carrying movements are skipped,
so `php artisan production:backfill-ledger` is safe to re-run.

## 10. Manager view

**Order card, no drill-down required:**

```
100 Total · 70 Not Started · 24 In Production · 6 Finished
Not Cut 70 · Cutting 10 · Sewing 2 · Button Fixing 4 · Finishing 6 · QC 2 · Finished 6
```

`Scrapped` appears in the headline only when non-zero.

**Alerts** (`stageAlerts()`) — the five things a line manager notices walking the
floor: `BOTTLENECK`, `STALLED`, `HELD`, `STARVED`, `OVER_WIP`.

**Schedule reality** (`completionRisk()`) — remaining standard minutes ÷ daily
capacity vs. days to due date. This answers on Tuesday whether Friday is real,
which is the question the stage distribution is actually being read to answer.

## 11. Deliberate design decisions

**Pieces are fungible within a batch.** No serial numbers. Batch + stage +
quantity is the grain. Piece-level identity would mean 100 rows per order and
barcode scanning at every station — real, but a different product. The ledger
already supports it: split each movement into quantity-1 rows and add a
`piece_id`. Nothing else changes.

**Workflows are immutable-versioned and pinned per batch.** Re-sequencing a
template tomorrow cannot corrupt an order halfway down the line. A workflow is
only amended in place while no batch is pinned to it; after that, a changed line
becomes a new version.

**Movement is always within one batch.** A colourway is a closed loop. Order
totals are aggregations, never stored — so an order total can never disagree
with its batches.

## 12. Edge cases and their answers

| Situation | Behaviour |
|---|---|
| Move more than available | `INSUFFICIENT_QUANTITY`, nothing written |
| Two workers move simultaneously | Second fails cleanly; conditional UPDATE |
| Double tap / retried outbox | Idempotent; returns the original movement |
| Two identical requests at once | Unique index decides; loser re-reads the winner |
| Move from Finished | `TERMINAL_STAGE` |
| Move from Scrapped | `TERMINAL_STAGE` — scrap does not re-enter |
| QC fails 2 of 8 | `REWORK` back to Sewing with reason; 6 continue forward |
| Fabric flaw destroys 3 | `SCRAP` with reason; `shortfall: 3` on the card |
| Re-cut the 3 | `INTAKE` reason `REPLACEMENT`; `loaded_qty` 100 → 103 |
| Missing buttons on 4 | `HOLD` with reason; task flags blocked; 4 stay counted |
| Tailor logs 40 instead of 4 | Supervisor `reverse()`; both rows remain in history |
| Mistaken cut load reversed | One-sided debit; `loaded_qty` comes back down |
| Reason does not fit the movement | `REASON_INVALID` |
| Custom quantity typed as 250 | `clampMove()` returns the available quantity |
| All pieces at a stage on hold | Move sheet disabled: *"All 4 pieces here are on hold. Release them first."* |
| Workflow edited mid-order | In-flight batches keep the pinned version |
| Projection suspected wrong | `production:check-integrity --deep --repair` |

## 13. Departures from the reference design

Each of these is a deliberate adaptation, not an oversight.

1. **`VARCHAR` + `CHECK` instead of native Postgres `ENUM`s.** This schema
   already models closed domains that way (`production_orders.status` is a
   VARCHAR on Postgres by explicit decision). Native enums also make evolution
   painful: `ALTER TYPE … ADD VALUE` cannot be used in the transaction that adds
   it, and Laravel wraps migrations in one. A sixth stage kind now ships as a
   one-line ALTER, with the domain enforced just as strictly.

2. **`bigint` keys, not UUIDs.** Every table these rows reference — orders,
   batches, users, stages — is `bigint`. `client_ref` stays a UUID, because it
   is a device-generated idempotency key rather than a primary key.

3. **Permissions, not hard-coded role names.** The reference maps movement types
   to roles like `supervisor`. This system has a permission catalogue with
   configurable roles on top, so movement types map to permissions. Same intent
   — floor staff move work forward and stop it; supervisors send it backwards,
   write it off, release holds and rewrite history — without a second
   authorisation model that would drift from the first.

4. **Intake reversal is a one-sided debit.** The reference posted it as
   `entry → entry, READY → READY`, which is a no-op row: it trips the
   "must actually move something" constraint, and on replay `loaded_qty` —
   summed from INTAKE rows alone — never comes back down. Conservation breaks
   the first time somebody un-loads a mistaken cut load. Modelling it as a true
   one-sided debit makes the ledger symmetric and lets `loaded_qty` fall out of
   it as *(all credits − all debits)*, so conservation becomes an arithmetic
   identity rather than a second rule to maintain in step.

5. **`entered_at` is reconstructed exactly on replay.** The reference took
   `MAX(moved_at)` over inbound rows, which reports the most recent arrival
   rather than the moment the stage last went empty → occupied — so stage age
   and `STALLED` would read differently after a rebuild. A running balance finds
   the real crossing. Relatedly, every write in one movement shares a single
   timestamp taken once, so a replay reproduces the live projection byte for
   byte, which is what makes "replay must equal the projection" a test worth
   writing.

6. **Terminal stages are checked before the destination is resolved.** Asking
   for the stage after Finished yields nothing, so resolution-first reports
   "no next stage" — technically true and useless. The reference's own edge-case
   table calls for `TERMINAL_STAGE`, and that is what the engine returns.

7. **`task.quantity_done` is derived, not deprecated.** The reference asks the
   UI to render progress from stage state and drop the counter after a
   dual-write release. Here that counter is load-bearing — `effectivePassed()`
   and `blockingTask()` gate whether a stage may start at all — so orphaning it
   would freeze the floor and dual-writing it would invite exactly the drift the
   ledger abolishes. It is instead recomputed from the ledger inside the same
   transaction: nobody types it, it cannot disagree, and every existing consumer
   keeps working untouched.

8. **Workflows are provisioned per stage-template, not seeded once.** Products
   already carry `production_stage_ids`, so two orders in this system genuinely
   run different lines. The workflow is derived from the order's own tasks and
   deduplicated by signature, so orders on the same line share one row.

9. **No `UNIQUE (order_id, label)` on batches.** The constraint is not
   load-bearing — `batch_id` is the grain — and adding one that live data might
   violate would break a production deploy. Worth adding later behind a dedupe.

## 14. Where things live

| Concern | File |
|---|---|
| Schema | `backend/database/migrations/2026_31_07_0000{02,03}_*` |
| Replay, integrity, read models | `…_000004_create_production_movement_read_models.php` |
| Permissions | `…_000005_add_piece_movement_permissions.php` |
| Backfill | `…_000006_backfill_piece_movements.php` → `LedgerBackfiller` |
| Transfer engine | `app/Services/Production/MovementService.php` |
| Transition rules (pure) | `app/Services/Production/WorkflowGraph.php` |
| Task bridge | `app/Services/Production/TaskBridge.php` |
| Workflow provisioning | `app/Services/Production/WorkflowProvisioner.php` |
| Read models (server) | `app/Services/Production/ProductionReadModel.php` |
| Read models (client) | `react-admin/src/lib/productionSelectors.ts` |
| HTTP | `app/Http/Controllers/Api/PieceMovementController.php` |
| Integrity command | `php artisan production:check-integrity [--repair] [--deep]` |
| Backfill command | `php artisan production:backfill-ledger` |
| Tests | `tests/Feature/PieceMovement{,Api}Test.php`, `LedgerBackfillTest.php` |
