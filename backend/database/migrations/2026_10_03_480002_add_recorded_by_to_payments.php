<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role hardening 4D — payments.recorded_by: the user who recorded the payment,
 * on the row itself.
 *
 * Maker≠checker on payment approval (Phase 1B) had to read the audit trail's
 * 'created' entry to learn who recorded a payment, and failed OPEN when that
 * entry was missing (an audit write is allowed to fail without failing the
 * sale). Every creation path now stamps this column — null for the public pay
 * page and gateway webhooks, where no staff member records anything.
 *
 * Nullable, so existing rows and every test fixture stay valid; nullOnDelete
 * like voided_by/approved_by. Existing rows are filled, if the owner approves,
 * by the SEPARATE migration 2026_10_03_480003_backfill_payments_recorded_by_from_audit_trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payments', 'recorded_by')) {
            return;
        }
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('recorded_by')->nullable()->after('status')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('payments', 'recorded_by')) {
            return;
        }
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_by');
        });
    }
};
