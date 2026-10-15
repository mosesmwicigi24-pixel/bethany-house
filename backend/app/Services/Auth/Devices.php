<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Named sign-in devices (owner, 2026-10-15). See the create_devices migration.
 *
 * What a device IS comes from the browser's own description (user agent) and
 * whether its screen is touch (X-Device-Touch): an iPad's Safari describes
 * itself as a Mac, and only the touch screen gives it away. A laptop and a
 * desktop describe themselves the same way, so "Mac" and "Windows PC" are as
 * far as the facts go — the NAME says the rest.
 *
 * Who names it: the person, after their first complete sign-in on it; or an
 * administrator who may manage that person's sign-in (StaffAuthority). An
 * administrator's name wins and the person cannot change it. A name is a
 * label, never trust: the new-device alarm (LoginDevices) is unchanged, and
 * the email shows the facts beside the name.
 */
class Devices
{
    public const KINDS = [
        'iphone'         => 'iPhone',
        'ipad'           => 'iPad',
        'android_phone'  => 'Android phone',
        'android_tablet' => 'Android tablet',
        'mac'            => 'Mac',
        'windows_pc'     => 'Windows PC',
        'chromebook'     => 'Chromebook',
        'linux'          => 'Linux computer',
        'unknown'        => 'Unknown device',
    ];

