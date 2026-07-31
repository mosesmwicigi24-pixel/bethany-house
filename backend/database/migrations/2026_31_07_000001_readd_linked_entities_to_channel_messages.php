<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Re-adds channel_messages.linked_entities at the correct point in the
 * migration order.
 *
 * The original (2026_01_01_000002_add_linked_entities_to_channel_messages)
 * is dated BEFORE 2026_06_02_065913_create_channels_tables, which creates the
 * table it alters. Its own `if (!Schema::hasTable('channel_messages')) return;`
 * guard then silently no-ops on any database built from scratch, so the column
 * never existed there — production only has it because the migration was added
 * to the codebase after the table already existed. Every fresh environment (CI,
 * a rebuilt VPS, disaster recovery) came up without it, and both sendMessage
 * and editMessage write that column — they 500 the moment a message carries a
 * linked entity.
 *
 * Idempotent, so it is a no-op where the column is already present.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('channel_messages')) {
            return;
        }
        if (Schema::hasColumn('channel_messages', 'linked_entities')) {
            return;
        }

        Schema::table('channel_messages', function (Blueprint $table) {
            $table->jsonb('linked_entities')->nullable()->after('mentions');
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: the original migration owns the column's
        // lifecycle, and dropping here would destroy data on rollback.
    }
};
