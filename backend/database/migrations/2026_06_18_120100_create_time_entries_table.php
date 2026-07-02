<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();

            // ── Clock in ────────────────────────────────────────────────────
            $table->timestamp('clock_in_at');
            $table->decimal('clock_in_latitude', 10, 7);
            $table->decimal('clock_in_longitude', 10, 7);
            $table->decimal('clock_in_distance_meters', 8, 2)->nullable();
            $table->enum('clock_in_method', ['gps', 'override'])->default('gps');

            // ── Clock out ───────────────────────────────────────────────────
            $table->timestamp('clock_out_at')->nullable();
            $table->decimal('clock_out_latitude', 10, 7)->nullable();
            $table->decimal('clock_out_longitude', 10, 7)->nullable();
            $table->decimal('clock_out_distance_meters', 8, 2)->nullable();

            // ── Breaks: [{ "started_at": "...", "ended_at": "..." }, ...] ─────
            $table->json('breaks')->nullable();
            $table->unsignedInteger('total_break_minutes')->nullable();
            $table->unsignedInteger('worked_minutes')->nullable();

            // ── Status / audit ──────────────────────────────────────────────
            $table->enum('status', ['active', 'completed', 'flagged'])->default('active');
            $table->string('flagged_reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('device_info')->nullable();

            // Set when a manager force-allows a clock-in outside the geofence,
            // or corrects an entry after the fact.
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'clock_in_at']);
            $table->index(['outlet_id', 'clock_in_at']);
            $table->index('status');
        });

        // Postgres partial unique index - the real safeguard against double
        // clock-ins (e.g. a slow connection causing a double-tap). A user can
        // only ever have one row with status='active' at a time; the DB
        // rejects a second insert rather than relying solely on app logic.
        DB::statement(
            "CREATE UNIQUE INDEX time_entries_one_active_per_user
             ON time_entries (user_id)
             WHERE status = 'active'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS time_entries_one_active_per_user');
        Schema::dropIfExists('time_entries');
    }
};
