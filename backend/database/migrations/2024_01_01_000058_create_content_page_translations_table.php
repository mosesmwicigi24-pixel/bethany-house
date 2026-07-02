<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('content_page_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('content_pages')->onDelete('cascade');
            $table->string('language_code', 5);
            $table->string('title', 255);
            $table->text('content')->nullable();
            $table->string('meta_title', 255)->nullable();
            $table->text('meta_description')->nullable();
            $table->timestamps();

            $table->unique(['page_id', 'language_code']);
            $table->index(['page_id']);
            $table->index(['language_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_page_translations');
    }
};
