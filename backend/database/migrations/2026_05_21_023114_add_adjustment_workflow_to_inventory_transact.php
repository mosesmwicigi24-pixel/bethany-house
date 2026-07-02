<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            // Already exists check before adding
            if (!Schema::hasColumn('inventory_transactions', 'reason_code')) {
                $table->string('reason_code')->nullable()->after('transaction_type');
            }
            if (!Schema::hasColumn('inventory_transactions', 'reference_number')) {
                $table->string('reference_number')->nullable()->after('reason_code');
            }
            if (!Schema::hasColumn('inventory_transactions', 'status')) {
                // pending_approval | approved | rejected
                $table->string('status', 30)->default('approved')->after('reference_number');
            }
            if (!Schema::hasColumn('inventory_transactions', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('created_by');
            }
            if (!Schema::hasColumn('inventory_transactions', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
            if (!Schema::hasColumn('inventory_transactions', 'approval_notes')) {
                $table->text('approval_notes')->nullable()->after('approved_at');
            }
        });

        // Add index on status for fast pending-approval queries
        Schema::table('inventory_transactions', function (Blueprint $table) {
            try {
                $table->index('status', 'idx_inv_tx_status');
            } catch (\Exception) {
                // index may already exist
            }
        });

        // Backfill existing rows that have no status - treat as approved
        DB::statement("UPDATE inventory_transactions SET status = 'approved' WHERE status IS NULL OR status = ''");
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropIndexIfExists('idx_inv_tx_status');
            $table->dropColumn(array_filter([
                Schema::hasColumn('inventory_transactions', 'reason_code')     ? 'reason_code'     : null,
                Schema::hasColumn('inventory_transactions', 'reference_number')? 'reference_number': null,
                Schema::hasColumn('inventory_transactions', 'status')          ? 'status'          : null,
                Schema::hasColumn('inventory_transactions', 'approved_by')     ? 'approved_by'     : null,
                Schema::hasColumn('inventory_transactions', 'approved_at')     ? 'approved_at'     : null,
                Schema::hasColumn('inventory_transactions', 'approval_notes')  ? 'approval_notes'  : null,
            ]));
        });
    }
};