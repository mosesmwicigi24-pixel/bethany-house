<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Delete" a payment, softly (owner, 2026-11-05 — #1158: a Mukuru payment
 * that never arrived). The payment is voided (it counts for nothing,
 * anywhere) and marked removed so the order page hides it; the row, the
 * reason and who did it stay for the audit trail and the integrity seals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->timestamp('removed_at')->nullable()->after('voided_by');
            $t->foreignId('removed_by')->nullable()->after('removed_at')->constrained('users')->nullOnDelete();
            $t->string('removed_reason', 500)->nullable()->after('removed_by');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('removed_by');
            $t->dropColumn(['removed_at', 'removed_reason']);
        });
    }
};
