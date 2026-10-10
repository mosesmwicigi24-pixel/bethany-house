<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The per-role discount limits (table role_discount_caps), read for
 * App\Support\DiscountRule::capFor().
 *
 * Owner, 2026-10-10: "clerks at 10% and Admins up to 15% … when needed a
 * clerk can give up to. Create a place where super admin can set this too."
 *
 * A role's limit is the most a holder of it may CHOOSE to give at the till, on
 * an order or on a quotation — nothing is applied by itself. A person's limit
 * is the highest among the rows of the roles they hold; with no row for any
 * of their roles, the global maximum (config pos.discount_cap_percent). When
 * the table cannot be read the answer is the global maximum, never "no limit".
 *
 * Cached briefly (CACHE_SECONDS) because every discounted line asks; the
 * cache is dropped whenever the super admin saves (forget()).
 */
final class RoleDiscountCaps
{
    public const TABLE         = 'role_discount_caps';
    public const CACHE_KEY     = 'discount.role_caps.v1';
    public const CACHE_SECONDS = 60;

    /**
     * Every row, role => cap percent. Null when the table cannot be read — the
     * caller then holds everyone to the global maximum.
     *
     * @return array<string, float>|null
     */
    public static function all(): ?array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => self::fresh());
        } catch (\Throwable $e) {
            // A failed read is never cached (remember() stores only a value
            // the closure returned), so the next ask tries again.
            Log::warning('RoleDiscountCaps: falling back to the global discount maximum: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * The table as it stands, uncached.
     *
     * @return array<string, float>
     */
    public static function fresh(): array
    {
        $read = fn () => DB::table(self::TABLE)
            ->pluck('cap_percent', 'role')
            ->map(fn ($v) => (float) $v)
            ->all();

        // Asked mid-sale, inside the sale's transaction: a failed read must
        // not abort it (Postgres poisons the whole transaction on any failed
        // statement), so it runs in its own savepoint.
        return DB::transactionLevel() > 0 ? DB::transaction($read) : $read();
    }

    /**
     * The highest limit among the rows of $roles, or $global when none of
     * them has a row (or the table cannot be read).
     *
     * @param  iterable<string>  $roles
     */
    public static function capForRoles(iterable $roles, float $global): float
    {
        $caps = self::all();
        if ($caps === null) {
            return $global;
        }

        $best = null;
        foreach ($roles as $role) {
            if (array_key_exists((string) $role, $caps)) {
                $best = $best === null ? $caps[$role] : max($best, $caps[$role]);
            }
        }

        return $best ?? $global;
    }

    public static function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable $e) {
            // The entry then lives out its CACHE_SECONDS; say so rather than hide it.
            Log::warning('RoleDiscountCaps: could not drop the cached limits: ' . $e->getMessage());
        }
    }
}
