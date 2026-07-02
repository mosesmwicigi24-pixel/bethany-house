<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fully idempotent — skips if table missing or column already exists
        if (!Schema::hasTable('channel_messages')) return;
        if (Schema::hasColumn('channel_messages', 'linked_entities')) return;

        Schema::table('channel_messages', function (Blueprint $table) {
            $table->jsonb('linked_entities')->nullable()->after('mentions');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('channel_messages')) return;
        if (!Schema::hasColumn('channel_messages', 'linked_entities')) return;

        Schema::table('channel_messages', function (Blueprint $table) {
            $table->dropColumn('linked_entities');
        });
    }
};