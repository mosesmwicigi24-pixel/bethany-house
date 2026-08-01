<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The piece-movement ledger — where the hundred cassocks actually are.
 *
 * The old model answered "how far along is this stage?" with one cumulative
 * counter per (task, batch): `quantity_done`, the pieces that had PASSED that
 * stage. It was a real improvement over a boolean, and it is still the number
 * every existing screen reads — but a single monotone counter cannot express
 * the things a garment line does every day:
 *
 *   - QC fails 2 of 8 and sends them back to Sewing. A monotone counter cannot
 *     go down, so rework was inexpressible and the numbers quietly lied.
 *   - A fabric flaw destroys 3 pieces. Subtracting them makes every historical
 *     report unreconcilable and hides the fact that 3 must be re-cut.
 *   - 4 pieces wait on buttons. They are physically AT Button Fixing, but
 *     nobody can work them — and "stopped" was not a thing the model could say.
 *
 * So state stops being a counter and becomes a position: `batch_stage_states`
 * holds, per (batch, stage), how many pieces are standing there right now,
 * split into workable (`qty_ready`) and stopped (`qty_held`). And that table is
 * only a projection — the truth is `piece_movements`, an append-only ledger of
 * every transfer. Nothing is ever updated or deleted; an undo posts a
 * compensating inverse row, so the history shows both the mistake and the
 * correction. That is what you want when a tailor logs 40 instead of 4 and the
 * manager asks what happened.
 *
 * The invariant the whole design defends:
 *
 *     Σ(qty_ready + qty_held) over all stages  =  batch.loaded_qty
 *
 * It holds structurally, not by convention: every movement is one debit and one
 * credit inside a single transaction, and there is no code path that credits a
 * stage without debiting another. `v_batch_integrity` (next migration) makes a
 * violation a visible defect rather than a silent drift.
 *
 * Why scrap is a stage and not a subtraction: a scrapped piece moves to a
 * terminal SCRAP stage off the main line and stays counted. That yields three
 * numbers a manager otherwise never sees — loaded, deliverable (loaded minus
 * scrapped), and shortfall (ordered minus deliverable). A 100-piece order that
 * scraps 3 shows `shortfall: 3` on the card until someone loads replacements,
 * so nobody discovers the gap at packing time.
 *
 * Why hold is a bucket and not a stage: a cassock waiting for buttons is
 * physically at Button Fixing. Moving it to an "On Hold" stage would put it
 * somewhere it isn't and destroy the one question this system exists to answer.
 */
