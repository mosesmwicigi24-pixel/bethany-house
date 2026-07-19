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

    /**
     * Send a pre-approved message template. Unlike free-form text, an approved
     * template can be delivered business-initiated (no open 24h window), which
     * is why one-time codes must go through a template. $components follows the
     * Cloud API template component array (body/button parameters).
     */
    public static function sendTemplate(string $to, string $template, string $lang, array $components = []): bool
    {
        if (!self::configured()) {
            Log::info('WhatsAppService: not configured, skipping template send.');
            return false;
        }

        $version = config('services.whatsapp.api_version', 'v19.0');
        $phoneId = config('services.whatsapp.phone_number_id');
        $to      = preg_replace('/[^\d+]/', '', $to);

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => ltrim($to, '+'),
            'type'              => 'template',
            'template'          => [
                'name'     => $template,
                'language' => ['code' => $lang],
            ],
        ];
        if (!empty($components)) {
            $payload['template']['components'] = $components;
        }

        try {
            $res = Http::withToken(config('services.whatsapp.token'))
                ->timeout(15)
                ->post("https://graph.facebook.com/{$version}/{$phoneId}/messages", $payload);

            if ($res->successful()) {
                return true;
            }

            $code = $res->json('error.code');
            Log::warning('WhatsAppService template send failed', [
                'to' => $to, 'template' => $template, 'status' => $res->status(),
                'error' => $res->json('error'),
                'hint' => $code === 132001
                    ? "Template '{$template}' does not exist or is not approved for this WABA — run `php artisan whatsapp:otp-template`."
                    : null,
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error("WhatsAppService template send exception for {$to}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Deliver a one-time login/lookup code through the approved AUTHENTICATION
     * template. For authentication templates Meta requires the code to be
     * echoed in BOTH the body parameter and the (copy-code) button parameter.
     */
    public static function sendAuthCode(string $to, string $code): bool
    {
        $template = config('services.whatsapp.otp_template', 'order_lookup_code');
        $lang     = config('services.whatsapp.otp_template_lang', 'en');

        $components = [
            [
                'type'       => 'body',
                'parameters' => [['type' => 'text', 'text' => $code]],
            ],
            [
                'type'       => 'button',
                'sub_type'   => 'url',
                'index'      => '0',
                'parameters' => [['type' => 'text', 'text' => $code]],
            ],
        ];

        return self::sendTemplate($to, $template, $lang, $components);
    }
}
