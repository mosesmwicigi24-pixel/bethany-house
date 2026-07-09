<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authorized dispatch confirmation (serial tracking Phase 3).
 *
 * Before goods leave the shop, a designated authorizer verifies the receipt
 * against the physical product(s) and confirms dispatch. These record who
 * authorized it and when; the sale's serials move sold → dispatched at the same
 * time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'dispatched_at')) {
                $table->timestamp('dispatched_at')->nullable()->after('completed_at');
            }
            if (!Schema::hasColumn('orders', 'dispatched_by')) {
                $table->unsignedBigInteger('dispatched_by')->nullable()->after('dispatched_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['dispatched_at', 'dispatched_by'] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
