<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('language_code', 5);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->text('short_description')->nullable();
            $table->json('specifications')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'language_code']);
            $table->index(['product_id']);
            $table->index(['language_code']);
            $table->index(['name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_translations');
    }
};
