<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-channel engagement, one row per (phone, channel). Populated nightly by
 * channels:sync-touchpoints, which pulls Neema's per-person × per-channel message
 * rollup (WhatsApp/Messenger/Instagram) and matches each phone to a hub customer.
 * Web engagement stays in site_visits (anonymous); this covers the messaging
 * channels. Keyed by phone so a touchpoint survives even before a customer record
 * exists; customer_id is filled in when a match is found.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_touchpoints', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 32);                 // canonical E.164 digits, no '+'
            $table->string('channel', 20);               // whatsapp | messenger | instagram | facebook
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->unsignedInteger('messages')->default(0);
            $table->unsignedInteger('inbound')->default(0);
            $table->timestamp('first_seen')->nullable();
            $table->timestamp('last_seen')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['phone', 'channel']);        // upsert target
            $table->index('customer_id');
            $table->index('channel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_touchpoints');
    }
};
