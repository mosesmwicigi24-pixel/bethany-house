<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Widen the rate column to support values like 16, 20, 25, etc.
        DB::statement('ALTER TABLE tax_rates ALTER COLUMN rate TYPE NUMERIC(8,4)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tax_rates ALTER COLUMN rate TYPE NUMERIC(5,4)');
    }
};