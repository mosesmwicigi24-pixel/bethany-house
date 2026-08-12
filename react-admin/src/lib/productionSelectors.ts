/**
 * productionSelectors.ts
 *
 * Pure read-model logic for the piece-movement line. No I/O, no framework —
 * feed it the stage distribution the API returns and it produces everything the
 * order card, the worker move sheet and the manager view need, including the
 * operational judgements a line manager otherwise makes by eye.
 *
 * Field names are snake_case because these objects arrive from the API exactly
 * as written here; converting them at the boundary would buy nothing and lose
 * the ability to hand a raw response straight to a selector.
 *
 * The backend carries the same logic in ProductionReadModel.php. That
 * duplication is deliberate and narrow: the server needs these numbers for the
 * order card, PDFs and notifications, while the client needs them to render
 * optimistically before a movement round-trips. Both are pure functions over
 * the same rows, and the movement tests pin the behaviour they share.
 */

export type StageKind = 'QUEUE' | 'WORK' | 'INSPECT' | 'DONE' | 'SCRAP';
export type Bucket = 'READY' | 'HELD';
export type MovementKind =
  | 'INTAKE' | 'FORWARD' | 'REWORK' | 'SCRAP' | 'HOLD' | 'RELEASE' | 'REVERSAL';

export interface StageRow {
  stage_id: number;
  stage_code: string;
  stage_name: string;
  seq: number;
  kind: StageKind;
  wip_limit: number | null;
  target_minutes: number | null;
  allows_rework: boolean;
  color: string | null;
  qty_ready: number;
  qty_held: number;
  qty_total: number;
  entered_at: string | null;   // when this stage last went empty → occupied
  last_out_at: string | null;  // last time a piece left it
}

export interface BatchRow {
  batch_id: number;
  label: string;
  ordered_qty: number;
  loaded_qty: number;
  due_at: string | null;
  stages: StageRow[];
}

const qty = (r: StageRow) => r.qty_total;
const sum = (rows: StageRow[], f: (r: StageRow) => number) => rows.reduce((a, r) => a + f(r), 0);
const onLine = (rows: StageRow[]) =>
  rows.filter(s => s.kind !== 'SCRAP').sort((a, b) => a.seq - b.seq);

// ---------------------------------------------------------------------------
// 1. Headline numbers
// ---------------------------------------------------------------------------

export interface Snapshot {
  total: number;          // pieces physically in the system
  ordered: number;        // pieces the customer must receive
  notStarted: number;     // sitting in the entry queue
  inProduction: number;   // between the first cut and QC, inclusive
  finished: number;
  scrapped: number;
  held: number;           // subset of inProduction that is stopped
  deliverable: number;    // best case still achievable = total − scrapped
  shortfall: number;      // > 0 means you cannot fulfil without re-cutting
  percentComplete: number;
}

export function snapshotOf(stages: StageRow[], orderedQty: number): Snapshot {
  const total = sum(stages, qty);
  const scrapped = sum(stages.filter(s => s.kind === 'SCRAP'), qty);
  const finished = sum(stages.filter(s => s.kind === 'DONE'), qty);
  const deliverable = total - scrapped;

  return {
    total,
    ordered: orderedQty,
    notStarted: sum(stages.filter(s => s.kind === 'QUEUE'), qty),
    inProduction: sum(stages.filter(s => s.kind === 'WORK' || s.kind === 'INSPECT'), qty),
    finished,
    scrapped,
    held: sum(stages, s => s.qty_held),
    deliverable,
    shortfall: Math.max(0, orderedQty - deliverable),
    percentComplete: orderedQty ? Math.round((finished / orderedQty) * 100) : 0,
  };
}

/** Order rollup. Batches never share a stage counter, so this is a plain sum. */
export function snapshotOfOrder(batches: BatchRow[]): Snapshot {
  const parts = batches.map(b => snapshotOf(b.stages, b.ordered_qty));
  const add = (f: (s: Snapshot) => number) => parts.reduce((a, s) => a + f(s), 0);

  const ordered = add(s => s.ordered);
  const finished = add(s => s.finished);
  const deliverable = add(s => s.deliverable);

  return {
    total: add(s => s.total),
    ordered,
    notStarted: add(s => s.notStarted),
    inProduction: add(s => s.inProduction),
    finished,
    scrapped: add(s => s.scrapped),
    held: add(s => s.held),
    deliverable,
    shortfall: Math.max(0, ordered - deliverable),
    percentComplete: ordered ? Math.round((finished / ordered) * 100) : 0,
  };
}

/** "100 Total · 70 Not Started · 24 In Production · 6 Finished" */
export function headlineSegments(s: Snapshot): { value: number; label: string; tone: string }[] {
  const out = [
    { value: s.total, label: 'Total', tone: 'neutral' },
    { value: s.notStarted, label: 'Not Started', tone: 'muted' },
    { value: s.inProduction, label: 'In Production', tone: 'active' },
    { value: s.finished, label: 'Finished', tone: 'success' },
  ];

  // Scrap earns a place in the headline only when it exists — visible when it
  // matters, not noise when it doesn't.
  if (s.scrapped > 0) out.push({ value: s.scrapped, label: 'Scrapped', tone: 'danger' });
  return out;
}

