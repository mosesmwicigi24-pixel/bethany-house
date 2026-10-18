<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money paid to a supplier against a purchase order — the payables ledger.
 *
 * A supplier payment is cash out, not an operating expense: the goods it
 * pays for reach profit as cost of sales when they are sold. So it lives
 * here, never in expenses, and never in the P&L.
 *
 * Paid in the PO's currency. amount_kes and rate_to_kes are a snapshot at
 * the reporting rate on the day it was recorded, so a later rate change
 * never rewrites what was paid. A row is never edited or deleted: a mistake
 * is voided (who, when, why) and recorded again.
 *
 * purchase_orders.payment_status (unpaid / partially_paid / paid / overpaid)
 * is kept in step with these rows by SupplierPayables, never typed in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number', 40)->unique();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders');
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->decimal('amount', 14, 2);
            $table->string('currency_code', 3);
            $table->decimal('rate_to_kes', 14, 6)->nullable();
            $table->decimal('amount_kes', 14, 2)->nullable();
            $table->string('method', 30);
            $table->string('reference', 120)->nullable();
            $table->timestamp('paid_at');
            $table->text('notes')->nullable();
            $table->string('client_request_id', 64)->nullable()->unique();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id', 'voided_at']);
            $table->index(['supplier_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
