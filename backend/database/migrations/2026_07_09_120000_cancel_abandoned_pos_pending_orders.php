<?php

use App\Support\CancelAbandonedPosPendingOrders;
use Illuminate\Database\Migrations\Migration;

/**
 * One-time cleanup of abandoned POS pending orders (e.g. the stale #54 that kept
 * being offered for auto-resume). Cancels POS orders that are still pending,
 * unpaid, older than 24h and have no money attached. Idempotent, forward-only.
 * See App\Support\CancelAbandonedPosPendingOrders.
 */
return new class extends Migration
{
    public function up(): void
    {
        CancelAbandonedPosPendingOrders::run(24);
    }

    public function down(): void
    {
        // One-time correction — cancelled orders are not restored on rollback.
    }
};