    public static function kindOf(string $ua, int $touchPoints = 0): string
    {
        return match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPod')           => 'iphone',
            str_contains($ua, 'iPad')                                          => 'ipad',
            // iPadOS Safari asks for the desktop site as a Mac; Macs have no touch screen.
            str_contains($ua, 'Macintosh') && $touchPoints > 1                 => 'ipad',
            str_contains($ua, 'Android') && str_contains($ua, 'Mobile')        => 'android_phone',
            str_contains($ua, 'Android')                                       => 'android_tablet',
            str_contains($ua, 'CrOS')                                          => 'chromebook',
            str_contains($ua, 'Windows')                                       => 'windows_pc',
            str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS X')    => 'mac',
            str_contains($ua, 'Linux')                                         => 'linux',
            default                                                            => 'unknown',
        };
    }

    public static function kindLabel(?string $kind): string
    {
        return self::KINDS[$kind ?? 'unknown'] ?? self::KINDS['unknown'];
    }

    /** "iPhone · Chrome" — what the browser says it is, nothing more. */
    public static function facts(string $ua, int $touchPoints = 0): string
    {
        return self::kindLabel(self::kindOf($ua, $touchPoints)) . ' · ' . LoginDevices::browserFamily($ua);
    }

    /** The device key the console sent, hashed; null when there is none or it is malformed. */
    public static function keyHash(Request $request): ?string
    {
        $key = (string) $request->header('X-Device-Id', '');

        return preg_match('/^[A-Za-z0-9-]{16,64}$/', $key) ? hash('sha256', $key) : null;
    }

    public static function touchPoints(Request $request): int
    {
        return max(0, min(20, (int) $request->header('X-Device-Touch', '0')));
    }

    /** This request's device row, if the hub has seen it before. */
    public function find(Request $request): ?object
    {
        $hash = self::keyHash($request);

        return $hash ? DB::table('devices')->where('key_hash', $hash)->first() : null;
    }

    /** This request's device, only if $user has completed a sign-in on it. */
    public function knownTo(User $user, Request $request): ?object
    {
        $device = $this->find($request);
        if (!$device) {
            return null;
        }

        return DB::table('device_user')->where('device_id', $device->id)->where('user_id', $user->id)->exists()
            ? $device : null;
    }

    /**
     * After a complete sign-in: the device exists, is linked to the account,
     * and the session about to be issued carries it (PersonalAccessToken
     * reads the request attribute). Returns the device id, or null when the
     * console sent no key (an older console, or an API client).
     */
    public function register(User $user, Request $request): ?int
    {
        $hash = self::keyHash($request);
        if (!$hash) {
            return null;
        }

        $ua   = (string) $request->userAgent();
        $now  = now();
        $kind = self::kindOf($ua, self::touchPoints($request));
        $browser = mb_substr(LoginDevices::browserFamily($ua), 0, 32);

        DB::table('devices')->insertOrIgnore([
            'key_hash' => $hash, 'kind' => $kind, 'browser' => $browser,
            'first_seen_at' => $now, 'last_seen_at' => $now,
        ]);
        $id = (int) DB::table('devices')->where('key_hash', $hash)->value('id');
        DB::table('devices')->where('id', $id)->update(['kind' => $kind, 'browser' => $browser, 'last_seen_at' => $now]);

        $linked = DB::table('device_user')->where('device_id', $id)->where('user_id', $user->id)
            ->update(['last_seen_at' => $now]);
        if ($linked === 0) {
            DB::table('device_user')->insertOrIgnore([
                'device_id' => $id, 'user_id' => $user->id, 'first_seen_at' => $now, 'last_seen_at' => $now,
            ]);
        }

        $request->attributes->set('device_id', $id);

        return $id;
    }

    /** The device of the session making this request (or the one just signed in on). */
    public function currentId(User $user, Request $request): ?int
    {
        $token = $user->currentAccessToken();
        $id = $token && isset($token->device_id) ? $token->device_id : $request->attributes->get('device_id');

        return $id ? (int) $id : null;
    }

    /** The devices an account has signed in on, most recent first. */
    public function listFor(User $user, ?int $currentId = null): array
    {
        $sessions = DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)->where('tokenable_type', $user->getMorphClass())
            ->whereNotNull('device_id')
            ->selectRaw('device_id, count(*) as n')->groupBy('device_id')
            ->pluck('n', 'device_id');

        return DB::table('device_user as du')
            ->join('devices as d', 'd.id', '=', 'du.device_id')
            ->leftJoin('users as nb', 'nb.id', '=', 'd.named_by')
            ->where('du.user_id', $user->id)
            ->orderByDesc('du.last_seen_at')
            ->get(['d.*', 'du.first_seen_at as user_first_seen', 'du.last_seen_at as user_last_seen',
                   'nb.first_name as nb_first', 'nb.last_name as nb_last'])
            ->map(fn ($d) => self::present($d) + [
                'first_seen'      => $d->user_first_seen,
                'last_seen'       => $d->user_last_seen,
                'active_sessions' => (int) ($sessions[$d->id] ?? 0),
                'is_current'      => $currentId !== null && (int) $d->id === $currentId,
            ])
            ->all();
    }

    /** One device as the console and the email show it. */
    public static function present(?object $d): ?array
    {
        if (!$d) {
            return null;
        }
        $facts = self::kindLabel($d->kind) . ($d->browser ? ' · ' . $d->browser : '');
        $namedBy = trim(($d->nb_first ?? '') . ' ' . ($d->nb_last ?? '')) ?: null;

        return [
            'id'          => (int) $d->id,
            'name'        => $d->name,
            'kind'        => $d->kind,
            'kind_label'  => self::kindLabel($d->kind),
            'browser'     => $d->browser,
            'facts'       => $facts,
            'label'       => $d->name ? "{$d->name} ({$facts})" : $facts,
            'name_source' => $d->name_source,
            'named_by'    => $namedBy,
            'needs_name'  => $d->name === null,
        ];
    }

    public function presentById(int $id): ?array
    {
        $d = DB::table('devices as d')->leftJoin('users as nb', 'nb.id', '=', 'd.named_by')
            ->where('d.id', $id)->first(['d.*', 'nb.first_name as nb_first', 'nb.last_name as nb_last']);

        return self::present($d);
    }

    /**
     * Name (or with null, un-name) a device $owner has signed in on.
     * $asAdmin: an administrator acting for $owner — their name wins.
     * Returns null on success, or the refusal to show.
     */
    public function rename(User $owner, int $deviceId, ?string $name, User $actor, bool $asAdmin): ?string
    {
        $device = $this->linkedDevice($owner, $deviceId);
        if (!$device) {
            return 'not_found';
        }
        if (!$asAdmin && $device->name_source === 'admin') {
            return 'An administrator named this device. Ask them to change it.';
        }

        $name = $name === null ? null : trim(preg_replace('/[\p{C}]+/u', ' ', $name));
        $name = $name === '' ? null : $name;

        DB::table('devices')->where('id', $device->id)->update([
            'name'        => $name,
            'name_source' => $name === null ? null : ($asAdmin ? 'admin' : 'self'),
            'named_by'    => $name === null ? null : $actor->id,
            'named_at'    => $name === null ? null : now(),
        ]);

        ActivityLogService::log('device_named', $owner, [
            'device_id' => $device->id, 'from' => $device->name, 'to' => $name, 'by_admin' => $asAdmin,
        ], $name === null
            ? "Device name cleared ({$device->name})"
            : "Device named \"{$name}\"" . ($device->name ? " (was \"{$device->name}\")" : ''), $actor);

        return null;
    }

    /**
     * Remove a device from $owner's list: their sessions on it end, and their
     * next sign-in on it raises the new-device alarm again. Other people who
     * use the same device (a shared till) are not touched.
     */
    public function remove(User $owner, int $deviceId, User $actor): ?int
    {
        $device = $this->linkedDevice($owner, $deviceId);
        if (!$device) {
            return null;
        }

        $ended = DB::transaction(function () use ($owner, $device) {
            $ended = DB::table('personal_access_tokens')
                ->where('tokenable_id', $owner->id)->where('tokenable_type', $owner->getMorphClass())
                ->where('device_id', $device->id)->delete();
            DB::table('login_devices')->where('user_id', $owner->id)->where('device_id', $device->id)->delete();
            DB::table('device_user')->where('user_id', $owner->id)->where('device_id', $device->id)->delete();

            return $ended;
        });

        $label = self::present($device)['label'];
        ActivityLogService::log('device_removed', $owner, ['device_id' => $device->id, 'sessions_ended' => $ended],
            "Device removed: {$label} ({$ended} session(s) ended)", $actor);

        return $ended;
    }

    private function linkedDevice(User $owner, int $deviceId): ?object
    {
        return DB::table('devices as d')
            ->join('device_user as du', 'du.device_id', '=', 'd.id')
            ->where('d.id', $deviceId)->where('du.user_id', $owner->id)
            ->first(['d.*']);
    }
}
