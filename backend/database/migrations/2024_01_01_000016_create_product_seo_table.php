<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_seo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('language_code', 5);
            $table->string('meta_title', 255)->nullable();
            $table->text('meta_description')->nullable();
            $table->text('meta_keywords')->nullable();
            $table->string('canonical_url', 500)->nullable();
            $table->string('og_title', 255)->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image', 500)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'language_code']);
            $table->index(['product_id']);
            $table->index(['language_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_seo');
    }
};
