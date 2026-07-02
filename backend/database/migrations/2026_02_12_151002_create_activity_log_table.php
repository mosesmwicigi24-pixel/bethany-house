<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateActivityLogTable extends Migration
{
    public function up(): void
    {
        $connection = config('activitylog.database_connection');
        $tableName  = config('activitylog.table_name', 'activity_log');

        if (Schema::connection($connection)->hasTable($tableName)) {
            return;
        }

        Schema::connection($connection)->create($tableName, function (Blueprint $table) {
            // ── Primary key ───────────────────────────────────────────────────
            $table->bigIncrements('id');

            // ── Spatie-compatible columns ─────────────────────────────────────
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');  // subject_type, subject_id
            $table->nullableMorphs('causer',  'causer');   // causer_type,  causer_id
            $table->json('properties')->nullable();

            // ── Extended columns used by ActivityLogService & AuditLogController
            $table->string('event')->nullable()->index();       // e.g. 'created', 'updated', 'login'
            $table->string('action')->nullable()->index();      // legacy alias of event (AuditLogController)
            $table->unsignedBigInteger('user_id')->nullable();  // direct FK used by ProfileController
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // ── Timestamps ────────────────────────────────────────────────────
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $connection = config('activitylog.database_connection');
        $tableName  = config('activitylog.table_name', 'activity_log');

        Schema::connection($connection)->dropIfExists($tableName);
    }
}