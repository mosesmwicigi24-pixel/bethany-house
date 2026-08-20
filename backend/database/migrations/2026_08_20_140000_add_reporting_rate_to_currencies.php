<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A SECOND rate, for reporting — deliberately not the pricing one.
 *
 * Bethany runs two different USD rates, and conflating them is the whole
 * hazard this migration exists to prevent:
 *
 *   PRICING     100 KES = 1 USD   what a customer is quoted and charged.
 *                                 Lives in currencies.exchange_rate (USD sits
 *                                 at 0.010000 — base-relative, KES is base).
 *                                 A commercial decision, a round number, and
 *                                 NOT what a dollar is worth.
 *
 *   REPORTING   128 KES = 1 USD   what a dollar of completed sales is worth
 *                                 when translated into the reporting currency.
 *                                 That is this column.
 *
 * Using the pricing rate to report understates foreign revenue by 22%, and the
 * International report has been doing exactly that — it read exchange_rate for
 * its "KES equivalence" because that was the only rate there was.
 *
 * NULL means "no reporting rate has been set for this currency". Those orders
 * stay out of KES totals and are counted separately rather than converted at a
 * guess: ZMW is left NULL here on purpose, because nobody has told us what a
 * kwacha is worth and inventing one would be worse than reporting it apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('currencies', 'reporting_rate_to_kes')) {
            Schema::table('currencies', function (Blueprint $table) {
                // KES per ONE unit of this currency. Read it out loud and it is
                // unambiguous — 128 KES per 1 USD — unlike the base-relative
                // exchange_rate beside it, which is 0.01 for the same currency.
                $table->decimal('reporting_rate_to_kes', 14, 6)->nullable()
                      ->after('exchange_rate');
            });
        }

        DB::table('currencies')->where(DB::raw('UPPER(code)'), 'KES')
          ->update(['reporting_rate_to_kes' => 1]);
        DB::table('currencies')->where(DB::raw('UPPER(code)'), 'USD')
          ->update(['reporting_rate_to_kes' => 128]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('currencies', 'reporting_rate_to_kes')) {
            Schema::table('currencies', function (Blueprint $table) {
                $table->dropColumn('reporting_rate_to_kes');
            });
        }
    }
};
