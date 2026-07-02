<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1 - Report and null-out any country currency codes that don't
        // exist in the currencies table. Without this the FK creation fails.
        $missing = DB::table('countries')
            ->whereNotNull('default_currency_code')
            ->whereNotIn('default_currency_code', DB::table('currencies')->pluck('code'))
            ->pluck('default_currency_code', 'code');

        if ($missing->isNotEmpty()) {
            // Log which countries had orphaned codes so they can be fixed manually
            foreach ($missing as $countryCode => $currencyCode) {
                \Illuminate\Support\Facades\Log::warning(
                    "Migration: country {$countryCode} had unknown currency code '{$currencyCode}' - cleared."
                );
            }

            // Null them out so the FK constraint can be created cleanly
            DB::table('countries')
                ->whereIn('code', $missing->keys())
                ->update(['default_currency_code' => null]);
        }

        // Step 2 - Add the foreign key constraint
        Schema::table('countries', function (Blueprint $table) {
            $table->foreign('default_currency_code', 'fk_countries_currency')
                ->references('code')
                ->on('currencies')
                ->onUpdate('cascade')   // rename currency code → all countries update
                ->onDelete('set null'); // delete currency → clears the link, not the country
        });
    }

    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->dropForeign('fk_countries_currency');
        });
    }
};