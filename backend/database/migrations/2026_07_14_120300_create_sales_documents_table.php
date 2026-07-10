<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The issued-document ledger: one immutable row per QUOTATION / INVOICE / RECEIPT
 * actually issued.
 *
 * The quotation and order stay live (mutable while draft/open); this table
 * freezes what was ISSUED — its gapless number, an immutable JSON snapshot of the
 * lines/customer/totals at issue time, its PDF, and the parent it derives from
 * (invoice → quotation, receipt → invoice). This is the audit trail and the basis
 * for legally durable, non-mutable tax documents. Corrections are made by issuing
 * a new document (e.g. a credit note), never by editing an issued row.
 *
 * `documentable` points at the underlying record: a Quotation (for QUO) or an
 * Order (for INV / RCP). Receipts additionally carry payment_id, since a single
 * invoice can produce several receipts (partial payments).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_documents', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);                       // quotation | invoice | receipt
            $table->string('number', 50)->unique();           // gapless per type

            $table->nullableMorphs('documentable');           // Quotation | Order
            $table->unsignedBigInteger('parent_document_id')->nullable();  // invoice→quote, receipt→invoice
            $table->foreignId('payment_id')->nullable()->constrained('payments')->onDelete('set null');

            $table->timestamp('issued_at')->useCurrent();
            $table->date('valid_until')->nullable();          // quotations
            $table->date('due_date')->nullable();             // invoices
            $table->string('status', 30)->default('issued');  // issued|sent|accepted|paid|void|expired

            $table->decimal('amount', 12, 2)->nullable();     // document total (or receipt amount)
            $table->string('currency_code', 3)->default('KES');
            $table->jsonb('snapshot')->nullable();            // frozen lines + customer + totals
            $table->string('pdf_path', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index('type');
            $table->index('parent_document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_documents');
    }
};
