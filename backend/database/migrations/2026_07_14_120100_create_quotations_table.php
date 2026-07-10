<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotations — the front of the sales-document lifecycle
 * (quotation → invoice → receipt).
 *
 * A quotation is a priced OFFER with no financial or stock commitment. It can be
 * raised by staff (source='admin') or by a customer on the storefront
 * (source='storefront'). On acceptance it is converted into an Order (which is
 * the invoice), recorded in converted_order_id. Money columns mirror the orders
 * table (integer minor units are not used elsewhere in this app; decimal(12,2)
 * is the house convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quote_number', 50)->nullable()->unique(); // assigned at issue
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');   // customer
            $table->foreignId('outlet_id')->nullable()->constrained()->onDelete('set null');
            $table->string('source', 20)->default('admin');           // admin | storefront
            $table->string('status', 30)->default('draft');           // draft|sent|accepted|declined|expired|converted
            $table->string('currency_code', 3)->default('KES');

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);

            // Denormalised customer details (guests / storefront leads).
            $table->string('customer_email', 255)->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->string('customer_first_name', 100)->nullable();
            $table->string('customer_last_name', 100)->nullable();

            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            $table->foreignId('converted_order_id')->nullable()->constrained('orders')->onDelete('set null');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index('status');
            $table->index('user_id');
            $table->index('outlet_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
