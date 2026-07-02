<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // Add as nullable first - existing rows have no name yet
            $table->string('name_en')->nullable()->after('slug');
            $table->string('name_sw')->nullable()->after('name_en');
            $table->string('name_fr')->nullable()->after('name_sw');
            $table->string('name_pt')->nullable()->after('name_fr');

            $table->text('description_en')->nullable()->after('name_pt');
            $table->text('description_sw')->nullable()->after('description_en');
            $table->text('description_fr')->nullable()->after('description_sw');
            $table->text('description_pt')->nullable()->after('description_fr');

            $table->string('icon')->nullable()->after('image_url');
            $table->string('color', 20)->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->boolean('show_in_menu')->default(true);
            $table->boolean('show_in_storefront')->default(true);
            $table->boolean('featured')->default(false);
            $table->unsignedInteger('products_count')->default(0);
        });

        // Backfill name_en from slug for any existing rows
        DB::statement("UPDATE categories SET name_en = INITCAP(REPLACE(slug, '-', ' ')) WHERE name_en IS NULL AND slug IS NOT NULL");
        DB::statement("UPDATE categories SET name_en = 'Unnamed Category' WHERE name_en IS NULL");
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn([
                'name_en', 'name_sw', 'name_fr', 'name_pt',
                'description_en', 'description_sw', 'description_fr', 'description_pt',
                'icon', 'meta_title', 'meta_description', 'meta_keywords',
                'color', 'show_in_menu', 'show_in_storefront', 'featured',
                'products_count',
            ]);
        });
    }
};