<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds approval-workflow columns to purchase_orders and purchase_returns tables.
 * Runs safely - columns are only added if they don't already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        // purchase_orders: submitted_by, submitted_at
        Schema::table('purchase_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_orders', 'submitted_by')) {
                $table->unsignedBigInteger('submitted_by')->nullable()->after('approved_at');
                $table->foreign('submitted_by')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('purchase_orders', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            }
        });

        // purchase_returns: approved_by, approved_at
        Schema::table('purchase_returns', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_returns', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('created_by');
                $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('purchase_returns', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_orders', 'submitted_by')) {
                $table->dropForeign(['submitted_by']);
                $table->dropColumn(['submitted_by', 'submitted_at']);
            }
        });

        Schema::table('purchase_returns', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_returns', 'approved_by')) {
                $table->dropForeign(['approved_by']);
                $table->dropColumn(['approved_by', 'approved_at']);
            }
        });
    }
};