/** "Not Cut 70 · Cutting 10 · Sewing 2 · Button Fixing 4 · QC 2 · Finished 6" */
export function distributionChips(stages: StageRow[], opts = { hideEmpty: false }) {
  return stages
    .filter(s => s.kind !== 'SCRAP' || qty(s) > 0)
    .filter(s => !opts.hideEmpty || qty(s) > 0)
    .sort((a, b) => a.seq - b.seq)
    .map(s => ({
      stageId: s.stage_id,
      label: s.stage_name,
      qty: qty(s),
      held: s.qty_held,
      color: s.color,
      overWip: s.wip_limit != null && qty(s) > s.wip_limit,
      dimmed: qty(s) === 0,
    }));
}

// ---------------------------------------------------------------------------
// 2. Worker move sheet — 1 · 5 · 10 · Custom
// ---------------------------------------------------------------------------

export interface MoveChip { value: number; enabled: boolean; label: string }

export interface MoveSheet {
  stageId: number;
  stageName: string;
  available: number;            // qty_ready — the only quantity that can move
  held: number;
  nextStageId: number | null;
  nextStageName: string | null;
  chips: MoveChip[];
  customMin: number;
  customMax: number;
  canMove: boolean;
  blockedReason: string | null;
  allChip: MoveChip | null;     // "Move all 10" — the tap workers actually want
}

export const DEFAULT_CHIPS = [1, 5, 10];

/**
 * The quantity chips a station may offer, and the "All n" tap workers actually
 * want.
 *
 * Shared deliberately. The quantity a chip offers and the quantity the server
 * accepts have to be decided by one piece of code or they drift, and the way
 * that drift shows up is a worker tapping a live button and being told no.
 * A chip above what is available is rendered disabled — never enabled-then-
 * rejected, which is how impossible totals get typed in.
 */
export function moveChips(
  available: number,
  presets: number[] = DEFAULT_CHIPS,
  blocked = false,
): { chips: MoveChip[]; allChip: MoveChip | null } {
  const chips: MoveChip[] = presets.map(v => ({
    value: v, enabled: !blocked && v <= available, label: String(v),
  }));

  const allChip: MoveChip | null =
    !blocked && available > 0 && !presets.includes(available)
      ? { value: available, enabled: true, label: `All ${available}` }
      : null;

  return { chips, allChip };
}

export function buildMoveSheet(
  stages: StageRow[], stageId: number, presets: number[] = DEFAULT_CHIPS,
): MoveSheet {
  const line = onLine(stages);
  const i = line.findIndex(s => s.stage_id === stageId);
  const cur = line[i];
  const nxt = i >= 0 && i < line.length - 1 ? line[i + 1] : null;

  const available = cur?.qty_ready ?? 0;
  const held = cur?.qty_held ?? 0;

  let blockedReason: string | null = null;
  if (!cur) blockedReason = 'Stage not found in this workflow.';
  else if (cur.kind === 'DONE') blockedReason = 'These pieces are finished.';
  else if (!nxt) blockedReason = 'No next stage.';
  else if (available === 0 && held > 0)
    blockedReason = `All ${held} piece(s) here are on hold. Release them first.`;
  else if (available === 0) blockedReason = 'Nothing at this stage yet.';

  // Never offer a chip the worker cannot honour — same rule, same code, as the
  // station screen uses.
  const { chips, allChip } = moveChips(available, presets, Boolean(blockedReason));

  return {
    stageId,
    stageName: cur?.stage_name ?? '',
    available,
    held,
    nextStageId: nxt?.stage_id ?? null,
    nextStageName: nxt?.stage_name ?? null,
    chips,
    customMin: available > 0 ? 1 : 0,
    customMax: available,
    canMove: !blockedReason,
    blockedReason,
    allChip,
  };
}

/** Clamp anything typed into Custom. Silently. Never post out of range. */
export const clampMove = (n: unknown, available: number): number => {
  const v = Math.floor(Number(n));
  if (!Number.isFinite(v) || v < 1) return 0;
  return Math.min(v, available);
};

/** "Cutting 10 → 6 · Sewing 2 → 6" — shown before the worker confirms. */
export function previewMove(stages: StageRow[], fromStageId: number, toStageId: number, n: number) {
  const from = stages.find(s => s.stage_id === fromStageId);
  const to = stages.find(s => s.stage_id === toStageId);
  if (!from || !to) return null;

  return {
    from: { name: from.stage_name, before: qty(from), after: qty(from) - n },
    to: { name: to.stage_name, before: qty(to), after: qty(to) + n },
    conserved: true, // by construction: one leaves, one arrives
  };
}

/** Stages a rework may target: earlier, on the line, never the uncut queue. */
export function reworkTargets(stages: StageRow[], fromStageId: number): StageRow[] {
  const from = stages.find(s => s.stage_id === fromStageId);
  if (!from || !from.allows_rework) return [];

  return onLine(stages).filter(s => s.seq < from.seq && s.kind !== 'QUEUE');
}

// ---------------------------------------------------------------------------
// 3. Line-manager intelligence
// ---------------------------------------------------------------------------

