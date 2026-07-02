<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_variant_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('image_url', 500);
            $table->string('thumbnail_url', 500)->nullable();
            $table->string('alt_text', 255)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id']);
            $table->index(['product_variant_id']);
            $table->index(['product_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
