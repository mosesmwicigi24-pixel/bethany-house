<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->string('subtitle', 255)->nullable();
            $table->string('image_url', 500);
            $table->string('mobile_image_url', 500)->nullable();
            $table->string('link_url', 500)->nullable();
            $table->string('link_text', 100)->nullable();
            $table->string('position', 50)->default('hero');
            // hero | sidebar | popup | footer | category_top
            $table->string('placement', 50)->default('homepage');
            $table->boolean('is_active')->default(true);
            $table->boolean('open_in_new_tab')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('styles')->nullable(); // text_color, bg_color, overlay_opacity, etc.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['position', 'is_active']);
            $table->index(['sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};