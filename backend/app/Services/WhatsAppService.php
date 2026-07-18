<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp delivery over the Meta Cloud API.
 *
 * Deliberately tiny: one static send(), configured entirely by env
 * (WABA_TOKEN, WABA_PHONE_NUMBER_ID, WABA_API_VERSION — the same variable
 * names the Neema deployment on this VPS already uses, so one set of
 * credentials serves both apps). Unconfigured environments are a safe
 * no-op that logs and returns false — CI and local dev never touch Meta.
 *
 * Free-form text sends require the recipient to have messaged the business
 * number within the 24-hour customer-service window. For a daily owner
 * digest that is normally true (reply once and the window stays open); if
 * Meta rejects with error 131047 (window closed), we log it clearly so the
 * fix — send any message to the business number — is obvious.
 */
class WhatsAppService
{
    public static function configured(): bool
    {
        return (bool) (config('services.whatsapp.token') && config('services.whatsapp.phone_number_id'));
    }

    /** Send a plain text message to an E.164 number. Returns true on accept. */
    public static function send(string $to, string $text): bool
    {
        if (!self::configured()) {
            Log::info('WhatsAppService: not configured (WABA_TOKEN / WABA_PHONE_NUMBER_ID missing), skipping send.');
            return false;
        }

        $version = config('services.whatsapp.api_version', 'v19.0');
        $phoneId = config('services.whatsapp.phone_number_id');
        $to      = preg_replace('/[^\d+]/', '', $to);

        try {
            $res = Http::withToken(config('services.whatsapp.token'))
                ->timeout(15)
                ->post("https://graph.facebook.com/{$version}/{$phoneId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to'                => ltrim($to, '+'),
                    'type'              => 'text',
                    'text'              => ['body' => mb_substr($text, 0, 4096)],
                ]);

            if ($res->successful()) {
                return true;
            }

            $code = $res->json('error.code');
            Log::warning('WhatsAppService send failed', [
                'to' => $to, 'status' => $res->status(), 'error' => $res->json('error'),
                'hint' => $code === 131047
                    ? 'Recipient outside the 24h window — they should send any message to the business number.'
                    : null,
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error("WhatsAppService send exception for {$to}: {$e->getMessage()}");
            return false;
        }
    }
}
