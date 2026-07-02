<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add context columns to channels so a channel can be scoped to a specific
 * entity (e.g. a production order).
 *
 * context_type: e.g. "production_order" | "order"
 * context_id:   the entity's primary key
 *
 * Note: linked_entities already exists on channel_messages from a prior
 * migration and is not added here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->string('context_type', 50)->nullable()->after('slug');
            $table->unsignedBigInteger('context_id')->nullable()->after('context_type');
            $table->index(['context_type', 'context_id'], 'channels_context_index');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropIndex('channels_context_index');
            $table->dropColumn(['context_type', 'context_id']);
        });
    }
};