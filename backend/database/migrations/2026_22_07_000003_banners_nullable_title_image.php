<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The homepage/marketing content-block CMS reuses `banners` for EVERY slot —
 * including text-only blocks (newsletter, pillars) that have no image and
 * sometimes no title. Relax the NOT NULL constraints so any block can be an
 * entry. (Postgres — matches prod + the CI test service.)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE banners ALTER COLUMN title DROP NOT NULL');
        DB::statement('ALTER TABLE banners ALTER COLUMN image_url DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE banners SET title = '' WHERE title IS NULL");
        DB::statement("UPDATE banners SET image_url = '' WHERE image_url IS NULL");
        DB::statement('ALTER TABLE banners ALTER COLUMN title SET NOT NULL');
        DB::statement('ALTER TABLE banners ALTER COLUMN image_url SET NOT NULL');
    }
};
