<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Download approval (owner decision 2026-09-21): every file that leaves the hub
 * needs the owner's approval — or a manager he delegates to — except invoices,
 * quotations and receipts. See App\Http\Middleware\DownloadGate.
 *
 * download_requests is one row per attempt to take a file: asked for, decided,
 * downloaded. It is mutable (status moves pending → approved → downloaded); the
 * append-only record of each step is in activity_log, which DownloadGate and
 * DownloadRequestController write on every transition.
 *
 * Timestamps are plain `timestamp`, hub-local, like every other table — see
 * 2026_09_21_000003 for what timestamptz did to request_logs.
 *
 * download_approvers is the delegation list. It is not a Spatie permission on
 * purpose: Gate::before gives super_admin every permission, so "can approve
 * downloads" as a permission would silently include every super admin. Only
 * the owner account (config audit.owner_account_email) may change this list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('download_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users');

            // Exactly what is being taken — replayed verbatim when the approved
            // download runs, so what was approved is what is downloaded.
            $table->string('method', 10);
            $table->string('path', 500);
            $table->json('payload')->nullable();           // query (GET) or body (POST), token excluded
            $table->string('route_action', 255)->nullable();
            $table->string('label', 255);
            $table->string('category', 20);                // gated | never_attach | exempt
            $table->text('reason')->nullable();

            // pending → approved → downloaded; or denied / expired / cancelled;
            // auto = the owner's own download, approved by being the owner.
            $table->string('status', 20)->index();
            $table->boolean('auto_approved')->default(false);
            $table->boolean('shadow')->default(false);     // recorded while the gate was not enforcing
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            // Single-use download token, stored hashed.
            $table->char('token_hash', 64)->nullable()->index();
            $table->timestamp('token_expires_at')->nullable();

            // What actually left.
            $table->timestamp('downloaded_at')->nullable();
            $table->string('download_ip', 45)->nullable();
            $table->string('download_user_agent', 500)->nullable();
            $table->string('export_id', 32)->nullable()->unique();
            $table->string('file_name', 255)->nullable();
            $table->string('content_type', 120)->nullable();
            $table->unsignedBigInteger('file_bytes')->nullable();
            $table->char('file_sha256', 64)->nullable()->index();
            $table->string('archive_path', 500)->nullable();
            $table->timestamp('owner_notified_at')->nullable();

            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('download_approvers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_approvers');
        Schema::dropIfExists('download_requests');
    }
};
