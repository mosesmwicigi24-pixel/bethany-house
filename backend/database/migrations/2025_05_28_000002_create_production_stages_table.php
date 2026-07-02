<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only create if not already present (may exist from an earlier migration)
        if (!Schema::hasTable('production_stages')) {
            Schema::create('production_stages', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('slug', 120)->unique();
                $table->text('description')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            // Seed the four default stages
            DB::table('production_stages')->insert([
                ['name' => 'Cutting',       'slug' => 'cutting',       'description' => 'Fabric is measured and cut to pattern.',        'sort_order' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Stitching',     'slug' => 'stitching',     'description' => 'Garment pieces are sewn together.',             'sort_order' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Finishing',     'slug' => 'finishing',     'description' => 'Final touches - buttons, ironing, trimmings.',  'sort_order' => 3, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Quality Check', 'slug' => 'quality-check', 'description' => 'Inspection before the item is released.',       'sort_order' => 4, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('production_stages');
    }
};