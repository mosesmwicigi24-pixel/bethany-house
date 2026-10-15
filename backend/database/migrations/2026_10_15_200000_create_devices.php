<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named sign-in devices (owner, 2026-10-15): "Moses signed in on Moses'
 * laptop", not "Chrome on iOS".
 *
 * A browser cannot tell a website the device's own name or who owns it, so
 * the console keeps a random key in the browser's storage and sends it with
 * every request (X-Device-Id). The hub stores only the key's SHA-256.
 *
 *  devices                  one per browser on one machine: what it is
 *                           (iPhone, iPad, Mac, Windows PC …), its browser,
 *                           and the name a person or an administrator gave
 *                           it. An administrator's name wins and the person
 *                           cannot change it.
 *  device_user              which accounts have completed a sign-in on it
 *                           (a shop till is shared by several clerks).
 *  personal_access_tokens   the session's device, so removing a device ends
 *                           the sessions on it.
 *  login_devices            the new-device alarm's record, linked to the
 *                           device, so removing a device makes its next
 *                           sign-in raise the alarm again.
 *
 * Additive only: existing sessions and alarm records read as "device not
 * known", and the alarm itself (browser + system + network) is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('key_hash', 64)->unique();
            $table->string('kind', 24);                     // iphone, ipad, mac, windows_pc …
            $table->string('browser', 32)->nullable();
            $table->string('name', 60)->nullable();
            $table->string('name_source', 8)->nullable();   // self | admin
            $table->foreignId('named_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('named_at')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
        });

        Schema::create('device_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unique(['device_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
        });

        Schema::table('login_devices', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('login_devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
        });
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
        });
        Schema::dropIfExists('device_user');
        Schema::dropIfExists('devices');
    }
};
