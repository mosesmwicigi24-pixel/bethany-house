<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a station needs to render itself.
 *
 * The tailor screen answers four questions the ledger alone cannot: what does
 * this station look like, what should I check before handing work on, how much
 * is a shift, and what do I call this in Kiswahili.
 *
 * **Icons and colours are fixed per stage, in data.** The same scissors mean
 * Cutting on the phone, on the rail, and on the printed bundle ticket, so the
 * mapping is learned once and never re-learned. Hard-coding it in the client
 * would guarantee the ticket and the screen drift apart the first time somebody
 * adds a station.
 *
 * **Checklists live on the stage, not the order.** "Piping continuous at the
 * collar join" is a property of Finishing, not of a particular cassock. Putting
 * it here means one edit updates every job at that bench. It guides and never
 * gates — a tailor who cannot tick a box must still be able to move work, or
 * she will simply stop using the screen.
 *
 * **Kiswahili is a column, not a client bundle.** Reason codes are already data
 * so a supervisor can add one without a deploy; their translations have to be
 * data for the same reason. Machine translation is not acceptable here — these
 * strings are reviewed by someone who sews.
 */
return new class extends Migration
{
    /**
     * The plan's hold vocabulary. Deliberately five: a list nobody can read is
     * a list everybody bottom-taps, and a wrong reason is worse than a coarse
     * one.
     */
    private const HOLD_REASONS = [
        ['NO_FABRIC',     'Fabric not delivered',   'Kitambaa hakijafika', 10],
        ['NO_TRIM',       'Trim or buttons short',  'Vifungo havitoshi',   20],
        ['MACHINE_DOWN',  'Machine down',           'Mashine imeharibika', 30],
        ['SPEC_QUERY',    'Need to check the spec', 'Nahitaji kuuliza',    40],
        ['MEASURE_QUERY', 'Measurement unclear',    'Vipimo havieleweki',  50],
    ];

    /** Swahili for the reasons already seeded with the ledger. */
    private const TRANSLATIONS = [
        'SEAM_PUCKER'     => 'Mshono umekunjamana',
        'CROOKED_STITCH'  => 'Mshono umepinda au umekatika',
        'WRONG_SIZE'      => 'Vipimo si sahihi',
        'TRIM_MISALIGNED' => 'Pambo halijakaa sawa',
        'FINISH_REDO'     => 'Kupiga pasi upya',
        'FABRIC_FLAW'     => 'Kitambaa kina kasoro',
        'CUT_ERROR'       => 'Kukata vibaya',
        'STAIN_DAMAGE'    => 'Kimechafuka au kimeharibika',
        'BURN_IRON'       => 'Kimeungua kwa pasi',
        'INITIAL_LOAD'    => 'Mzigo wa kwanza',
        'REPLACEMENT'     => 'Badala ya kilichoharibika',
        'ADDED_SCOPE'     => 'Mteja ameongeza',
    ];

    /**
     * Icon per stage, keyed by the stage code the provisioner mints. Falls back
     * to kind, so a station nobody anticipated still gets something sensible
     * rather than a blank square.
     */
    private const ICONS = [
        'NOT_CUT'   => 'fabric',
        'CUTTING'   => 'scissors',
        'SEWING'    => 'needle',
        'STITCHING' => 'needle',
        'BUTTONS'   => 'button',
        'FINISHING' => 'iron',
        'QC'        => 'eye',
        'FINISHED'  => 'check-circle',
        'SCRAPPED'  => 'x-circle',
    ];

    private const KIND_ICONS = [
        'QUEUE'   => 'fabric',
        'WORK'    => 'needle',
        'INSPECT' => 'eye',
        'DONE'    => 'check-circle',
        'SCRAP'   => 'x-circle',
    ];

    public function up(): void
    {
        Schema::table('production_workflow_stages', function (Blueprint $table) {
            $table->json('checklist')->nullable()->after('color');
            $table->string('icon', 40)->nullable()->after('checklist');
            // Pieces a full shift at this bench is expected to move. Nullable —
            // a station without an agreed number shows progress, not a verdict.
            $table->unsignedInteger('daily_target')->nullable()->after('icon');
        });

        Schema::table('movement_reasons', function (Blueprint $table) {
            $table->string('label_sw', 150)->nullable()->after('label');
        });

        $this->seedIcons();
        $this->seedReasonTranslations();
        $this->seedHoldReasons();
        $this->extendDistributionView();
    }

    /**
     * A Postgres view is a frozen query, not a live `SELECT *` — the columns
     * added above are invisible to `v_batch_stage_distribution` until it is
     * redefined. Appending them (replacing in place keeps existing columns and
     * their order, which is all `CREATE OR REPLACE VIEW` permits) means the
     * station screen can still render itself from one read.
     */
    private function extendDistributionView(): void
    {
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
       s.last_out_at,
       st.icon,
       st.checklist,
       st.daily_target
  FROM production_order_batches b
  JOIN production_workflow_stages st
    ON st.production_workflow_id = b.production_workflow_id
  LEFT JOIN batch_stage_states s
    ON s.production_order_batch_id = b.id
   AND s.production_workflow_stage_id = st.id
SQL);
    }

    private function seedIcons(): void
    {
        foreach (DB::table('production_workflow_stages')->get(['id', 'code', 'kind']) as $stage) {
            $code = strtoupper((string) $stage->code);

            DB::table('production_workflow_stages')
                ->where('id', $stage->id)
                ->update([
                    'icon' => self::ICONS[$code]
                        ?? self::KIND_ICONS[$stage->kind]
                        ?? 'needle',
                ]);
        }
    }

    private function seedReasonTranslations(): void
    {
        foreach (self::TRANSLATIONS as $code => $swahili) {
            DB::table('movement_reasons')->where('code', $code)->update(['label_sw' => $swahili]);
        }
    }

    /**
     * Replace the ledger's provisional hold vocabulary with the floor's.
     *
     * The superseded codes are deactivated rather than deleted: `reason_code` is
     * a foreign key on every movement, and a movement that already explained
     * itself must keep being able to. Deactivating removes them from the picker
     * while leaving history readable — which is the same reason scrap is a stage
     * and not a subtraction.
     */
    private function seedHoldReasons(): void
    {
        $now = now();

        foreach (self::HOLD_REASONS as [$code, $label, $swahili, $order]) {
            DB::table('movement_reasons')->updateOrInsert(
                ['code' => $code],
                [
                    'label'      => $label,
                    'label_sw'   => $swahili,
                    'applies_to' => 'HOLD',
                    'is_defect'  => false,
                    'is_active'  => true,
                    'sort_order' => $order,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        DB::table('movement_reasons')
            ->where('applies_to', 'HOLD')
            ->whereNotIn('code', array_column(self::HOLD_REASONS, 0))
            ->update(['is_active' => false]);
    }

    public function down(): void
    {
        // The view has to let go of the columns before they can be dropped.
        DB::statement('DROP VIEW IF EXISTS v_batch_stage_distribution CASCADE');

        Schema::table('production_workflow_stages', function (Blueprint $table) {
            $table->dropColumn(['checklist', 'icon', 'daily_target']);
        });

        Schema::table('movement_reasons', function (Blueprint $table) {
            $table->dropColumn('label_sw');
        });
    }
};
