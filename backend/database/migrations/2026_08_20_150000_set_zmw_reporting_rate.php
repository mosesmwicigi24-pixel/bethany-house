<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The kwacha gets a reporting rate: 6.5 KES to 1 ZMW.
 *
 * Left NULL when the reporting column was introduced, because nobody had said
 * what a kwacha was worth and converting at a guess is worse than reporting the
 * order apart. The owner has now said, so it converts.
 *
 * Note the direction, because it is the clearest argument for why these two
 * rates live in separate columns rather than one:
 *
 *   USD   pricing 100  → reporting 128   reporting is HIGHER
 *   ZMW   pricing ~7.14 → reporting 6.5   reporting is LOWER
 *
 * There is no fixed relationship between what Bethany charges in a currency and
 * what that currency is worth. Deriving one from the other would be wrong in
 * both directions at once.
 *
 * The pricing rate (currencies.exchange_rate = 0.140000) is deliberately not
 * touched: quotes and price lists keep behaving exactly as they do today.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('currencies')
            ->where(DB::raw('UPPER(code)'), 'ZMW')
            ->update(['reporting_rate_to_kes' => 6.5]);
    }

    public function down(): void
    {
        DB::table('currencies')
            ->where(DB::raw('UPPER(code)'), 'ZMW')
            ->update(['reporting_rate_to_kes' => null]);
    }
};
