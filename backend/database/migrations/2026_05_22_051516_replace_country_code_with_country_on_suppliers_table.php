<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('country', 100)->nullable()->after('city');
        });

        // Migrate any existing data
        DB::statement("UPDATE suppliers SET country = country_code WHERE country_code IS NOT NULL");

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('country_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('country_code', 10)->nullable()->after('city');
        });

        DB::statement("UPDATE suppliers SET country_code = country WHERE country IS NOT NULL");

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('country');
        });
    }
};
