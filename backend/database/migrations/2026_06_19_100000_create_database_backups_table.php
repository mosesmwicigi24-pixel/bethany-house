<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_backups', function (Blueprint $table) {
            $table->id();

            // 'manual' | 'scheduled' | 'pre_clear' | 'pre_wipe'  -- pre_clear/pre_wipe are
            // automatic safety snapshots taken right before a destructive operation.
            $table->string('type', 20)->default('manual');

            // 'pending' | 'running' | 'success' | 'failed'
            $table->string('status', 20)->default('pending');

            $table->string('filename');
            $table->string('disk', 30)->default('local');      // storage disk used: local, s3, etc.
            $table->string('path', 500);                        // path within the disk
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum_sha256', 64)->nullable();

            $table->string('app_version', 50)->nullable();      // git sha / version tag at backup time
            $table->string('db_driver', 20)->nullable();         // pgsql, mysql
            $table->string('triggered_by', 20)->default('user'); // 'user' | 'schedule' | 'system'
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            // When this backup should be auto-purged per the retention policy. Null = keep forever.
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_backups');
    }
};
