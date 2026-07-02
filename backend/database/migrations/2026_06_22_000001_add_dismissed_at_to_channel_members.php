<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a user dismiss an order/production-order thread from their own
 * sidebar without affecting anyone else, and without archiving the channel
 * itself (which deletes it for every member - see
 * ChannelController::destroy(), an admin-only hard-archive of the whole
 * Space). This is a soft, per-user, auto-reversing preference.
 *
 * dismissed_at lives on the channel_members PIVOT table (not on channels,
 * and not on a separate table) because the dismissal is inherently scoped
 * to one user's relationship to one channel - exactly what a pivot row
 * already represents, same as the existing last_read_message_id and
 * muted_until columns there.
 *
 * Comparing dismissed_at against the channel's last_activity_at at query
 * time (see ChannelController::index()) is what makes the dismissal
 * automatically clear itself once a new message arrives - no write is
 * needed on the send side, so sendMessage() stays completely unaware of
 * this per-user UI preference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_members', function (Blueprint $table) {
            $table->timestamp('dismissed_at')->nullable()->after('muted_until');
        });
    }

    public function down(): void
    {
        Schema::table('channel_members', function (Blueprint $table) {
            $table->dropColumn('dismissed_at');
        });
    }
};