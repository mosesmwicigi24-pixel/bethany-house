<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 - Customer Management: Optional Email & Password
 *
 * Changes:
 *  1. users.email        → nullable (walk-in customers with no portal account)
 *  2. users.password     → nullable
 *  3. users.is_portal_user → new boolean flag
 *  4. customers.user_id  → nullable (phone-only customers have no User record)
 *
 * Safe to run on existing data:
 *  - Existing users all have emails, so setting nullable is non-destructive.
 *  - Existing customers all have user_id set, so nullability doesn't break anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Make users.email nullable ──────────────────────────────────
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();

            // New flag - true when the user has intentional portal credentials
            $table->boolean('is_portal_user')->default(false)->after('password');
        });

        // Backfill: all existing users have credentials, so mark them as portal users
        DB::table('users')->whereNotNull('email')->update(['is_portal_user' => true]);

        // ── 2. Make customers.user_id nullable ────────────────────────────
        Schema::table('customers', function (Blueprint $table) {
            // Drop the FK constraint first so we can alter the column
            // (constraint name may vary - use try/catch for safety)
            try {
                $table->dropForeign(['user_id']);
            } catch (\Exception $e) {
                // Constraint might not exist or have a different name - skip
            }

            $table->unsignedBigInteger('user_id')->nullable()->change();

            // Re-add FK with nullOnDelete so deleting a User doesn't orphan the Customer
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_portal_user');
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });

        Schema::table('customers', function (Blueprint $table) {
            try {
                $table->dropForeign(['user_id']);
            } catch (\Exception $e) {}

            $table->unsignedBigInteger('user_id')->nullable(false)->change();

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->cascadeOnDelete();
        });
    }
};