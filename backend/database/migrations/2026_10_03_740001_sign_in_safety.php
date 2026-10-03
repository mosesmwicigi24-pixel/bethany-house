<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4C — sign-in safety (role hardening plan §12.3–12.4).
 *
 *  personal_access_tokens   the session: when the person last did something
 *                           (idle limit), whether it is PIN-locked, when it
 *                           last passed step-up, and where it was signed in.
 *  users                    account lock state (lockout after failed sign-ins).
 *  login_failures           each failed staff sign-in, for the per-account count.
 *  login_devices            devices an account has signed in from (suspicious
 *                           sign-in = a device not seen before).
 *  terminal_pins            the clerk's terminal PIN, hashed. A table of its own
 *                           so the PIN hash never passes through the users-row
 *                           audit observer.
 *
 * Additive only: every new column is nullable, existing tokens and accounts
 * read as "never active / not locked / no PIN".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('stepped_up_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('locked_until')->nullable();   // temporary lock (5 failures)
            $table->timestamp('locked_at')->nullable();      // held until unlocked (10 in 24 h)
            $table->string('lock_reason', 64)->nullable();
            $table->timestamp('unlocked_at')->nullable();    // restarts the failure count
        });

        Schema::create('login_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 40);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->useCurrent();
            // Set by a successful sign-in or an unlock: the count starts again.
            $table->timestamp('cleared_at')->nullable();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('login_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->string('agent_family', 64)->nullable();
            $table->string('network', 64)->nullable();       // IPv4 /24 or IPv6 /48
            $table->string('country', 2)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unique(['user_id', 'fingerprint']);
        });

        Schema::create('terminal_pins', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->string('pin_hash');
            $table->timestamp('set_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_pins');
        Schema::dropIfExists('login_devices');
        Schema::dropIfExists('login_failures');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['locked_until', 'locked_at', 'lock_reason', 'unlocked_at']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['last_active_at', 'locked_at', 'stepped_up_at', 'ip_address', 'user_agent']);
        });
    }
};
