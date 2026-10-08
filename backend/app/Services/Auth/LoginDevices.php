<?php

namespace App\Services\Auth;

use App\Mail\NewSignInDeviceMail;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Suspicious sign-in (role plan §12.4): a correct password from a device the
 * account has not signed in from before.
 *
 * A device is the browser family + operating system ("Chrome on Windows") and
 * the network it comes from (IPv4 /24, IPv6 /48) — no paid lookup. When the
 * edge supplies a country (Cloudflare's CF-IPCountry) it is recorded with the
 * device and named in the flag, but it is not trusted to make a device known.
 *
 * On a new device: the attempt is flagged on the audit trail
 * (suspicious_sign_in) and the person is emailed. A device becomes known only
 * after a COMPLETE sign-in, so a password alone (2FA refused) never teaches the
 * system an attacker's device. There is no "remember this device" bypass of
 * two-step sign-in: when 2FA is on it is asked for on every sign-in.
 *
 * An account with no known device yet (its first sign-in after this shipped)
 * is not flagged: there is nothing to compare with, and every member of staff
 * would otherwise be emailed on the day it went live.
 */
class LoginDevices
{
    /** @return array{fingerprint:string, agent:string, network:?string, country:?string} */
    public function describe(Request $request): array
    {
        $agent   = self::agentFamily((string) $request->userAgent());
        $network = self::network((string) $request->ip());
        $country = strtoupper((string) $request->header('CF-IPCountry'));
        $country = preg_match('/^[A-Z]{2}$/', $country) && $country !== 'XX' && $country !== 'T1' ? $country : null;

        return [
            'fingerprint' => hash('sha256', $agent . '|' . ($network ?? '-')),
            'agent'       => $agent,
            'network'     => $network,
            'country'     => $country,
        ];
    }

    /**
     * Called once the password is proved. Flags + emails on a new device.
     * Returns true when the sign-in is suspicious.
     */
    public function assess(User $user, Request $request): bool
    {
        $d = $this->describe($request);

        $known = DB::table('login_devices')->where('user_id', $user->id);
        if (!(clone $known)->exists()) {
            return false;
        }
        if ((clone $known)->where('fingerprint', $d['fingerprint'])->exists()) {
            return false;
        }

        ActivityLogService::log('suspicious_sign_in', $user, [
            'flag'    => 'new_device',
            'device'  => $d['agent'],
            'network' => $d['network'],
            'country' => $d['country'],
            'ip'      => $request->ip(),
        ], "Sign-in from a new device for {$user->email}: {$d['agent']}" . ($d['country'] ? " ({$d['country']})" : ''), $user);

        try {
            if ($user->email) {
                Mail::to($user->email)->queue(new NewSignInDeviceMail(
                    name:    $user->first_name ?: $user->email,
                    device:  $d['agent'],
                    ip:      (string) $request->ip(),
                    country: $d['country'],
                    at:      now()->timezone(config('app.timezone'))->format('j M Y, H:i'),
                ));
            }
        } catch (\Throwable $e) {
            // The flag is on the trail either way; a mail outage must not block sign-in.
            Log::error('new sign-in device mail failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        return true;
    }

    /** Called after a complete sign-in: this device is now known. */
    public function remember(User $user, Request $request): void
    {
        $d = $this->describe($request);
        $now = now();

        $updated = DB::table('login_devices')
            ->where('user_id', $user->id)->where('fingerprint', $d['fingerprint'])
            ->update(['last_seen_at' => $now, 'country' => $d['country']]);

        if ($updated === 0) {
            DB::table('login_devices')->insertOrIgnore([
                'user_id'       => $user->id,
                'fingerprint'   => $d['fingerprint'],
                'agent_family'  => mb_substr($d['agent'], 0, 64),
                'network'       => $d['network'],
                'country'       => $d['country'],
                'first_seen_at' => $now,
                'last_seen_at'  => $now,
            ]);
        }
    }

    public static function agentFamily(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/')                                       => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera')         => 'Opera',
            str_contains($ua, 'SamsungBrowser')                             => 'Samsung Internet',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS')     => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS')      => 'Chrome',
            str_contains($ua, 'Safari/')                                    => 'Safari',
            $ua === ''                                                      => 'Unknown browser',
            default                                                         => 'Other browser',
        };
        $os = match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')        => 'iOS',
            str_contains($ua, 'Android')                                    => 'Android',
            str_contains($ua, 'Windows')                                    => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'CrOS')                                       => 'ChromeOS',
            str_contains($ua, 'Linux')                                      => 'Linux',
            default                                                         => 'unknown system',
        };

        return "{$browser} on {$os}";
    }

    public static function network(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode('.', $ip);
            return "{$p[0]}.{$p[1]}.{$p[2]}.0/24";
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            return $bin === false ? null : inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10)) . '/48';
        }

        return null;
    }
}
