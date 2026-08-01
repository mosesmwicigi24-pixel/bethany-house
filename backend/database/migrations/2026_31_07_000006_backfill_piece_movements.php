<?php

use App\Services\Production\LedgerBackfiller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the movement ledger from the counters the floor is running on today.
 *
 * The translation, the clamping and the balance assertion all live in
 * {@see LedgerBackfiller} rather than here, for two reasons: a migration cannot
 * be unit-tested (by the time a test runs, it has already executed against an
 * empty database), and operations needs to be able to re-run this after
 * importing historical orders. The service skips batches that already carry
 * movements, so running it twice is a no-op.
 *
 * A fresh install has nothing to migrate and this does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!DB::table('production_orders')->exists()) {
            return;
        }

        app(LedgerBackfiller::class)->run();
    }

    public function down(): void
    {
        DB::table('piece_movements')->delete();
        DB::table('batch_stage_states')->delete();
        DB::table('production_order_batches')->update(['loaded_qty' => 0]);
    }
};
