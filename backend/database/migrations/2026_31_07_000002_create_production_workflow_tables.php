<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow definition for the piece-movement line — stages become DATA.
 *
 * Until now the pipeline was a flat, global catalogue (`production_stages`):
 * every order everywhere shared one list, and the order of work lived in
 * `production_tasks.sequence`. That cannot express "a cassock is cut, sewn,
 * buttoned, finished, inspected" while "an alb skips buttons", and it cannot
 * express the two things a line actually needs — an entry queue that holds
 * uncut pieces, and a terminal for pieces written off.
 *
 * So a workflow is a versioned, ordered list of stages, and a batch pins the
 * exact workflow row it started on. Re-sequencing the template tomorrow can
 * never re-write history for an order that is halfway down the line: editing a
 * workflow creates version n+1 and in-flight batches keep pointing at the
 * version they were loaded under.
 *
 * Five stage kinds carry the whole grammar:
 *   QUEUE   — the entry buffer. "Not Cut". Pieces exist, no work has begun.
 *   WORK    — a value-adding station. Cutting, Sewing, Buttons, Finishing.
 *   INSPECT — a gate that may send pieces BACKWARDS. QC.
 *   DONE    — terminal, good.
 *   SCRAP   — terminal, bad, off-line, but still counted (see the ledger
 *             migration for why subtracting scrap instead would be a lie).
 *
 * Deviation from the reference spec, deliberate: the spec models these domains
 * as native Postgres ENUM types. This codebase does not — `production_orders.
 * status` is a VARCHAR on Postgres by explicit decision (see the phase-4
 * migration). Native enums also make evolution painful: `ALTER TYPE … ADD
 * VALUE` cannot be used in the same transaction that adds it, and Laravel wraps
 * migrations in a transaction on Postgres. VARCHAR + CHECK enforces exactly the
 * same closed domain, stays consistent with the rest of the schema, and lets a
 * sixth stage kind or an eighth movement type ship as a one-line ALTER.
 *
 * `production_stage_id` is the bridge back to the legacy catalogue. The task
 * system still creates one task per (order, legacy stage), so the movement
 * engine needs to know which legacy stage a workflow stage speaks for in order
 * to open, close and block those tasks. Nullable, because the QUEUE/DONE/SCRAP
 * terminals are movement-system concepts with no task behind them.
 */
