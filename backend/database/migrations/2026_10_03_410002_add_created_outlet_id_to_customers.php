<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A: "customers with orders/activity at assigned outlets, or created
 * there" (plan §8). Activity is already on record — orders and production
 * orders carry the customer and the outlet. "Created there" was not: a
 * customer an outlet manager adds from the Customers screen, before any sale,
 * belonged to no outlet and would have vanished from the manager who created
 * them. This column records where a customer was first taken on.
 *
 * Nullable and not backfilled: every existing customer with history is
 * reached through that history; one with none (an import, a head-office
 * entry) stays with the unbounded roles, which is where it lived.
 * Additive only; down() drops the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('created_outlet_id')->nullable()->after('status')
                ->constrained('outlets')->nullOnDelete();
            $table->index('created_outlet_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_outlet_id');
        });
    }
};
