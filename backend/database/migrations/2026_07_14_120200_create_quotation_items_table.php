<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quotation line items. Mirrors order_items so a quotation converts cleanly into
 * an order at invoice time. product_id is nullable to allow non-catalogue / ad-hoc
 * lines on a quote (a description + price with no stock item behind it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->nullable()->constrained();
            $table->foreignId('product_variant_id')->nullable()->constrained();
            $table->string('sku', 100)->nullable();
            $table->string('product_name', 255);
            $table->string('variant_name', 255)->nullable();
            $table->integer('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2);
            $table->timestamps();

            $table->index('quotation_id');
            $table->index('product_id');
        });

        DB::statement('ALTER TABLE quotation_items ADD CONSTRAINT check_quotation_item_quantity CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
    }
};
