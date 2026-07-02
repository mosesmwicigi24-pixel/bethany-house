<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_id')->nullable()->constrained()->onDelete('set null');
            $table->unsignedTinyInteger('rating');
            $table->string('title', 255)->nullable();
            $table->text('review')->nullable();
            $table->boolean('is_verified_purchase')->default(false);
            $table->boolean('is_approved')->default(false);
            $table->integer('helpful_count')->default(0);
            $table->timestamps();

            $table->index(['product_id']);
            $table->index(['user_id']);
            $table->index(['is_approved']);
            $table->index(['rating']);
        });
        
        DB::statement('ALTER TABLE product_reviews ADD CONSTRAINT check_rating CHECK (rating >= 1 AND rating <= 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
