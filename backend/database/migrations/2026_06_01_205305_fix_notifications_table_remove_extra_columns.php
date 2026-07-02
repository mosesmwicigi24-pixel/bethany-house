<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove extra columns from the notifications table that conflict with
     * Laravel's standard database notification schema.
     *
     * Laravel's Notifiable trait writes all payload fields (title, body,
     * action_url, icon) into the JSON `data` column. It never writes to
     * dedicated scalar columns - so if those columns exist with NOT NULL
     * constraints, every notification insert fails with:
     *
     *   SQLSTATE[23502]: Not null violation: null value in column "title"
     *
     * The NotificationController already reads title/body/etc. by parsing
     * the `data` JSON column, so removing these columns has no effect on
     * the API or frontend.
     *
     * Run with: php artisan migrate
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            // Drop only the columns that exist - guard against re-running
            $columns = ['title', 'body', 'action_url', 'icon'];

            $existing = array_filter(
                $columns,
                fn ($col) => Schema::hasColumn('notifications', $col)
            );

            if (!empty($existing)) {
                $table->dropColumn($existing);
            }
        });
    }

    /**
     * Restore the extra columns (nullable so existing rows are unaffected).
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('notifications', 'title')) {
                $table->string('title')->nullable();
            }
            if (!Schema::hasColumn('notifications', 'body')) {
                $table->text('body')->nullable();
            }
            if (!Schema::hasColumn('notifications', 'action_url')) {
                $table->string('action_url')->nullable();
            }
            if (!Schema::hasColumn('notifications', 'icon')) {
                $table->string('icon')->nullable();
            }
        });
    }
};