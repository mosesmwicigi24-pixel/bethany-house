<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_tax_rates', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('tax_rate_id');
            $table->primary(['product_id', 'tax_rate_id']);

            $table->foreign('product_id')
                  ->references('id')->on('products')
                  ->onDelete('cascade');

            $table->foreign('tax_rate_id')
                  ->references('id')->on('tax_rates')
                  ->onDelete('cascade');

            $table->timestamps();
        });

        // Add is_default and display_name columns to tax_rates if not already there
        Schema::table('tax_rates', function (Blueprint $table) {
            if (!Schema::hasColumn('tax_rates', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('is_active');
            }
            if (!Schema::hasColumn('tax_rates', 'applies_to')) {
                $table->string('applies_to', 30)->default('all')->after('is_default');
                // Enum: all | products | shipping
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_tax_rates');
        Schema::table('tax_rates', function (Blueprint $table) {
            if (Schema::hasColumn('tax_rates', 'is_default')) {
                $table->dropColumn('is_default');
            }
            if (Schema::hasColumn('tax_rates', 'applies_to')) {
                $table->dropColumn('applies_to');
            }
        });
    }
};