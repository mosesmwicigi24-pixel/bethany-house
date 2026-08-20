<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Guarantee the currencies the reporting layer depends on actually exist.
 *
 * Every money figure now reads its rate from this table. That is deliberate —
 * it means an edited rate takes effect immediately and no cache can go stale —
 * but it also means an ABSENT row silently reduces a report to zero rather than
 * to a wrong number, because a missing rate is NULL and SUM() skips NULL.
 *
 * The rows exist in production. They did NOT exist in a freshly migrated
 * database (the seeder is not part of the migration run), so every engine
 * reported nothing there — which is exactly how this was caught: the whole
 * customer-engine test suite went to zero the moment reporting started reading
 * this table.
 *
 * Inserting them here makes the invariant part of the schema rather than part
 * of a seeder someone might not run.
 *
 * Rates, and why there are two of them, are documented on
 * App\Support\ReportingCurrency. In short: exchange_rate is what a customer is
 * QUOTED at, reporting_rate_to_kes is what earned money is WORTH.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            // code, name, symbol, pricing (base-relative), reporting (KES per unit), base
            ['KES', 'Kenyan Shilling', 'KES', 1.0,  1.0,   true],
            ['USD', 'US Dollar',       '$',   0.01, 128.0, false],
            ['ZMW', 'Zambian Kwacha',  'ZK',  0.14, 6.5,   false],
        ];

        foreach ($rows as [$code, $name, $symbol, $pricing, $reporting, $isBase]) {
            $exists = DB::table('currencies')->whereRaw('UPPER(code) = ?', [$code])->exists();
            if ($exists) {
                // Never overwrite a rate an operator has set by hand; only fill
                // in a reporting rate that was never configured.
                DB::table('currencies')
                    ->whereRaw('UPPER(code) = ?', [$code])
                    ->whereNull('reporting_rate_to_kes')
                    ->update(['reporting_rate_to_kes' => $reporting, 'updated_at' => now()]);
                continue;
            }

            DB::table('currencies')->insert([
                'code' => $code, 'name' => $name, 'symbol' => $symbol,
                'exchange_rate' => $pricing, 'reporting_rate_to_kes' => $reporting,
                'decimal_places' => 2, 'is_base' => $isBase, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** Not reversible: removing a currency row would zero every report. */
    public function down(): void
    {
    }
};
