<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who does a production stage, by default (owner, 2026-11-26).
 *
 * Some stages always belong to the same hands: Information Desk and Payment
 * to whoever is serving the customer; Material Sourcing, Quality Check and
 * Laundry to one named person each. Until now every order's stages were
 * handed out one by one.
 *
 *   default_assignee_mode   none          — assigned on each order, as before
 *                           order_creator — whoever raised the customer's order
 *                           person        — default_assignee_id
 *
 * Set once on the stage and it stays until changed. It is applied when an
 * order's tasks are created (ProductionOrder::seedTasks); orders already on
 * the floor are not touched. Additive; nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_stages', function (Blueprint $table) {
            $table->string('default_assignee_mode', 20)->default('none');
            $table->foreignId('default_assignee_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_stages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_assignee_id');
            $table->dropColumn('default_assignee_mode');
        });
    }
};
