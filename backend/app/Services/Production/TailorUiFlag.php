<?php

namespace App\Services\Production;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * `tailor_ledger_ui` — who gets the movement screen.
 *
 * The rollout is one station at a time, starting with a single tailor, and the
 * old screen stays reachable the whole way. That means the flag has to be
 * scoped two ways at once: **per user**, so one person can pilot it while her
 * colleagues carry on, and **per station**, so Sewing can move over while
 * Cutting waits. A single global boolean would force the floor to switch in one
 * step, which is exactly the change-management risk the plan is built to avoid.
 *
 * The flag never gates *data* — the ledger records every movement regardless.
 * Turning it off returns a station to the old screen in one release with no
 * loss, because both screens are now writing the same movements. That is what
 * makes it a genuine kill switch rather than a hope.
 *
 * Stored as one JSON settings row so a supervisor can widen the pilot without a
 * deploy:
 *
 *     { "enabled": true, "user_ids": [12], "stage_codes": ["SEWING"] }
 *
 * `enabled: false` is off for everyone. With no `user_ids` and no
 * `stage_codes`, an enabled flag means the whole floor.
 */
class TailorUiFlag
{
    public const KEY = 'tailor_ledger_ui';

    private const CACHE_TTL = 30;   // seconds — a pilot is changed by hand, rarely

    public function enabledFor(?User $user, ?string $stageCode = null): bool
    {
        $config = $this->config();

        if (!($config['enabled'] ?? false)) {
            return false;
        }

        $users  = array_map('intval', $config['user_ids'] ?? []);
        $stages = array_map('strtoupper', $config['stage_codes'] ?? []);

        if ($users && (!$user || !in_array((int) $user->id, $users, true))) {
            return false;
        }

        // A station filter only applies once we know which station is asking.
        if ($stages && $stageCode !== null && !in_array(strtoupper($stageCode), $stages, true)) {
            return false;
        }

        return true;
    }

    /** The raw row, for the settings screen. */
    public function config(): array
    {
        return Cache::remember(
            'flag:'.self::KEY,
            self::CACHE_TTL,
            function () {
                $raw = DB::table('settings')->where('key', self::KEY)->value('value');
                $decoded = $raw ? json_decode($raw, true) : null;

                return is_array($decoded) ? $decoded : ['enabled' => false];
            }
        );
    }

    public function set(array $config): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => self::KEY],
            [
                'value'       => json_encode($config),
                'data_type'   => 'json',
                'group'       => 'production',
                'description' => 'Ledger-backed tailor workspace: rollout scope by user and station.',
                'is_public'   => false,
                'updated_at'  => now(),
                'created_at'  => now(),
            ],
        );

        Cache::forget('flag:'.self::KEY);
    }
}
