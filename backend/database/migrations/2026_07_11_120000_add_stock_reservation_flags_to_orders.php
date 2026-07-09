<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POS stock reservation model.
 *
 * Moving from "deduct quantity_on_hand at order creation" to "reserve at
 * creation, commit the deduction at payment" keeps the physical count accurate
 * even while a sale is open. These three timestamps make every inventory
 * transition idempotent and, crucially, let the new code recognise orders
 * created under the OLD model so it never double-deducts or mis-restores them:
 *   - stock_reserved_at   set when a pending order reserves stock
 *   - stock_committed_at  set when the reservation is committed (goods leave)
 *   - stock_unwound_at    set when reserved/committed stock is returned (void/cancel)
 *
 * Backfill: every existing order already had its on_hand deducted (old model),
 * so mark it committed; terminal orders already had that restored, so mark them
 * unwound too. New orders (created after this deploy) use reserve → commit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['stock_reserved_at', 'stock_committed_at', 'stock_unwound_at'] as $col) {
                if (!Schema::hasColumn('orders', $col)) {
                    $table->timestamp($col)->nullable();
                }
            }
        });

        // Existing orders: on_hand was deducted at creation under the old model →
        // treat as already committed so the new pay path never re-deducts them.
        DB::table('orders')
            ->whereNull('stock_committed_at')
            ->update(['stock_committed_at' => DB::raw('COALESCE(completed_at, updated_at)')]);

        // Terminal orders already had that stock returned → mark unwound so the
        // new void/cancel path never restores it a second time.
        DB::table('orders')
            ->whereIn('status', ['cancelled', 'voided', 'refunded'])
            ->whereNull('stock_unwound_at')
            ->update(['stock_unwound_at' => DB::raw('COALESCE(cancelled_at, updated_at)')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['stock_reserved_at', 'stock_committed_at', 'stock_unwound_at'] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