const hoursSince = (iso: string | null, now: number) =>
  iso ? (now - Date.parse(iso)) / 3_600_000 : 0;

export interface StageAlert {
  stageId: number;
  stageName: string;
  severity: 'info' | 'warn' | 'critical';
  kind: 'BOTTLENECK' | 'STALLED' | 'HELD' | 'STARVED' | 'OVER_WIP';
  message: string;
}

/**
 * The things a line manager actually looks for when walking the floor.
 *
 *  BOTTLENECK — work is piling up faster than it drains
 *  STALLED    — pieces are sitting and nothing has left in hours
 *  HELD       — work is stopped for a reason someone must resolve
 *  STARVED    — a downstream station is idle while upstream is loaded
 *  OVER_WIP   — a station is above its declared limit
 */
export function stageAlerts(
  stages: StageRow[],
  opts = { stalledAfterHours: 8, now: Date.now() },
): StageAlert[] {
  const line = onLine(stages);
  const alerts: StageAlert[] = [];

  const work = line.filter(s => s.kind === 'WORK' || s.kind === 'INSPECT');
  const busiest = work.reduce<StageRow | null>((m, s) => (!m || qty(s) > qty(m) ? s : m), null);

  for (const s of line) {
    const q = qty(s);

    if (s.wip_limit != null && q > s.wip_limit) {
      alerts.push({
        stageId: s.stage_id, stageName: s.stage_name, severity: 'warn', kind: 'OVER_WIP',
        message: `${s.stage_name} is holding ${q} against a limit of ${s.wip_limit}.`,
      });
    }

    if (s.qty_held > 0) {
      alerts.push({
        stageId: s.stage_id, stageName: s.stage_name, severity: 'critical', kind: 'HELD',
        message: `${s.qty_held} piece(s) stopped at ${s.stage_name}.`,
      });
    }

    if (q > 0 && s.kind !== 'DONE' && s.kind !== 'QUEUE') {
      const idle = hoursSince(s.last_out_at ?? s.entered_at, opts.now);
      if (idle >= opts.stalledAfterHours) {
        alerts.push({
          stageId: s.stage_id, stageName: s.stage_name, severity: 'warn', kind: 'STALLED',
          message: `Nothing has left ${s.stage_name} in ${Math.round(idle)}h.`,
        });
      }
    }
  }

  if (busiest && qty(busiest) >= 2) {
    const others = work.filter(s => s.stage_id !== busiest.stage_id);
    const avg = others.length ? sum(others, qty) / others.length : 0;

    if (qty(busiest) > Math.max(3, avg * 2)) {
      alerts.push({
        stageId: busiest.stage_id, stageName: busiest.stage_name,
        severity: 'warn', kind: 'BOTTLENECK',
        message: `${busiest.stage_name} is the constraint — ${qty(busiest)} piece(s) queued behind it.`,
      });
    }

    for (const s of work) {
      if (qty(s) === 0 && s.seq > busiest.seq) {
        alerts.push({
          stageId: s.stage_id, stageName: s.stage_name, severity: 'info', kind: 'STARVED',
          message: `${s.stage_name} is idle while ${busiest.stage_name} is loaded.`,
        });
      }
    }
  }

  return alerts;
}

/**
 * Remaining standard minutes: for each piece, the sum of target minutes for
 * every stage it has not yet passed. This is what tells you on Tuesday whether
 * Friday's delivery is real.
 */
export function remainingStandardMinutes(stages: StageRow[]): number {
  const line = stages
    .filter(s => s.kind !== 'SCRAP' && s.kind !== 'DONE')
    .sort((a, b) => a.seq - b.seq);

  let total = 0;
  for (let i = 0; i < line.length; i++) {
    const downstream = line.slice(i).reduce((a, s) => a + (s.target_minutes ?? 0), 0);
    total += qty(line[i]) * downstream;
  }
  return total;
}

export function completionRisk(
  stages: StageRow[], dueAt: string | null,
  capacityMinutesPerDay: number, now = Date.now(),
): { requiredDays: number; availableDays: number; atRisk: boolean } | null {
  if (!dueAt || capacityMinutesPerDay <= 0) return null;

  const requiredDays = remainingStandardMinutes(stages) / capacityMinutesPerDay;
  const availableDays = (Date.parse(dueAt) - now) / 86_400_000;

  return { requiredDays, availableDays, atRisk: requiredDays > availableDays };
}

// ---------------------------------------------------------------------------
// 4. Progress, rendered rather than stored
// ---------------------------------------------------------------------------

/**
 * What a stage has "done" — pieces already downstream of it — and what still
 * stands at it. Derived, never typed: the same rule the server uses to keep the
 * legacy task counters in step with the ledger.
 */
export const taskProgress = (stages: StageRow[], stageId: number, batchLoaded: number) => {
  const line = onLine(stages);
  const i = line.findIndex(x => x.stage_id === stageId);
  const s = line[i];

  return {
    remaining: s ? qty(s) : 0,
    done: i < 0 ? 0 : sum(line.slice(i + 1), qty),
    total: batchLoaded,
  };
};
