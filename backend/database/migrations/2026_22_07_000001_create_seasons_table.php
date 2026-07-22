<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liturgical Seasons — the conductor for the storefront's seasonal "skin".
 *
 * A season is a dated window (Lent→Easter, Pentecost, Harvest, Advent→Christmas)
 * that carries a subtle theme palette + motif and, optionally, a linked
 * promotion (the "Blessed Friday" discount — server-authoritative money lives on
 * the promotion, never here) and a hero banner. The storefront reads the active
 * season via GET /api/v1/site/theme and re-skins itself. Everything is managed
 * from the hub CMS — no hardcoding on the client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();   // slug: lent-easter, pentecost, harvest, advent-christmas
            $table->string('name', 120);
            $table->string('tagline', 200)->nullable();
            $table->string('scripture', 300)->nullable();
            // Subtle theme palette applied by the storefront (accent + motif only;
            // the navy base + typography are never touched). JSON so the CMS can
            // recolour a season without a schema change.
            $table->json('theme')->nullable();
            $table->timestamp('starts_at')->nullable(); // active window; null = windowless/default
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true); // CMS enable/disable toggle
            $table->integer('priority')->default(0);     // higher wins when windows overlap
            // The season's Blessed Friday campaign — the discount lives on the
            // promotion (percentage/fixed, its own window). Null until set in the CMS.
            $table->foreignId('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            // Optional seasonal hero banner (reuses the existing banners table).
            $table->foreignId('banner_id')->nullable()->constrained('banners')->nullOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active']);
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seasons');
    }
};
