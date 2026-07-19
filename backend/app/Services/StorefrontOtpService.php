<?php

namespace App\Services;

use App\Mail\StorefrontOtpMail;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Passwordless order-lookup codes for the storefront ("Find my orders").
 *
 * A guest checks out with no account; later they enter their phone or email,
 * receive a 6-digit code, and see every order tied to that contact — on any
 * device, with nothing to remember. This never gates checkout.
 *
 * State lives entirely in the cache (Redis in prod): the code and a short
 * verified-session token. No schema, no migration. Codes are keyed by a hash
 * of the contact so raw phone numbers / emails never sit in cache keys.
 *
 * Delivery is channel-aware:
 *   • phone  → WhatsApp AUTHENTICATION template (business-initiated, no 24h
 *              window). If the phone also has an email on a past order, the
 *              code is emailed too, so lookup still works before the template
 *              is approved.
 *   • email  → transactional email.
 */
class StorefrontOtpService
{
    public const CODE_TTL         = 600;    // code valid 10 minutes
    public const SESSION_TTL      = 86400;  // verified session 24 hours
    public const MAX_ATTEMPTS     = 5;      // wrong tries before a code dies
    public const RESEND_COOLDOWN  = 45;     // seconds between sends per contact

    /**
     * Classify and canonicalise a raw contact string.
     *
     * @return array{type:?string,value:?string} type is 'phone'|'email'|null
     */
    public static function normalizeContact(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['type' => null, 'value' => null];
        }

        if (str_contains($raw, '@')) {
            $email = strtolower($raw);
            return filter_var($email, FILTER_VALIDATE_EMAIL)
                ? ['type' => 'email', 'value' => $email]
                : ['type' => null, 'value' => null];
        }

        $phone = self::normalizePhone($raw);
        return $phone ? ['type' => 'phone', 'value' => $phone] : ['type' => null, 'value' => null];
    }

    /**
     * Canonical E.164 digits (no '+') for a Kenyan-or-international number.
     * Full-number, country-aware — never last-N matching (avoids cross-border
     * collisions). Returns null if it cannot be a real phone number.
     */
    public static function normalizePhone(string $raw): ?string
    {
        $hasPlus = str_starts_with(trim($raw), '+');
        $digits  = preg_replace('/\D/', '', $raw);
        if ($digits === '') {
            return null;
        }

        if (!$hasPlus) {
            // Local Kenyan forms: 07XXXXXXXX / 01XXXXXXXX → 2547/2541XXXXXXXX.
            if (str_starts_with($digits, '0') && strlen($digits) === 10) {
                $digits = '254' . substr($digits, 1);
            } elseif (strlen($digits) === 9 && (str_starts_with($digits, '7') || str_starts_with($digits, '1'))) {
                $digits = '254' . $digits;
            }
        }

        return strlen($digits) >= 9 && strlen($digits) <= 15 ? $digits : null;
    }

    /**
     * The stored phone encodings we should match an order against, derived
     * from a canonical E.164 (no '+'). Full numbers only.
     */
    public static function phoneVariants(string $e164): array
    {
        $variants = ['+' . $e164, $e164];
        if (str_starts_with($e164, '254') && strlen($e164) === 12) {
            $variants[] = '0' . substr($e164, 3);  // local 07.. / 01..
        }
        return array_values(array_unique($variants));
    }

    private static function codeKey(string $type, string $value): string
    {
        return 'sf_otp:code:' . hash('sha256', $type . ':' . $value);
    }

    private static function cooldownKey(string $type, string $value): string
    {
        return 'sf_otp:cd:' . hash('sha256', $type . ':' . $value);
    }

    private static function sessionKey(string $token): string
    {
        return 'sf_otp:sess:' . hash('sha256', $token);
    }

    /**
     * Generate a code for a contact and deliver it. Returns the channels the
     * code was actually sent on (may be empty if delivery failed) plus a masked
     * destination hint for the UI. Throws nothing on unknown contacts — the
     * caller must not reveal whether a contact exists.
     *
     * @return array{channels:array<string>,hint:string}
     */
    public function requestCode(string $type, string $value): array
    {
        // Cooldown so the endpoint can't be used to spam a number/inbox.
        if (Cache::has(self::cooldownKey($type, $value))) {
            return ['channels' => [], 'hint' => self::mask($type, $value), 'throttled' => true];
        }

        $code = (string) random_int(100000, 999999);
        Cache::put(self::codeKey($type, $value), [
            'code'     => $code,
            'attempts' => 0,
            'type'     => $type,
            'value'    => $value,
        ], self::CODE_TTL);
        Cache::put(self::cooldownKey($type, $value), 1, self::RESEND_COOLDOWN);

        $channels = [];
        if ($type === 'phone') {
            if (WhatsAppService::sendAuthCode($value, $code)) {
                $channels[] = 'whatsapp';
            }
            // Insurance while the WhatsApp template is pending / out of window:
            // if this phone has an email on any past order, email the code too.
            $email = Order::whereIn('customer_phone', self::phoneVariants($value))
                ->whereNotNull('customer_email')
                ->orderByDesc('id')
                ->value('customer_email');
            if ($email && $this->sendEmail($email, $code)) {
                $channels[] = 'email';
            }
        } elseif ($type === 'email') {
            if ($this->sendEmail($value, $code)) {
                $channels[] = 'email';
            }
        }

        return ['channels' => $channels, 'hint' => self::mask($type, $value)];
    }

    private function sendEmail(string $email, string $code): bool
    {
        try {
            Mail::to($email)->send(new StorefrontOtpMail($code, (int) (self::CODE_TTL / 60)));
            return true;
        } catch (\Throwable $e) {
            Log::warning('StorefrontOtpService email send failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify a submitted code. On success returns an opaque session token
     * (valid 24h); on any failure returns null. Codes are single-use and die
     * after MAX_ATTEMPTS wrong guesses.
     */
    public function verify(string $type, string $value, string $code): ?string
    {
        $key   = self::codeKey($type, $value);
        $entry = Cache::get($key);
        if (!$entry) {
            return null;
        }

        if (($entry['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            Cache::forget($key);
            return null;
        }

        if (!hash_equals((string) $entry['code'], trim($code))) {
            $entry['attempts'] = ($entry['attempts'] ?? 0) + 1;
            Cache::put($key, $entry, self::CODE_TTL);
            return null;
        }

        Cache::forget($key);
        Cache::forget(self::cooldownKey($type, $value));

        $token = Str::random(48);
        Cache::put(self::sessionKey($token), ['type' => $type, 'value' => $value], self::SESSION_TTL);
        return $token;
    }

    /** Contact behind a verified session token, or null if expired/invalid. */
    public function sessionContact(string $token): ?array
    {
        $entry = Cache::get(self::sessionKey($token));
        return $entry ?: null;
    }

    /** Partially mask a contact for display ("+2547•••••678" / "j•••@x.com"). */
    public static function mask(string $type, string $value): string
    {
        if ($type === 'email') {
            [$user, $domain] = array_pad(explode('@', $value, 2), 2, '');
            $u = mb_strlen($user) <= 2 ? $user : mb_substr($user, 0, 1) . '•••';
            return "{$u}@{$domain}";
        }
        $len = strlen($value);
        if ($len <= 5) {
            return $value;
        }
        return '+' . substr($value, 0, 4) . str_repeat('•', max(0, $len - 7)) . substr($value, -3);
    }
}
