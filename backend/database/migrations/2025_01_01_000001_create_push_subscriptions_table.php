<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * push_subscriptions
 *
 * PostgreSQL-compatible version.
 * Endpoint stored as string(2000) so a normal unique index works without
 * expression index syntax (which differs between MySQL and PostgreSQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained()
                  ->cascadeOnDelete();

            // Endpoint stored as varchar(2000) - long enough for any push
            // service URL, and lets us put a normal unique index on it.
            $table->string('endpoint', 2000);

            // Web Crypto keys sent by the browser during subscribe()
            $table->text('p256dh');
            $table->text('auth');

            // User-agent hint for debugging
            $table->string('user_agent', 500)->nullable();

            // Set false when push service returns 410 Gone (expired/revoked)
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One row per browser registration
            $table->unique('endpoint');

            // Fast lookup: all active subscriptions for a user
            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};