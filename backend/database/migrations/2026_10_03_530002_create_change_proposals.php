<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role hardening, Phase 3C — proposals: money-relevant values that take effect
 * only after the required signatures (owner's Role Hardening Plan §5;
 * bh-tools/phase3_spec.md "3C").
 *
 *   change_proposals   one row per change to a guarded value, whether it applied
 *                      at once (inside the maker's own band, `direct`) or waited
 *                      for signatures. It is the record the approval engine
 *                      signs (the approvable), and it is the value's HISTORY:
 *                      old → new, who, when it took effect. A rejected or
 *                      expired proposal leaves the live value exactly as it was.
 *
 * Thresholds are seeded into the 3B approval_thresholds table, never code
 * constants. Two kinds of event per guarded value:
 *
 *   <event>          the SIGNATURE bands, judged by the approval engine.
 *   <event>_direct   ONE band: a holder of approver_permission may apply a
 *                    change whose measure is at or under up_to_kes at once,
 *                    without a signature (audited). Above it — or for a maker
 *                    without that key — the change waits for <event>'s bands.
 *
 * The measure is not always money. For the price and cost events up_to_kes
 * holds a PERCENTAGE (the column name is 3B's; the unit is stated per event in
 * App\Services\Approvals\Handlers\ProposalHandler::unit()):
 *
 *   selling_price_change_direct   |change| ≤ 10 %, and not below cost → at once
 *   selling_price_change          % BELOW COST: ≤ 20 → finance; above → + super admin
 *                                 (a change over 10 % that stays at or above cost
 *                                 measures 0 → finance alone)
 *   product_cost_change_direct    |change| ≤ 5 %
 *   product_cost_change           |change| ≤ 25 % → finance; above → + super admin
 *   supplier_cost_change(_direct) the same, for material (supplier) unit costs
 *   customer_credit_direct        balance left on credit ≤ KES 20,000 (outlet manager)
 *   customer_credit               ≤ KES 200,000 → finance; above → + super admin
 *   tax_rate_change, reporting_fx_change, payment_settlement_change
 *                                 the super admin signs every one (no direct band)
 *   customer_pricing_fx_change    finance signs every one (no direct band)
 *
 * Seeded with the plan's proposed defaults; the owner confirms the figures when
 * approving the merge.
 */
return new class extends Migration
{
    /** event => [[up_to (KES or %) | null, permission], ...] in band order. */
    private const DEFAULTS = [
        'selling_price_change_direct' => [[10, 'products.edit']],
        'selling_price_change'        => [[20, 'approvals.finance_sign'], [null, 'approvals.super_sign']],
        'product_cost_change_direct'  => [[5, 'products.edit_cost']],
        'product_cost_change'         => [[25, 'approvals.finance_sign'], [null, 'approvals.super_sign']],
        'supplier_cost_change_direct' => [[5, 'inventory.adjust']],
        'supplier_cost_change'        => [[25, 'approvals.finance_sign'], [null, 'approvals.super_sign']],
        'customer_credit_direct'      => [[20000, 'orders.set_deposit']],
        'customer_credit'             => [[200000, 'approvals.finance_sign'], [null, 'approvals.super_sign']],
        'tax_rate_change'             => [[null, 'approvals.super_sign']],
        'reporting_fx_change'         => [[null, 'approvals.super_sign']],
        'payment_settlement_change'   => [[null, 'approvals.super_sign']],
        'customer_pricing_fx_change'  => [[null, 'approvals.finance_sign']],
    ];

    public function up(): void
    {
        Schema::create('change_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('event', 64);
            // What is being changed: 'product_price', 'material', 'tax_rate',
            // 'currency', 'payment_method', 'order'.
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_label', 255)->nullable();
            // {field: {old, new}} — only the guarded fields that change.
            $table->json('changeset');
            // How the change measured against the bands, at submission:
            // {direct_basis, band_basis, unit, ...event detail}.
            $table->json('measures')->nullable();
            // When the new value takes effect. NULL = on approval. Never in the
            // past: a change is not retroactive.
            $table->timestamp('effective_from')->nullable();
            // pending | scheduled | applied | rejected | expired | cancelled
            $table->string('status', 20);
            // true = applied at once inside the maker's own band (no signature).
            $table->boolean('direct')->default(false);
            $table->foreignId('maker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'event']);
            $table->index(['maker_id', 'event', 'created_at']);
            $table->index(['status', 'effective_from']);
        });

        // At most one open (waiting or scheduled) change per value.
        DB::statement("CREATE UNIQUE INDEX change_proposals_one_open ON change_proposals (event, subject_type, subject_id) WHERE status IN ('pending', 'scheduled')");

        $now = now();
        foreach (self::DEFAULTS as $event => $bands) {
            foreach ($bands as $i => [$upTo, $permission]) {
                DB::table('approval_thresholds')->insert([
                    'event'               => $event,
                    'band_order'          => $i + 1,
                    'up_to_kes'           => $upTo,
                    'approver_permission' => $permission,
                    'effective_from'      => $now,
                    'created_by'          => null,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // The 3C events' requests first (they point at change_proposals rows),
        // then their thresholds, then the table.
        $events = array_keys(self::DEFAULTS);
        if (Schema::hasTable('approval_requests')) {
            DB::table('approval_requests')->whereIn('event', $events)->delete();
        }
        if (Schema::hasTable('approval_thresholds')) {
            DB::table('approval_thresholds')->whereIn('event', $events)->delete();
        }
        Schema::dropIfExists('change_proposals');
    }
};
