<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Currency plan, Step 3 (owner, 2026-10-18): keep the reporting rate with
 * the money.
 *
 * Until now every report stated a foreign sale at TODAY's reporting rate
 * (currencies.reporting_rate_to_kes), past periods included — so a rate
 * change approved in October would silently rewrite August. From here each
 * order, payment, quotation and purchase order carries the rate in force
 * when it was made (`reporting_rate_to_kes`, KES per unit) and where that
 * rate came from (`reporting_rate_source`, see App\Support\ReportingRateStamp),
 * and reports read the stored rate through one SQL function:
 *
 *   fx_rate_to_kes(stored, code)
 *     = the stored rate; when none is stored (a legacy row, or a currency
 *       that had no rate at the time) the live rate for `code`; NULL when
 *       neither exists — so the row stays out of KES sums, never guessed.
 *     The session setting bethany.fx_basis = 'current' makes it ignore the
 *     stored rate, which is how the comparison command (reports:fx-basis-compare)
 *     shows what the old basis would have said.
 *
 * BACKFILL, from the rate history the system holds, never from a guess:
 *   - approved rate changes since 3 Oct 2026 are in change_proposals
 *     (event reporting_fx_change, status applied): each is a dated point, and
 *     the first one's "old" value is the rate before it;
 *   - the rates set on 20 Aug 2026 (migrations 2026_08_20_140000/150000):
 *     USD 128, ZMW 6.5;
 *   - a currency with a live rate but no dated history (set outside the
 *     approval flow): the live rate, marked no_history;
 *   - a row older than the first known point for its currency carries the
 *     earliest known rate, marked earliest_known;
 *   - KES is always 1; a currency with no rate anywhere stays NULL (none).
 *
 * If no rate has changed since it was set, every report reads exactly as
 * before — the comparison command proves it rather than assumes it.
 */
