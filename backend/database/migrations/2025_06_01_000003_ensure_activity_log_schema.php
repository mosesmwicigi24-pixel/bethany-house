<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ensures the activity_log table has all columns needed by both:
 *  - The existing AuditLogController (causer_id, action, description, ip_address)
 *  - Spatie's activitylog package (log_name, subject_type, subject_id, causer_type,
 *    causer_id, description, properties, event, batch_uuid)
 *
 * Run BEFORE installing spatie/laravel-activitylog so the table already exists
 * and the vendor migration becomes a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Create table if it doesn't exist (first install)
        if (!Schema::hasTable('activity_log')) {
            Schema::create('activity_log', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('log_name')->nullable()->index();
                $table->text('description');
                $table->nullableMorphs('subject', 'subject');     // subject_type + subject_id
                $table->string('event')->nullable();
                $table->nullableMorphs('causer', 'causer');       // causer_type + causer_id
                $table->jsonb('properties')->nullable();
                $table->uuid('batch_uuid')->nullable()->index();
                // Legacy columns used by the original AuditLogController
                $table->string('action')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('activity_log', function (Blueprint $table) {
                // Add Spatie columns that may be missing
                if (!Schema::hasColumn('activity_log', 'log_name')) {
                    $table->string('log_name')->nullable()->index()->after('id');
                }
                if (!Schema::hasColumn('activity_log', 'subject_type')) {
                    $table->string('subject_type')->nullable()->after('description');
                    $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
                }
                if (!Schema::hasColumn('activity_log', 'event')) {
                    $table->string('event')->nullable()->after('subject_id');
                }
                if (!Schema::hasColumn('activity_log', 'causer_type')) {
                    $table->string('causer_type')->nullable();
                }
                if (!Schema::hasColumn('activity_log', 'properties')) {
                    $table->jsonb('properties')->nullable();
                }
                if (!Schema::hasColumn('activity_log', 'batch_uuid')) {
                    $table->uuid('batch_uuid')->nullable()->index();
                }
                // Legacy columns
                if (!Schema::hasColumn('activity_log', 'action')) {
                    $table->string('action')->nullable()->index();
                }
                if (!Schema::hasColumn('activity_log', 'ip_address')) {
                    $table->string('ip_address', 45)->nullable();
                }
                if (!Schema::hasColumn('activity_log', 'user_agent')) {
                    $table->text('user_agent')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // We don't drop the table on rollback - logs are precious
        // Just remove the columns we added
        if (Schema::hasTable('activity_log')) {
            Schema::table('activity_log', function (Blueprint $table) {
                $table->dropColumnIfExists('log_name');
                $table->dropColumnIfExists('event');
                $table->dropColumnIfExists('batch_uuid');
            });
        }
    }
};