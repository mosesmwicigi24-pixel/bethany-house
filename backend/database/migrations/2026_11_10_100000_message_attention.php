<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Message accountability (owner, 2026-11-02): every important message must be
 * noticed, and the right person must be able to answer it quickly.
 *
 * Two more per-member pointers beside last_read_message_id, the same
 * high-water-mark shape (a message is covered exactly when the pointer is ≥
 * its id), so nothing is stored per message and no state can drift:
 *
 *   notice_dismissed_message_id   the floating notice was dismissed up to
 *                                 here. Dismissing is NOT reading: the
 *                                 message stays unread and still counts.
 *   acknowledged_message_id       "handled without a reply" up to here. A
 *                                 reply of your own also answers everything
 *                                 before it, without touching this column.
 *
 * Two settings for when a message waiting on you turns amber, then red.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_members', function (Blueprint $table) {
            $table->unsignedBigInteger('notice_dismissed_message_id')->nullable()->after('last_read_message_id');
            $table->unsignedBigInteger('acknowledged_message_id')->nullable()->after('notice_dismissed_message_id');
        });

        foreach ([
            ['messaging.response_warn_minutes', '60', 'Waiting this long for your reply turns a message amber.'],
            ['messaging.response_escalate_minutes', '240', 'Waiting this long for your reply turns a message red.'],
        ] as [$key, $value, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => $value, 'data_type' => 'integer', 'group' => 'messaging',
                'is_public' => false, 'created_at' => now(), 'updated_at' => now(),
            ] + (Schema::hasColumn('settings', 'description') ? ['description' => $description] : []));
        }
    }

    public function down(): void
    {
        Schema::table('channel_members', function (Blueprint $table) {
            $table->dropColumn(['notice_dismissed_message_id', 'acknowledged_message_id']);
        });
        DB::table('settings')->whereIn('key', ['messaging.response_warn_minutes', 'messaging.response_escalate_minutes'])->delete();
    }
};
