<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product production stages + gated stage progression.
 *
 * Until now every confirmed production order was seeded with EVERY active stage —
 * a keyholder gets the same ten stages as an embroidered chasuble, and the floor
 * board fills with stages that will never run. And any stage could be started at
 * any time, in any order, by whoever it was assigned to.
 *
 * 1. products.production_stage_ids — ordered JSON array of production_stages ids,
 *    the same shape as products.measurements. NULL (or empty) means "all active
 *    stages", which is exactly today's behaviour, so no existing product changes
 *    until someone edits its template.
 *
 * 2. production_tasks.sequence — the order's stage sequence, SNAPSHOT at seeding.
 *    Gating must not read the live production_stages.sort_order: an admin
 *    re-ordering stages in Setup would silently re-gate every in-flight order on
 *    the floor. The rules an order was confirmed under are the rules it keeps.
 *    Backfilled from the current sort_order so gating works for in-flight orders.
 *
 * 3. production_tasks.concurrent_allowed / unlocked_by / unlocked_at — the
 *    manager's escape hatch. Sequential is the default (one tailor, one shirt:
 *    cutting → stitching → …), but embroidery can run beside stitching on
 *    separate pieces when the production manager explicitly unlocks it. The
 *    unlock is recorded — who, and when — because an untracked bypass is how
 *    "the gate exists" quietly becomes "the gate is decorative".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('production_stage_ids')->nullable()->after('measurements');
        });

        Schema::table('production_tasks', function (Blueprint $table) {
            $table->unsignedInteger('sequence')->nullable()->after('production_stage_id');
            $table->boolean('concurrent_allowed')->default(false)->after('status');
            $table->unsignedBigInteger('unlocked_by')->nullable()->after('concurrent_allowed');
            $table->timestamp('unlocked_at')->nullable()->after('unlocked_by');
            $table->foreign('unlocked_by')->references('id')->on('users')->nullOnDelete();
        });

        // In-flight orders predate the snapshot — give them one from the stage
        // order they were seeded under, so gating holds for them too.
        DB::statement('
            UPDATE production_tasks t
            SET sequence = s.sort_order
            FROM production_stages s
            WHERE s.id = t.production_stage_id AND t.sequence IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('production_tasks', function (Blueprint $table) {
            $table->dropForeign(['unlocked_by']);
            $table->dropColumn(['sequence', 'concurrent_allowed', 'unlocked_by', 'unlocked_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('production_stage_ids');
        });
    }
};
