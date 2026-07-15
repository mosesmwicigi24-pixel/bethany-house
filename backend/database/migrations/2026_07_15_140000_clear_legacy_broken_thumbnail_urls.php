<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clear broken legacy thumbnail URLs on product images.
 *
 * Some product_images carry a `thumbnail_url` pointing at a pre-migration path
 * like `/admin/legacy/<name>_thumb.jpg` that no longer exists — every product
 * card requesting it logs a 404. thumbnail_url is nullable and the UI falls back
 * to image_url (then to a placeholder), so nulling these broken values is safe,
 * non-destructive, and stops the 404s. The main image_url is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_images')) {
            return;
        }

        DB::table('product_images')
            ->where('thumbnail_url', 'ILIKE', '%legacy/%')
            ->update(['thumbnail_url' => null]);
    }

    public function down(): void
    {
        // The legacy URLs were broken; there is nothing to restore.
    }
};
