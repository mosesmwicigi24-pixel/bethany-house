<?php

use App\Support\SuperAdminPromoter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Make mwicigi@icloud.com the super admin.
 *
 * Runs right after the Ngigi Nyoro account is purged (2026_07_07_100000), so
 * the system always has an owner super_admin. Idempotent: promotes the account
 * if it exists, otherwise creates it (owner sets their password via "forgot
 * password"). See App\Support\SuperAdminPromoter.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        SuperAdminPromoter::ensure('mwicigi@icloud.com');
    }

    public function down(): void
    {
        // Intentionally irreversible — do not strip the owner's super_admin role
        // on rollback.
    }
};
