<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily message-volume snapshots per (phone, channel), derived by
 * channels:sync-touchpoints from Neema's CUMULATIVE rollup.
 *
 * WHY: channel_touchpoints stores running totals that are overwritten nightly,
 * so "how many messages did we exchange THIS month" was unanswerable — the
 * cumulative number silently mixes a year of history into any period. Each
 * sync writes the day's DELTA here (incoming total minus the previous total,
 * clamped to the incoming value when the upstream counter resets), which makes
 * message volume period-sliceable from the day this table deploys onward.
 * History before deploy day is not reconstructible — the report labels the
 * cumulative numbers "all-time" for exactly that reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_touchpoint_daily', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('channel', 20);               // whatsapp | messenger | instagram | facebook
            $table->string('phone', 32);                 // canonical E.164 digits, no '+'
            $table->unsignedInteger('messages')->default(0);
            $table->unsignedInteger('inbound')->default(0);
            $table->timestamps();

            $table->unique(['date', 'channel', 'phone']); // upsert target (accumulates on re-run)
            $table->index(['date', 'channel']);           // the report's read path
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_touchpoint_daily');
    }
};
