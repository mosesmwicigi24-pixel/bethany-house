<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fitting and collection dates for production orders.
 *
 * A tailoring business runs on four dates, and the system only knew two of
 * them: due_date (delivery) and estimated_completion_date (internal). The
 * customer-facing pair — when the customer comes in to be fitted, and when
 * they collect the finished garment — lived in people's heads and on paper.
 * Both are nullable: plenty of orders (stock production) have neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->date('fitting_date')->nullable()->after('due_date');
            $table->date('collection_date')->nullable()->after('fitting_date');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn(['fitting_date', 'collection_date']);
        });
    }
};