return new class extends Migration
{
    private const TABLES = ['orders', 'payments', 'quotations', 'purchase_orders'];

    /** The first reporting rates, set by migration on 20 Aug 2026. */
    private const INITIAL = ['USD' => 128.0, 'ZMW' => 6.5];
    private const INITIAL_AT = '2026-08-20 00:00:00';

    public function up(): void
    {
        foreach (self::TABLES as $t) {
            if (!Schema::hasColumn($t, 'reporting_rate_to_kes')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->decimal('reporting_rate_to_kes', 14, 6)->nullable()->after('currency_code');
                    $table->string('reporting_rate_source', 20)->nullable()->after('reporting_rate_to_kes');
                });
            }
        }

        $this->backfill();

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fx_rate_to_kes(stored numeric, code text) RETURNS numeric
            LANGUAGE sql STABLE AS $$
                -- $1 = stored, $2 = code. Positional on purpose: inside an SQL
                -- function an unqualified parameter name loses to a column of
                -- the same name, and currencies has a column called code.
                SELECT COALESCE(
                    CASE WHEN current_setting('bethany.fx_basis', true) = 'current' THEN NULL ELSE $1 END,
                    (SELECT rc.reporting_rate_to_kes FROM currencies rc WHERE UPPER(rc.code) = UPPER($2))
                )
            $$;
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS fx_rate_to_kes(numeric, text)');
        }
        foreach (self::TABLES as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn(['reporting_rate_to_kes', 'reporting_rate_source']));
        }
    }

    /**
     * Stamp every unstamped row from the rate timeline. Only rows whose
     * reporting_rate_to_kes is NULL are touched, so the migration can be
     * re-run and never rewrites a stamp a row already carries.
     */
    private function backfill(): void
    {
        // KES: always 1.
        foreach (self::TABLES as $t) {
            DB::table($t)->whereNull('reporting_rate_to_kes')
                ->where(fn ($q) => $q->whereNull('currency_code')->orWhereRaw("UPPER(currency_code) = 'KES'"))
                ->update(['reporting_rate_to_kes' => 1, 'reporting_rate_source' => 'kes']);
        }

        $currencies = DB::table('currencies')->get(['id', 'code', 'reporting_rate_to_kes'])
            ->mapWithKeys(fn ($c) => [strtoupper($c->code) => $c]);

        foreach ($currencies as $code => $c) {
            if ($code === 'KES') {
                continue;
            }
            $timeline = $this->timeline($code, $c);
            foreach (self::TABLES as $t) {
                $this->stampTable($t, $code, $timeline);
            }
        }

        // Any other currency code on a row (no currencies row at all): no rate.
        foreach (self::TABLES as $t) {
            DB::table($t)->whereNull('reporting_rate_to_kes')->whereNull('reporting_rate_source')
                ->update(['reporting_rate_source' => 'none']);
        }
    }

    /**
     * The dated rate points for one currency, oldest first:
     * [[at: Carbon, rate: ?float, source: string], ...]
     */
    private function timeline(string $code, object $currency): array
    {
        $points = [];

        if (isset(self::INITIAL[$code])) {
            $points[] = ['at' => Carbon::parse(self::INITIAL_AT), 'rate' => self::INITIAL[$code], 'source' => 'initial'];
        }

        $proposals = DB::table('change_proposals')
            ->where('event', 'reporting_fx_change')
            ->where('subject_type', 'currency')
            ->where('subject_id', $currency->id)
            ->where('status', 'applied')
            ->orderByRaw('COALESCE(effective_from, applied_at, created_at)')
            ->orderBy('id')
            ->get();

        foreach ($proposals as $i => $p) {
            $set = json_decode((string) $p->changeset, true) ?: [];
            if (!array_key_exists('reporting_rate_to_kes', $set)) {
                continue;
            }
            $at  = Carbon::parse($p->effective_from ?? $p->applied_at ?? $p->created_at);
            $old = $set['reporting_rate_to_kes']['old'] ?? null;
            $new = $set['reporting_rate_to_kes']['new'] ?? null;
            // The first dated change tells us the rate BEFORE it, when the
            // initial set does not already.
            if ($i === 0 && $old !== null && !isset(self::INITIAL[$code])) {
                $points[] = ['at' => Carbon::parse('1970-01-01'), 'rate' => (float) $old, 'source' => 'history'];
            }
            $points[] = ['at' => $at, 'rate' => $new === null ? null : (float) $new, 'source' => 'history'];
        }

        // A live rate with no dated history at all: set outside the flow.
        if ($points === [] && $currency->reporting_rate_to_kes !== null) {
            $points[] = ['at' => Carbon::parse('1970-01-01'), 'rate' => (float) $currency->reporting_rate_to_kes, 'source' => 'no_history'];
        }

        usort($points, fn ($a, $b) => $a['at'] <=> $b['at']);

        return $points;
    }

    private function stampTable(string $table, string $code, array $timeline): void
    {
        $base = fn () => DB::table($table)->whereNull('reporting_rate_to_kes')->whereRaw('UPPER(currency_code) = ?', [$code]);

        if ($timeline === []) {
            $base()->update(['reporting_rate_source' => 'none']);

            return;
        }

        // Each point covers [its date, the next point's date).
        foreach ($timeline as $i => $point) {
            $next = $timeline[$i + 1]['at'] ?? null;
            $q = $base()->where('created_at', '>=', $point['at']);
            if ($next) {
                $q->where('created_at', '<', $next);
            }
            $q->update([
                'reporting_rate_to_kes' => $point['rate'],
                'reporting_rate_source' => $point['rate'] === null ? 'none' : $point['source'],
            ]);
        }

        // Older than the first known point: the earliest rate, marked as such.
        $first = null;
        foreach ($timeline as $point) {
            if ($point['rate'] !== null) {
                $first = $point;
                break;
            }
        }
        if ($first) {
            $base()->where('created_at', '<', $timeline[0]['at'])
                ->update(['reporting_rate_to_kes' => $first['rate'], 'reporting_rate_source' => 'earliest_known']);
        }
        $base()->update(['reporting_rate_source' => 'none']);
    }
};
