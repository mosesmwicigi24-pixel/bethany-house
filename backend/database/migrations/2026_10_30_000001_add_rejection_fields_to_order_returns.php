<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the rejection trail to order_returns: rejected_at / rejected_by /
 * rejection_reason, mirroring approved_at / approved_by.
 *
 * ReturnController::reject (and updateStatus → 'rejected') always wrote these
 * three columns, but the table was created without them, so every rejection
 * 500'd with SQLSTATE 42703 before anything committed. Production had 0
 * order_returns rows when this was added — nothing to backfill.
 *
 * Idempotent, so it is a no-op where a column is already present.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_returns', function (Blueprint $table) {
            if (!Schema::hasColumn('order_returns', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('approved_at');
            }
            if (!Schema::hasColumn('order_returns', 'rejected_by')) {
                $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('order_returns', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_returns', function (Blueprint $table) {
            if (Schema::hasColumn('order_returns', 'rejected_by')) {
                $table->dropConstrainedForeignId('rejected_by');
            }
            foreach (['rejected_at', 'rejection_reason'] as $column) {
                if (Schema::hasColumn('order_returns', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
