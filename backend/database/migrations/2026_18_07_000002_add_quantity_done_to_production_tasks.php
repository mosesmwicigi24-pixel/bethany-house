<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Piece-level progress for batch production orders.
 *
 * A 50-piece order had 8 binary stage tasks: Stitching was either pending or
 * done for the WHOLE batch, so "10 finished, 5 at Button, 35 not started" was
 * inexpressible. Each stage task now carries ONE cumulative number —
 * quantity_done, the pieces that have PASSED that stage — and the entire
 * distribution is derived arithmetic, never typed in:
 *
 *   not started   = order.quantity − passed(first stage)
 *   held at stage = passed(previous stage) − passed(this stage)
 *   finished      = passed(last stage)
 *
 * The server enforces the pipeline invariant (you cannot button more shirts
 * than were stitched), which is what keeps the numbers honest: an inflated
 * count immediately collides with the neighbouring stage's.
 *
 * Backfill: a stage that was marked completed under the old binary model has,
 * by definition, passed every piece — so completed tasks inherit their order's
 * full quantity. Without this, in-flight orders would read as 0% the moment
 * the arithmetic went live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_tasks', function (Blueprint $table) {
            $table->unsignedInteger('quantity_done')->default(0)->after('sequence');
        });

        DB::statement("
            UPDATE production_tasks t
            SET quantity_done = o.quantity
            FROM production_orders o
            WHERE o.id = t.production_order_id AND t.status = 'completed'
        ");
    }

    public function down(): void
    {
        Schema::table('production_tasks', function (Blueprint $table) {
            $table->dropColumn('quantity_done');
        });
    }
};
