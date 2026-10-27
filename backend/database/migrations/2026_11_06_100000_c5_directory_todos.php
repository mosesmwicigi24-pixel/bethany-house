<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bethany People C5 (owner, 27 Oct 2026) — the staff directory and to-dos.
 *
 * Directory: every staff member sees colleagues' name, job, department and
 * outlets; whether their phone, email and birthday show is each person's
 * own choice (employee_profiles.directory_*). Phone and email show by
 * default; the birthday does not.
 *
 * todos: a light "do this by then" for a person, outside production's task
 * flow. A manager (attendance.view_team) gives one to someone in their
 * outlets; anyone writes their own. Done, reopened or cancelled; the giver
 * hears when it is done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $t) {
            $t->boolean('directory_show_phone')->default(true)->after('notes');
            $t->boolean('directory_show_email')->default(true)->after('directory_show_phone');
            $t->boolean('directory_show_birthday')->default(false)->after('directory_show_email');
        });
        Schema::create('todos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();            // who does it
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); // who asked
            $t->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $t->string('title', 140);
            $t->text('details')->nullable();
            $t->timestamp('due_at')->nullable();
            $t->string('status', 10)->default('open');                                   // open | done | cancelled
            $t->timestamp('done_at')->nullable();
            $t->text('done_note')->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'status']);
            $t->index(['created_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todos');
        Schema::table('employee_profiles', fn (Blueprint $t) => $t->dropColumn(['directory_show_phone', 'directory_show_email', 'directory_show_birthday']));
    }
};
