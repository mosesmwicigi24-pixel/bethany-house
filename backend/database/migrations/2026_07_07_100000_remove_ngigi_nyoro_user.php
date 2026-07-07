<?php

use App\Support\UserPurger;
use Illuminate\Database\Migrations\Migration;

/**
 * Remove the Ngigi Nyoro account (nyorojnr@gmail.com) completely.
 *
 * It was seeded as the system super_admin (SuperAdminSeeder), which meant it
 * received every role-based notification — including the payment notifications.
 * This purges the account and its entire notification/auth footprint from the
 * live database on deploy. The seeder, web-push VAPID contact and compose
 * defaults are changed in the same change so no redeploy or re-seed can ever
 * recreate it.
 *
 * Forward-only and idempotent: a no-op once the account is gone or on a fresh
 * install where it was never seeded.
 */
return new class extends Migration
{
    public function up(): void
    {
        UserPurger::byEmail('nyorojnr@gmail.com');
    }

    public function down(): void
    {
        // Intentionally irreversible — the account is deliberately removed and
        // must not be recreated by rolling back.
    }
};
