<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A quotation can be VOID (owner, 2026-10-26): when the super admin voids
 * the unpaid invoice a quotation became, the quotation is withdrawn with it
 * — who, when and why are kept on the row (App\Services\Documents\InvoiceVoid).
 *
 * quotations.status is a plain varchar(30) with no check constraint
 * (verified on production 2026-10-26: pg_constraint holds no CHECK on
 * quotations, sales_documents or orders), so 'void' needs no constraint
 * change. Only the three facts are new. Nothing existing is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason']);
        });
    }
};