return new class extends Migration
{
    /** Stage kinds — the closed domain enforced by CHECK below. */
    private const STAGE_KINDS = ['QUEUE', 'WORK', 'INSPECT', 'DONE', 'SCRAP'];

    /** Movement kinds a reason code may be attached to. */
    private const REASON_KINDS = ['REWORK', 'SCRAP', 'HOLD', 'INTAKE'];

    public function up(): void
    {
        // ── 1. Workflows — immutable-versioned ───────────────────────────
        Schema::create('production_workflows', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60);              // 'CASSOCK_STD', 'ALB_STD'
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['code', 'version']);
            $table->index(['is_active']);
        });

        // Exactly one workflow may be the fallback for orders that name none.
        DB::statement("
            CREATE UNIQUE INDEX ux_workflow_single_default
                ON production_workflows ((is_default)) WHERE is_default = true
        ");

        // ── 2. Stages — ordered, kinded, per workflow ────────────────────
        Schema::create('production_workflow_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_workflow_id')
                ->constrained('production_workflows')->cascadeOnDelete();

            // Bridge to the legacy global catalogue so the task system keeps working.
            $table->foreignId('production_stage_id')
                ->nullable()->constrained('production_stages')->nullOnDelete();

            $table->string('code', 60);              // 'NOT_CUT', 'CUTTING', 'QC'
            $table->string('name', 150);             // 'Not Cut', 'Cutting', 'QC'
            $table->unsignedInteger('seq');          // line order; SCRAP uses 9999
            $table->string('kind', 12)->default('WORK');
            $table->unsignedInteger('wip_limit')->nullable();      // soft cap → bottleneck flag
            $table->unsignedInteger('target_minutes')->nullable(); // std minutes/piece → ETA
            $table->boolean('allows_rework')->default(false);      // may send pieces backwards
            $table->string('default_role', 100)->nullable();       // who gets the auto-created task
            $table->string('color', 20)->nullable();               // UI chip colour
            $table->timestamps();

            $table->unique(['production_workflow_id', 'code'], 'uq_wf_stage_code');
            $table->unique(['production_workflow_id', 'seq'], 'uq_wf_stage_seq');
            $table->index(['production_stage_id']);
        });

        DB::statement(sprintf(
            "ALTER TABLE production_workflow_stages
                 ADD CONSTRAINT ck_wf_stage_kind CHECK (kind IN ('%s'))",
            implode("','", self::STAGE_KINDS)
        ));

        // A line has one way in, one good way out, one bad way out.
        foreach (['QUEUE', 'DONE', 'SCRAP'] as $kind) {
            DB::statement(sprintf(
                "CREATE UNIQUE INDEX ux_wf_stage_one_%s
                     ON production_workflow_stages (production_workflow_id)
                  WHERE kind = '%s'",
                strtolower($kind),
                $kind
            ));
        }

        // ── 3. Movement reasons — no silent defects ──────────────────────
        // Rework, scrap and hold must always say why. That single requirement is
        // what turns the ledger into a defect Pareto for free.
        Schema::create('movement_reasons', function (Blueprint $table) {
            $table->string('code', 60)->primary();   // 'FABRIC_FLAW', 'SEAM_PUCKER'
            $table->string('label', 150);
            $table->string('applies_to', 12);        // which movement kind it explains
            $table->boolean('is_defect')->default(true);  // include in the defect Pareto
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['applies_to', 'is_active']);
        });

        DB::statement(sprintf(
            "ALTER TABLE movement_reasons
                 ADD CONSTRAINT ck_reason_applies_to CHECK (applies_to IN ('%s'))",
            implode("','", self::REASON_KINDS)
        ));

        $this->seedReasons();
        $this->seedDefaultWorkflow();
    }

    /**
     * A starter reason catalogue. Deliberately short: a list nobody can read is
     * a list everybody bottom-taps, and a wrong reason is worse than a coarse
     * one. These are editable at runtime — the point is that day one is not
     * spent typing free text.
     */
    private function seedReasons(): void
    {
        $now  = now();
        $rows = [
            // Rework — the piece is recoverable.
            ['SEAM_PUCKER',     'Seam puckering',            'REWORK', true,  10],
            ['CROOKED_STITCH',  'Crooked or broken stitch',  'REWORK', true,  20],
            ['WRONG_SIZE',      'Measurement off spec',      'REWORK', true,  30],
            ['TRIM_MISALIGNED', 'Trim or plate misaligned',  'REWORK', true,  40],
            ['FINISH_REDO',     'Pressing or finish redo',   'REWORK', true,  50],
            // Scrap — the piece is gone.
            ['FABRIC_FLAW',     'Fabric flaw',               'SCRAP',  true,  10],
            ['CUT_ERROR',       'Cut error',                 'SCRAP',  true,  20],
            ['STAIN_DAMAGE',    'Stained or damaged',        'SCRAP',  true,  30],
            ['BURN_IRON',       'Iron or machine burn',      'SCRAP',  true,  40],
            // Hold — the piece is fine, the line is not.
            ['NO_MATERIAL',     'Waiting on fabric',         'HOLD',   false, 10],
            ['NO_TRIM',         'Waiting on buttons or trim', 'HOLD',  false, 20],
            ['SPEC_QUERY',      'Query on the specification', 'HOLD',  false, 30],
            ['MACHINE_DOWN',    'Machine down',              'HOLD',   false, 40],
            ['AWAITING_FITTING', 'Awaiting customer fitting', 'HOLD',  false, 50],
            // Intake — why extra pieces entered the batch.
            ['INITIAL_LOAD',    'Initial cut load',          'INTAKE', false, 10],
            ['REPLACEMENT',     'Replacement for scrapped piece', 'INTAKE', false, 20],
            ['ADDED_SCOPE',     'Customer added pieces',     'INTAKE', false, 30],
        ];

        DB::table('movement_reasons')->insert(array_map(fn ($r) => [
            'code'       => $r[0],
            'label'      => $r[1],
            'applies_to' => $r[2],
            'is_defect'  => $r[3],
            'is_active'  => true,
            'sort_order' => $r[4],
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows));
    }

    /**
     * Every batch must pin a workflow, so one has to exist before the ledger
     * opens. Two honest paths:
     *
     *   - Stages already configured → mirror them, in their own order, keeping
     *     the legacy id on each so the task bridge can find its way home. A QC
     *     stage is detected by slug and promoted to INSPECT so it can send work
     *     backwards, which is the whole reason QC exists.
     *   - Nothing configured (a fresh install: `production_stages` is created
     *     empty and no seeder fills it) → lay down the standard garment line.
     *
     * Either way the QUEUE/DONE/SCRAP terminals are added, because they are
     * movement-system structure rather than shop-floor stations.
     */
    private function seedDefaultWorkflow(): void
    {
        $now = now();

        $workflowId = DB::table('production_workflows')->insertGetId([
            'code'        => 'STANDARD',
            'name'        => 'Standard production line',
            'description' => 'Default line pinned to batches that name no other workflow.',
            'version'     => 1,
            'is_active'   => true,
            'is_default'  => true,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $legacy = DB::table('production_stages')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $stages = [];
        $seq    = 10;

        // Entry queue — where pieces wait before any work has begun.
        $stages[] = [
            'production_stage_id' => null,
            'code' => 'NOT_CUT', 'name' => 'Not Cut', 'seq' => $seq,
            'kind' => 'QUEUE', 'allows_rework' => false,
            'default_role' => 'cutter', 'color' => '#94a3b8',
        ];

        if ($legacy->isNotEmpty()) {
            foreach ($legacy as $stage) {
                $seq += 10;
                $isQc = $this->looksLikeQc((string) $stage->slug, (string) $stage->name);

                $stages[] = [
                    'production_stage_id' => $stage->id,
                    'code' => $this->codeFor((string) $stage->slug),
                    'name' => $stage->name,
                    'seq'  => $seq,
                    'kind' => $isQc ? 'INSPECT' : 'WORK',
                    'allows_rework' => $isQc,
                    'default_role'  => $isQc ? 'supervisor' : null,
                    'color' => $stage->color ?? null,
                ];
            }
        } else {
            $canonical = [
                ['CUTTING',   'Cutting',       'WORK',    false, 'cutter',     '#f59e0b'],
                ['SEWING',    'Sewing',        'WORK',    false, 'tailor',     '#3b82f6'],
                ['BUTTONS',   'Button Fixing', 'WORK',    false, 'finisher',   '#8b5cf6'],
                ['FINISHING', 'Finishing',     'WORK',    false, 'finisher',   '#14b8a6'],
                ['QC',        'QC',            'INSPECT', true,  'supervisor', '#ef4444'],
            ];
            foreach ($canonical as [$code, $name, $kind, $rework, $role, $color]) {
                $seq += 10;
                $stages[] = [
                    'production_stage_id' => null,
                    'code' => $code, 'name' => $name, 'seq' => $seq,
                    'kind' => $kind, 'allows_rework' => $rework,
                    'default_role' => $role, 'color' => $color,
                ];
            }
        }

        // Terminals.
        $stages[] = [
            'production_stage_id' => null,
            'code' => 'FINISHED', 'name' => 'Finished', 'seq' => $seq + 10,
            'kind' => 'DONE', 'allows_rework' => false,
            'default_role' => null, 'color' => '#22c55e',
        ];
        $stages[] = [
            'production_stage_id' => null,
            'code' => 'SCRAPPED', 'name' => 'Scrapped', 'seq' => 9999,
            'kind' => 'SCRAP', 'allows_rework' => false,
            'default_role' => null, 'color' => '#dc2626',
        ];

        DB::table('production_workflow_stages')->insert(array_map(fn ($s) => $s + [
            'production_workflow_id' => $workflowId,
            'wip_limit'      => null,
            'target_minutes' => null,
            'created_at'     => $now,
            'updated_at'     => $now,
        ], $stages));
    }

    /** QC goes by many names on a shop floor; all of them mean "gate". */
    private function looksLikeQc(string $slug, string $name): bool
    {
        $haystack = strtolower($slug . ' ' . $name);

        foreach (['quality', 'qc', 'inspect', 'check'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** 'quality-check' → 'QUALITY_CHECK'. Stable, readable, unique per workflow. */
    private function codeFor(string $slug): string
    {
        $code = strtoupper(preg_replace('/[^a-z0-9]+/i', '_', $slug) ?? '');

        return trim($code, '_') ?: 'STAGE';
    }

    public function down(): void
    {
        Schema::dropIfExists('movement_reasons');
        Schema::dropIfExists('production_workflow_stages');
        Schema::dropIfExists('production_workflows');
    }
};
