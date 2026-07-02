<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds `display_order` to `payment_methods` if it does not already exist,
     * then seeds it with the current `sort_order` values so existing rows
     * stay in the correct sequence.
     */
    public function up(): void
    {
        $exists = DB::select("
            SELECT 1
            FROM   information_schema.columns
            WHERE  table_name   = 'payment_methods'
            AND    column_name  = 'display_order'
            LIMIT  1
        ");

        if (empty($exists)) {
            DB::statement('ALTER TABLE payment_methods ADD COLUMN display_order integer NOT NULL DEFAULT 0');

            // Seed display_order from sort_order so existing rows keep their order
            DB::statement('UPDATE payment_methods SET display_order = sort_order');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $exists = DB::select("
            SELECT 1
            FROM   information_schema.columns
            WHERE  table_name   = 'payment_methods'
            AND    column_name  = 'display_order'
            LIMIT  1
        ");

        if (!empty($exists)) {
            DB::statement('ALTER TABLE payment_methods DROP COLUMN display_order');
        }
    }
};