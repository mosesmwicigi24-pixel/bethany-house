<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_variant_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('currency_code', 3);
            $table->decimal('regular_price', 12, 2);
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->timestamp('sale_start_date')->nullable();
            $table->timestamp('sale_end_date')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'product_variant_id', 'currency_code'], 'unique_product_price');
            $table->index(['product_id']);
            $table->index(['product_variant_id']);
            $table->index(['currency_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
    }
};
