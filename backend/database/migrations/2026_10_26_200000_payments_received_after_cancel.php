<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payments.received_after_cancel_at — money that settled onto an order that
 * was already dead (owner, 2026-10-26: "block payment on cancelled orders").
 *
 * New payments on a dead order are refused at every door, but one already in
 * flight (an M-Pesa push approved before the cancel, a late card webhook, a
 * bank proof approved afterwards) still lands, and must: it is real money.
 * App\Services\Payments\LatePayment records it and stamps this column, so
 * finance can see what to refund.
 *
 * Why a column and not an existing one: approval_status would take the money
 * out of Collected (SettledPayment requires 'approved'), which is false — the
 * money was received. A note in approval_notes cannot be queried. Nothing
 * already on the row says "refund this".
 *
 * Nullable, no default: a metadata-only ALTER on Postgres (no rewrite, no
 * long lock), and every existing row reads "not late". Forward-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payments', 'received_after_cancel_at')) {
            return;
        }
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('received_after_cancel_at')->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('payments', 'received_after_cancel_at')) {
            return;
        }
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('received_after_cancel_at');
        });
    }
};
