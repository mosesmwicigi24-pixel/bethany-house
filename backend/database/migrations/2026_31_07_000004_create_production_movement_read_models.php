<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replay, integrity, and the read models the floor and the office actually see.
 *
 * `fn_rebuild_batch_state` is the proof that the ledger — not the projection —
 * is authoritative. Throw `batch_stage_states` away for a batch, replay every
 * movement, and you get the same numbers back. Reversals replay naturally
 * because they are stored as ordinary inverse rows rather than as deletions.
 *
 * Two deliberate improvements over the reference implementation:
 *
 * 1. `loaded_qty` is recomputed as (all credits − all debits) across the whole
 *    ledger rather than as SUM(INTAKE). Every internal transfer nets to zero, so
 *    only the one-sided rows survive: an INTAKE adds, an un-intake subtracts.
 *    Conservation stops being a second rule that has to be maintained in step
 *    and becomes an arithmetic identity of the ledger itself.
 *
 * 2. `entered_at` is reconstructed exactly instead of approximated. The
 *    reference took MAX(moved_at) over all inbound rows, which reports the most
 *    recent arrival rather than the moment the stage last went from empty to
 *    occupied — so after a replay the stage-age and STALLED alerts would read
 *    differently than they did live. A running balance over the stage's
 *    movements finds the real empty→occupied crossing, which makes "replay must
 *    equal the projection" a test worth writing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fn_rebuild_batch_state(p_batch bigint) RETURNS void AS $fn$
BEGIN
  DELETE FROM batch_stage_states WHERE production_order_batch_id = p_batch;

  INSERT INTO batch_stage_states (
      production_order_batch_id, production_workflow_stage_id,
      qty_ready, qty_held, entered_at, last_in_at, last_out_at, version)
  SELECT p_batch,
         stage_id,
         COALESCE(SUM(delta) FILTER (WHERE bucket = 'READY'), 0),
         COALESCE(SUM(delta) FILTER (WHERE bucket = 'HELD'),  0),
         -- the last time this stage crossed from empty into occupied
         MAX(at) FILTER (WHERE prev_total = 0 AND total > 0),
         MAX(at) FILTER (WHERE delta > 0),
         MAX(at) FILTER (WHERE delta < 0),
         COUNT(*)
    FROM (
      SELECT s.stage_id, s.bucket, s.delta, s.at,
             SUM(s.delta) OVER win AS total,
             COALESCE(SUM(s.delta) OVER (
                 PARTITION BY s.stage_id ORDER BY s.at, s.ord
                 ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING), 0) AS prev_total
        FROM (
          SELECT to_stage_id AS stage_id, to_bucket AS bucket,
                 quantity AS delta, moved_at AS at, id AS ord
            FROM piece_movements
           WHERE production_order_batch_id = p_batch AND to_stage_id IS NOT NULL
          UNION ALL
          SELECT from_stage_id, from_bucket,
                 -quantity, moved_at, id
            FROM piece_movements
           WHERE production_order_batch_id = p_batch AND from_stage_id IS NOT NULL
        ) s
      WINDOW win AS (PARTITION BY s.stage_id ORDER BY s.at, s.ord
                     ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)
    ) t
   GROUP BY stage_id;

  UPDATE production_order_batches b
     SET loaded_qty = COALESCE((
           SELECT SUM(CASE WHEN to_stage_id   IS NOT NULL THEN quantity ELSE 0 END)
                - SUM(CASE WHEN from_stage_id IS NOT NULL THEN quantity ELSE 0 END)
             FROM piece_movements
            WHERE production_order_batch_id = p_batch
         ), 0)
   WHERE b.id = p_batch;
