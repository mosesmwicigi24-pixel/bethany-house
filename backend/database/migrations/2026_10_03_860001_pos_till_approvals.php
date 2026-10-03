<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role hardening, Phase 4B part 2 — till-side approvals for voids and refunds
 * (bh-tools/phase4_spec.md "4B").
 *
 *   approval_thresholds          two new events on the Phase 3B engine:
 *       pos_void    ≤ 20,000 outlet manager · ≤ 100,000 finance · super admin above
 *       pos_refund  ≤  5,000 outlet manager · ≤  50,000 finance · super admin above
 *     (cumulative, signed in order — a KES 60,000 void needs the outlet
 *     manager AND finance). The outlet manager's band key is
 *     pos.approve_reversal (created by 2026_10_03_860002).
 *
 *   pos_refund_requests          what a clerk asks to refund, before anyone
 *     has signed: the lines, the amount, the method. Nothing moves — no stock,
 *     no cash, no order_returns row — until the last band signs; then a NEW
 *     order_returns row is written (never an edit of the sale). Kept out of
 *     order_returns so a refund that is still only a request is never counted
 *     as a refund by the reports that read that table.
 *
 *   approval_terminal_signatures one row per signature given on the clerk's
 *     terminal with the approver's PIN: the requester, the approver, the
 *     person signed in at the terminal, the session and the address. The
 *     engine's approval_signatures row is the signature itself; this says
 *     where and how it was given.
 */
return new class extends Migration
{
    private const BANDS = [
        'pos_void' => [
            [20000,  'pos.approve_reversal'],
            [100000, 'approvals.finance_sign'],
            [null,   'approvals.super_sign'],
        ],
        'pos_refund' => [
            [5000,  'pos.approve_reversal'],
            [50000, 'approvals.finance_sign'],
            [null,  'approvals.super_sign'],
        ],
    ];

    public function up(): void
    {
        Schema::create('pos_refund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            // pending | approved | rejected | expired | cancelled
            $table->string('status', 20)->default('pending');
            // [{order_item_id, variant_id, product_id, quantity, unit_price, line_refund}]
            $table->json('items');
            $table->decimal('refund_amount', 12, 2);
            $table->string('currency_code', 3)->default('KES');
            $table->string('refund_method', 20);
            $table->string('reason', 500);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            // The refund transaction written when the last band signed.
            $table->foreignId('order_return_id')->nullable()->constrained('order_returns')->nullOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });

        Schema::create('approval_terminal_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained('approval_requests')->cascadeOnDelete();
            $table->foreignId('approval_signature_id')->nullable()->constrained('approval_signatures')->nullOnDelete();
            $table->string('decision', 20);
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('terminal_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('terminal_token_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('approval_request_id');
        });

        $now = now();
        foreach (self::BANDS as $event => $bands) {
            if (DB::table('approval_thresholds')->where('event', $event)->exists()) {
                continue;   // a set is already in force; history is never edited
            }
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
        // Requests raised for these events point at records this migration
        // created; they go with them.
        DB::table('approval_requests')->whereIn('event', array_keys(self::BANDS))->delete();
        DB::table('approval_thresholds')->whereIn('event', array_keys(self::BANDS))->delete();
        Schema::dropIfExists('approval_terminal_signatures');
        Schema::dropIfExists('pos_refund_requests');
    }
};
