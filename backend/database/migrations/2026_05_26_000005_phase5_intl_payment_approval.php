<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 - International Order Payment Approval
 *
 * Adds to the `payments` table:
 *   - proof_of_payment_path    stored file path
 *   - proof_uploaded_at        timestamp
 *   - requires_approval        whether this payment needs admin sign-off
 *   - approval_status          pending_review | approved | rejected
 *   - approved_by              FK → users
 *   - approved_at
 *   - approval_notes           admin notes on approval / rejection
 *
 * Receipt generation is blocked until all requires_approval payments on an
 * order have approval_status = 'approved'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Proof of payment
            $table->string('proof_of_payment_path', 500)->nullable()->after('tax_amount_collected');
            $table->timestamp('proof_uploaded_at')->nullable()->after('proof_of_payment_path');

            // Approval gate
            $table->boolean('requires_approval')->default(false)->after('proof_uploaded_at');
            $table->string('approval_status', 30)->nullable()->after('requires_approval');
            // Values: pending_review | approved | rejected

            $table->unsignedBigInteger('approved_by')->nullable()->after('approval_status');
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();

            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('approval_notes')->nullable()->after('approved_at');
        });

        // Index for the approvals query (admin inbox)
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['requires_approval', 'approval_status'], 'payments_approval_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_approval_idx');
            $table->dropForeign(['approved_by']);
            $table->dropColumn([
                'proof_of_payment_path',
                'proof_uploaded_at',
                'requires_approval',
                'approval_status',
                'approved_by',
                'approved_at',
                'approval_notes',
            ]);
        });
    }
};