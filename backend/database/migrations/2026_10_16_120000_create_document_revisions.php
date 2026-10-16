<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner edits of issued documents (owner, 2026-10-16): "the super admin
 * should be able to edit the purchase order, the receipt, the invoice …"
 * Decided: every edit is a REVISION — the document as it was is kept,
 * read-only, with who, when and why; the document shows "Rev N".
 *
 *  document_revisions      one row per edit: a full snapshot of the document
 *                          BEFORE the edit. Never changed or deleted (the
 *                          model refuses, like order_corrections). Polymorphic
 *                          on purpose: purchase orders and goods receipts now,
 *                          sales documents next.
 *  purchase_orders         revision (0 = as first issued) and the supplier's
 *                          own invoice: number, date and amount. The PO update
 *                          endpoint always accepted an invoice number and
 *                          dropped it — there was no column.
 *  goods_received_notes    revision.
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 40);           // purchase_order | goods_receipt
            $table->unsignedBigInteger('document_id');
            $table->unsignedSmallInteger('revision');        // the revision this snapshot WAS
            $table->json('snapshot');
            $table->json('changes')->nullable();             // a short list of what the edit changed
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['document_type', 'document_id', 'revision']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('revision')->default(0);
            $table->string('supplier_invoice_number', 100)->nullable();
            $table->date('supplier_invoice_date')->nullable();
            $table->decimal('supplier_invoice_amount', 14, 2)->nullable();
        });

        Schema::table('goods_received_notes', function (Blueprint $table) {
            $table->unsignedSmallInteger('revision')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('goods_received_notes', fn (Blueprint $t) => $t->dropColumn('revision'));
        Schema::table('purchase_orders', fn (Blueprint $t) => $t->dropColumn([
            'revision', 'supplier_invoice_number', 'supplier_invoice_date', 'supplier_invoice_amount',
        ]));
        Schema::dropIfExists('document_revisions');
    }
};
