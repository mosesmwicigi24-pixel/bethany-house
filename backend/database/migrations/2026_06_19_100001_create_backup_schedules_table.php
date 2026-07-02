<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_schedules', function (Blueprint $table) {
            $table->id();

            $table->boolean('is_enabled')->default(false);

            // 'daily' | 'weekly' | 'monthly'
            $table->string('frequency', 20)->default('daily');

            // Time of day to run, e.g. '02:00:00' (server timezone).
            $table->time('run_at')->default('02:00:00');

            // For weekly: 0 (Sun) - 6 (Sat). For monthly: 1-28 (day of month). Null for daily.
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();

            // How many backups created BY THIS SCHEDULE to retain before pruning the oldest.
            // Null = keep forever (not recommended).
            $table->unsignedInteger('retain_count')->default(14);

            // Storage destination for scheduled runs: 'local' | 's3' (matches `disk` column on database_backups).
            $table->string('disk', 30)->default('local');

            $table->timestamp('last_run_at')->nullable();
            $table->string('last_run_status', 20)->nullable(); // success | failed
            $table->text('last_run_error')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Single-row config table — seed the one default row.
        Schema::table('backup_schedules', function (Blueprint $table) {
            //
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_schedules');
    }
};
