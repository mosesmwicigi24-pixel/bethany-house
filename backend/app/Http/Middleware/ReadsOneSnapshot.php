<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One report, one moment in time (cycle 8).
 *
 * A report is not one query. The sales ledger runs forty-three — a pass per
 * channel, per stage, per day, per week — and promises its reader that they
 * agree: the stage columns sum to the channel's sales, the channels sum to the
 * recognised total. Under Postgres's default READ COMMITTED every one of those
 * statements sees the database as it is at THAT statement, so an order the
 * till confirms halfway through the request lands in some figures and not
 * others. The page then contradicts itself, and nobody can reproduce it,
 * because by the next refresh the order is in all of them.
 *
 * REPEATABLE READ fixes the snapshot at the request's first statement: every
 * figure on the page describes the same instant. The figures are no older for
 * it — they were never more current than the request's own start.
 *
 * Not READ ONLY, deliberately: the PDF and export routes record the download
 * in the audit trail on the way out, and those are appends, which cannot
 * conflict with anything in this transaction.
 *
 * Steps aside when a transaction is already open — a test's RefreshDatabase,
 * or a caller that owns one — because isolation is fixed at BEGIN and that
 * caller has already chosen.
 */
class ReadsOneSnapshot
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->isMethodSafe() || DB::transactionLevel() > 0) {
            return $next($request);
        }

        return DB::transaction(function () use ($request, $next) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

            return $this->withCacheOutsideTheSnapshot(fn () => $next($request));
        });
    }

    /**
     * Production's cache store is the DATABASE, on the request's connection by
     * default. Inside this transaction its upsert would collide with a cache
     * entry another request committed after our snapshot — two people opening
     * the same report at once, both missing — and Postgres refuses that under
     * REPEATABLE READ: "could not serialize access", a 500. Proven, not
     * supposed: ReportSnapshotConsistencyTest reproduces it.
     *
     * So for the length of the snapshot the cache writes on a connection of
     * its own, committing independently. That is safe HERE because a report
     * request only reads committed data, so whatever it caches is true.
     *
     * Deliberately not app-wide: SettingController clears its cache inside the
     * transaction that writes the settings, and on a separate connection that
     * clear would commit a moment before the settings do — a window in which
     * another request could cache the OLD settings for five minutes.
     */
    private function withCacheOutsideTheSnapshot(Closure $run)
    {
        $store = config('cache.default');
        $key   = "cache.stores.{$store}.connection";

        $previous = config($key);
        $default  = config('database.default');

        if (config("cache.stores.{$store}.driver") !== 'database'
            || ($previous !== null && $previous !== $default)) {
            return $run();   // not a database cache, or already on a connection of its own
        }

        config([
            'database.connections.report_cache' => config("database.connections.{$default}"),
            $key                                => 'report_cache',
        ]);
        Cache::forgetDriver($store);

        try {
            return $run();
        } finally {
            config([$key => $previous]);
            Cache::forgetDriver($store);
        }
    }
}
