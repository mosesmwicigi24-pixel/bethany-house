<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 - Notifications & Permissions
 *
 * 1. notifications table  - stores in-app bell notifications per user
 * 2. permissions seeder   - run via: php artisan permission:sync (see SyncPermissions command)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Recipient
            $table->unsignedBigInteger('notifiable_id');
            $table->string('notifiable_type');          // App\Models\User
            $table->index(['notifiable_type', 'notifiable_id']);

            // Payload
            $table->string('type');                     // e.g. App\Notifications\ProductionOrderConfirmed
            $table->string('title', 255);               // Short summary shown in bell dropdown
            $table->text('body')->nullable();            // Full message
            $table->string('action_url', 500)->nullable(); // Deep-link into the admin
            $table->string('icon', 50)->nullable();     // e.g. 'production', 'payment', 'shipment'
            $table->jsonb('data')->nullable();           // Arbitrary extra payload

            // State
            $table->timestamp('read_at')->nullable();

            $table->timestamps();
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};