return new class extends Migration
{
    private const MOVEMENT_KINDS = [
        'INTAKE',   // pieces introduced into the batch (initial load or replacement)
        'FORWARD',  // normal advance to the next stage
        'REWORK',   // QC / supervisor sends pieces backwards
        'SCRAP',    // pieces written off
        'HOLD',     // stop pieces in place (no fabric, no buttons, query on spec)
        'RELEASE',  // un-stop them
        'REVERSAL', // compensating entry that undoes an earlier movement
    ];

    private const BUCKETS = ['READY', 'HELD'];

    public function up(): void
    {
        // ── 1. Batches gain a pinned workflow and a loaded count ─────────
        // `quantity` on this table is the ORDERED quantity — what the customer
        // must receive. It is left untouched: every existing consumer reads it.
        // `loaded_qty` is a different number and needs its own column — how many
        // physical pieces have ever been introduced, initial load plus
        // replacements for scrap. They start equal and diverge the first time
        // something is written off.
        Schema::table('production_order_batches', function (Blueprint $table) {
            $table->foreignId('production_workflow_id')
                ->nullable()
                ->after('production_order_id')
                ->constrained('production_workflows')
                ->nullOnDelete();

            $table->unsignedInteger('loaded_qty')->default(0)->after('quantity');
            $table->timestamp('due_at')->nullable()->after('sort_order');
        });

        // ── 1b. Tasks gain somewhere to show a hold ──────────────────────
        // When pieces are stopped at a stage, the task standing over that stage
        // should say so. The task lifecycle here is pending → in_progress →
        // paused/completed, with no "blocked" member, and bending `paused` (a
        // worker-initiated state) to mean "waiting on buttons" would corrupt a
        // status machine other code already reasons about. A nullable reason is
        // additive, costs nothing when unused, and is what the card renders.
        Schema::table('production_tasks', function (Blueprint $table) {
            $table->string('blocked_reason', 150)->nullable()->after('notes');
            $table->timestamp('blocked_at')->nullable()->after('blocked_reason');
        });

        // ── 2. Current state — the projection, always rebuildable ────────
        // One row per (batch, stage). A piece is in exactly one row. Always.
        Schema::create('batch_stage_states', function (Blueprint $table) {
            $table->foreignId('production_order_batch_id')
                ->constrained('production_order_batches')->cascadeOnDelete();
            $table->foreignId('production_workflow_stage_id')
                ->constrained('production_workflow_stages')->cascadeOnDelete();

            $table->integer('qty_ready')->default(0);
            $table->integer('qty_held')->default(0);

            $table->timestamp('entered_at')->nullable();  // last time empty → occupied
            $table->timestamp('last_in_at')->nullable();
            $table->timestamp('last_out_at')->nullable();

            // Optimistic-concurrency counter; also a cheap "did anything change"
            // signal for the floor screens to poll against.
            $table->unsignedBigInteger('version')->default(0);

            $table->primary(
                ['production_order_batch_id', 'production_workflow_stage_id'],
                'pk_batch_stage_state'
            );
        });

        // Non-negativity is structural. Combined with the conditional
        // `WHERE qty >= n` on every debit, a stage can never go negative and a
        // move can never oversubscribe — even with two tailors tapping at once.
        DB::statement('ALTER TABLE batch_stage_states
            ADD CONSTRAINT ck_bss_ready_non_negative CHECK (qty_ready >= 0)');
        DB::statement('ALTER TABLE batch_stage_states
            ADD CONSTRAINT ck_bss_held_non_negative CHECK (qty_held >= 0)');

        // Only occupied rows are interesting to the floor views.
        DB::statement('CREATE INDEX ix_bss_stage_occupied
            ON batch_stage_states (production_workflow_stage_id)
            WHERE qty_ready + qty_held > 0');

        // ── 3. The ledger — append-only, never updated, never deleted ────
        Schema::create('piece_movements', function (Blueprint $table) {
            $table->id();

            // Idempotency key generated on the device. A double-tap, a retried
            // offline outbox flush, or a flaky workshop connection cannot move
            // the same pieces twice.
            $table->uuid('client_ref')->unique();

            $table->foreignId('production_order_id')          // denormalised for reporting
                ->constrained('production_orders')->cascadeOnDelete();
            $table->foreignId('production_order_batch_id')
                ->constrained('production_order_batches')->cascadeOnDelete();
            $table->foreignId('production_workflow_id')       // as pinned on the batch
                ->constrained('production_workflows');

            $table->string('movement_type', 12);

            $table->foreignId('from_stage_id')->nullable()
                ->constrained('production_workflow_stages');
            $table->foreignId('to_stage_id')->nullable()
                ->constrained('production_workflow_stages');
            $table->string('from_bucket', 8)->nullable();
            $table->string('to_bucket', 8)->nullable()->default('READY');

            $table->integer('quantity');

            $table->string('reason_code', 60)->nullable();
            $table->text('note')->nullable();

            $table->foreignId('moved_by')->constrained('users');
            $table->timestamp('moved_at')->useCurrent();
            $table->string('device_id', 120)->nullable();

            $table->foreignId('reverses_id')->nullable()
                ->constrained('piece_movements');

            $table->foreign('reason_code')->references('code')->on('movement_reasons');

            $table->index(['production_order_batch_id', 'moved_at'], 'ix_pm_batch');
            $table->index(['production_order_id', 'moved_at'], 'ix_pm_order');
            $table->index(['from_stage_id', 'moved_at'], 'ix_pm_from');
            $table->index(['moved_by', 'moved_at'], 'ix_pm_user');
        });

        DB::statement(sprintf(
            "ALTER TABLE piece_movements ADD CONSTRAINT ck_pm_type
                 CHECK (movement_type IN ('%s'))",
            implode("','", self::MOVEMENT_KINDS)
        ));
        DB::statement(sprintf(
            "ALTER TABLE piece_movements ADD CONSTRAINT ck_pm_buckets
                 CHECK (to_bucket IN ('%s')
                        AND (from_bucket IS NULL OR from_bucket IN ('%s')))",
            implode("','", self::BUCKETS),
            implode("','", self::BUCKETS)
        ));

        // A movement must actually move something.
        DB::statement('ALTER TABLE piece_movements ADD CONSTRAINT ck_pm_quantity
            CHECK (quantity > 0)');

        // Sidedness. INTAKE is a one-sided CREDIT: pieces come from outside the
        // line, so it has a destination and no origin. Its reversal has to be
        // the exact mirror — a one-sided DEBIT, origin and no destination —
        // because un-loading pieces removes them from the line entirely.
        //
        // The reference design instead posted an intake reversal as entry→entry
        // READY→READY, which is a no-op row: it trips ck_pm_not_noop, and on
        // replay it nets to zero at the entry stage while `loaded_qty` — summed
        // from INTAKE rows alone — never comes back down. Conservation silently
        // breaks the first time somebody un-loads a mistaken cut load. Modelling
        // it as a true one-sided debit makes the ledger symmetric, and makes
        // loaded_qty fall out of it (see the rebuild migration) instead of being
        // tracked by a second, drift-prone rule.
        //
        // Every other movement is a two-sided transfer: one stage loses exactly
        // what another gains, which is what makes conservation structural.
        DB::statement("ALTER TABLE piece_movements ADD CONSTRAINT ck_pm_origin CHECK (
            (movement_type = 'INTAKE'
                AND from_stage_id IS NULL     AND from_bucket IS NULL
                AND to_stage_id   IS NOT NULL AND to_bucket   IS NOT NULL)
            OR
            (movement_type = 'REVERSAL'
                AND to_stage_id   IS NULL     AND to_bucket   IS NULL
                AND from_stage_id IS NOT NULL AND from_bucket IS NOT NULL)
            OR
            (movement_type <> 'INTAKE'
                AND from_stage_id IS NOT NULL AND from_bucket IS NOT NULL
                AND to_stage_id   IS NOT NULL AND to_bucket   IS NOT NULL)
        )");

        // Rework, scrap and hold must always carry a reason. No silent defects —
        // this one constraint is what makes the defect Pareto fall out for free.
        DB::statement("ALTER TABLE piece_movements ADD CONSTRAINT ck_pm_reason CHECK (
            movement_type NOT IN ('REWORK','SCRAP','HOLD') OR reason_code IS NOT NULL
        )");

        // A no-op movement is a bug in the caller, not a business event.
        DB::statement('ALTER TABLE piece_movements ADD CONSTRAINT ck_pm_not_noop CHECK (
            from_stage_id IS DISTINCT FROM to_stage_id
            OR from_bucket IS DISTINCT FROM to_bucket
        )');

        // A movement can be reversed at most once.
        DB::statement('CREATE UNIQUE INDEX ux_pm_reversal
            ON piece_movements (reverses_id) WHERE reverses_id IS NOT NULL');

        // The defect Pareto: "60% of rework this month was seam pucker on the
        // purple batch" is one grouped scan of this index.
        DB::statement('CREATE INDEX ix_pm_defect
            ON piece_movements (reason_code, moved_at) WHERE reason_code IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('piece_movements');
        Schema::dropIfExists('batch_stage_states');

        Schema::table('production_tasks', function (Blueprint $table) {
            $table->dropColumn(['blocked_reason', 'blocked_at']);
        });

        Schema::table('production_order_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_workflow_id');
            $table->dropColumn(['loaded_qty', 'due_at']);
        });
    }
};
