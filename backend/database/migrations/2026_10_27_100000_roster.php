<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bethany People, PR 3 — shifts and the weekly roster (owner, 24 Oct 2026).
 *
 * shifts: one row per person per working span at an outlet. user_id null is
 * an OPEN shift anyone at that outlet may claim. A shift is drafted by a
 * manager, then PUBLISHED (the person is told, it appears on their phone);
 * a published shift is cancelled, never deleted, so the record of what was
 * planned stays. Times are Nairobi wall clock; an overnight shift ends on
 * the next day (ends_at < starts_at).
 *
 * shift_swap_requests: a person asks to hand a published shift to a named
 * colleague or to anyone; the manager decides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('label', 60)->nullable();      // "Morning", "Fitting room", "Cutting"
            $table->string('notes', 500)->nullable();
            $table->string('status', 12)->default('draft'); // draft | published | cancelled
            $table->timestamp('published_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['outlet_id', 'date']);
            $table->index(['user_id', 'date']);
            $table->index(['status', 'date']);
        });

        Schema::create('shift_swap_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 300)->nullable();
            $table->string('status', 12)->default('pending'); // pending | approved | declined | withdrawn
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        // One pending request per shift at a time.
        DB::statement("CREATE UNIQUE INDEX shift_swap_one_pending ON shift_swap_requests (shift_id) WHERE status = 'pending'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS shift_swap_one_pending');
        Schema::dropIfExists('shift_swap_requests');
        Schema::dropIfExists('shifts');
    }
};