END;
$fn$ LANGUAGE plpgsql;
SQL);

        // ── Integrity guard ──────────────────────────────────────────────
        // Any row with is_balanced = false is a bug, not a business condition.
        // Checked in CI and by `production:check-integrity` on a schedule.
        DB::statement(<<<'SQL'
CREATE OR REPLACE VIEW v_batch_integrity AS
SELECT b.id                                                     AS batch_id,
       b.production_order_id,
       b.label,
       b.quantity                                               AS ordered_qty,
       b.loaded_qty,
       COALESCE(SUM(s.qty_ready + s.qty_held), 0)                AS distributed_qty,
       b.loaded_qty = COALESCE(SUM(s.qty_ready + s.qty_held), 0) AS is_balanced
  FROM production_order_batches b
  LEFT JOIN batch_stage_states s ON s.production_order_batch_id = b.id
 GROUP BY b.id, b.production_order_id, b.label, b.quantity, b.loaded_qty
SQL);

        // ── Per-batch, per-stage distribution ────────────────────────────
        // The row the worker screen and the order card both render. Every stage
        // in the batch's pinned workflow appears, occupied or not, so the line
        // reads as a line rather than as a sparse set of whatever has pieces.
        DB::statement(<<<'SQL'
CREATE OR REPLACE VIEW v_batch_stage_distribution AS
SELECT b.production_order_id,
       b.id                     AS batch_id,
       b.label                  AS batch_label,
       b.quantity               AS ordered_qty,
       b.loaded_qty,
       b.due_at,
       st.id                    AS stage_id,
       st.production_stage_id,
       st.code                  AS stage_code,
       st.name                  AS stage_name,
       st.seq,
       st.kind,
       st.wip_limit,
       st.target_minutes,
       st.allows_rework,
       st.default_role,
       st.color,
       COALESCE(s.qty_ready, 0) AS qty_ready,
       COALESCE(s.qty_held,  0) AS qty_held,
       COALESCE(s.qty_ready, 0) + COALESCE(s.qty_held, 0) AS qty_total,
       s.entered_at,
       s.last_in_at,
       s.last_out_at
  FROM production_order_batches b
  JOIN production_workflow_stages st
    ON st.production_workflow_id = b.production_workflow_id
  LEFT JOIN batch_stage_states s
    ON s.production_order_batch_id = b.id
   AND s.production_workflow_stage_id = st.id
SQL);

        // ── Order headline ───────────────────────────────────────────────
        // "100 Total · 70 Not Started · 24 In Production · 6 Finished".
        // Ordered quantity is summed once per batch, not once per (batch,stage)
        // row, or a five-stage workflow would report five times the order.
        DB::statement(<<<'SQL'
CREATE OR REPLACE VIEW v_order_snapshot AS
WITH per_batch AS (
  SELECT d.production_order_id,
         d.batch_id,
         MAX(d.ordered_qty)                                        AS ordered_qty,
         MAX(d.loaded_qty)                                         AS loaded_qty,
         COALESCE(SUM(d.qty_total), 0)                             AS total,
         COALESCE(SUM(d.qty_total) FILTER (WHERE d.kind = 'QUEUE'), 0)   AS not_started,
         COALESCE(SUM(d.qty_total) FILTER (WHERE d.kind IN ('WORK','INSPECT')), 0) AS in_production,
         COALESCE(SUM(d.qty_total) FILTER (WHERE d.kind = 'DONE'), 0)    AS finished,
         COALESCE(SUM(d.qty_total) FILTER (WHERE d.kind = 'SCRAP'), 0)   AS scrapped,
         COALESCE(SUM(d.qty_held), 0)                              AS held
    FROM v_batch_stage_distribution d
   GROUP BY d.production_order_id, d.batch_id
)
SELECT production_order_id,
       SUM(ordered_qty)                        AS total_ordered,
       SUM(total)                              AS total_loaded,
       SUM(not_started)                        AS not_started,
       SUM(in_production)                      AS in_production,
       SUM(finished)                           AS finished,
       SUM(scrapped)                           AS scrapped,
       SUM(held)                               AS held,
       SUM(total) - SUM(scrapped)              AS deliverable,
       GREATEST(SUM(ordered_qty) - (SUM(total) - SUM(scrapped)), 0) AS shortfall
  FROM per_batch
 GROUP BY production_order_id
SQL);

        // ── Defect Pareto ────────────────────────────────────────────────
        // The reason code on every rework and scrap was mandatory for exactly
        // this: "60% of rework this month was seam pucker on the purple batch"
        // is now a query rather than a data-collection project.
        DB::statement(<<<'SQL'
CREATE OR REPLACE VIEW v_movement_defect_pareto AS
SELECT m.production_order_id,
       m.production_order_batch_id,
       m.movement_type,
       m.reason_code,
       r.label            AS reason_label,
       r.is_defect,
       st.code            AS stage_code,
       st.name            AS stage_name,
       COUNT(*)           AS movement_count,
       SUM(m.quantity)    AS pieces,
       MIN(m.moved_at)    AS first_seen_at,
       MAX(m.moved_at)    AS last_seen_at
  FROM piece_movements m
  JOIN movement_reasons r ON r.code = m.reason_code
  LEFT JOIN production_workflow_stages st ON st.id = m.from_stage_id
 WHERE m.reason_code IS NOT NULL
 GROUP BY m.production_order_id, m.production_order_batch_id, m.movement_type,
          m.reason_code, r.label, r.is_defect, st.code, st.name
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_movement_defect_pareto');
        DB::statement('DROP VIEW IF EXISTS v_order_snapshot');
        DB::statement('DROP VIEW IF EXISTS v_batch_stage_distribution');
        DB::statement('DROP VIEW IF EXISTS v_batch_integrity');
        DB::statement('DROP FUNCTION IF EXISTS fn_rebuild_batch_state(bigint)');
    }
};
