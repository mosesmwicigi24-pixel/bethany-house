<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Serial tracking Phase 4 (aging + reconciliation).
 *
 * `stocked_at` records when a unit most recently landed on the shelf, so we can
 * flag stock that has sat unsold too long. Existing in-stock serials are
 * backfilled from their last-updated time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('product_serials', 'stocked_at')) {
            Schema::table('product_serials', function (Blueprint $table) {
                $table->timestamp('stocked_at')->nullable()->after('status')->index();
            });
        }

        // Backfill: units currently on the shelf have been there since their last
        // status change (best available proxy).
        DB::table('product_serials')
            ->where('status', 'in_stock')
            ->whereNull('stocked_at')
            ->update(['stocked_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('product_serials', 'stocked_at')) {
            Schema::table('product_serials', function (Blueprint $table) {
                $table->dropColumn('stocked_at');
            });
        }
    }
};
