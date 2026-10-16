<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner edits of issued sales documents (owner, 2026-10-16), the sales half
 * of document_revisions: a quotation revised after it was issued, an invoice
 * copy refreshed when its order changes, a receipt whose payment details
 * were corrected. The row holds the CURRENT version; every earlier one is in
 * document_revisions. 0 = as first issued. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->unsignedSmallInteger('revision')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('sales_documents', fn (Blueprint $t) => $t->dropColumn('revision'));
    }
};
