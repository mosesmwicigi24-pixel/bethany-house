<?php

use App\Services\AbandonedOrderReaper;
use Illuminate\Database\Migrations\Migration;

/**
 * One-time inventory correction.
 *
 * The two-step POS deducts stock at pending-order creation. Earlier cleanups
 * cancelled abandoned pending orders WITHOUT restoring that stock, so the shelf
 * count drifted down. This backfills the stock for those already-cancelled
 * orders, then reaps any still-abandoned ones with restore. Both are idempotent
 * (guarded by the inventory-transaction ledger). Going forward the hourly
 * pos:reap-abandoned-orders schedule keeps this from recurring.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Give back the stock the previous cleanup cancelled without restoring.
        AbandonedOrderReaper::backfillCancelledUnrestored();

        // Reap anything still abandoned, this time restoring stock + serials.
        AbandonedOrderReaper::reap(24);
    }

    public function down(): void
    {
        // One-time correction — not reversible.
    }
};
