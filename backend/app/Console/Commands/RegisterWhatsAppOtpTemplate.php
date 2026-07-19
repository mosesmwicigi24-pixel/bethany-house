<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Create (or inspect) the WhatsApp AUTHENTICATION template used to deliver
 * storefront order-lookup codes. AUTHENTICATION templates are business-
 * initiated, so they reach a customer with no open 24-hour window — the whole
 * reason free-form text can't be used for OTPs.
 *
 * Meta owns the copy for authentication templates: we only declare the code
 * button, a security note and an expiry. They are normally approved within
 * minutes (often automatically).
 *
 *   php artisan whatsapp:otp-template          # create if missing, else show
 *   php artisan whatsapp:otp-template --force   # (re)create
 *
 * Requires WABA_ID (the WhatsApp Business Account id — WhatsApp Manager →
 * Account tools → the numeric ID). WABA_TOKEN already present for sends is
 * reused; it needs the whatsapp_business_management scope (it has it).
 */
class RegisterWhatsAppOtpTemplate extends Command
{
    protected $signature = 'whatsapp:otp-template {--force : Recreate even if it already exists} {--lang= : Language code (default from config)}';

    protected $description = 'Create/inspect the WhatsApp authentication template for storefront order-lookup codes';

    public function handle(): int
    {
        $token   = config('services.whatsapp.token');
        $wabaId  = config('services.whatsapp.business_account_id');
        $version = config('services.whatsapp.api_version', 'v19.0');
        $name    = config('services.whatsapp.otp_template', 'order_lookup_code');
        $lang    = $this->option('lang') ?: config('services.whatsapp.otp_template_lang', 'en');

        if (!$token) {
            $this->error('WABA_TOKEN is not set — cannot talk to the WhatsApp API.');
            return self::FAILURE;
        }
        if (!$wabaId) {
            $this->error('WABA_ID (WhatsApp Business Account id) is not set.');
            $this->line('');
            $this->line('Find it in WhatsApp Manager → Account tools / Settings (the numeric');
            $this->line('"WhatsApp Business Account ID"), then add to the backend .env:');
            $this->line('');
            $this->line('  WABA_ID=1234567890');
            $this->line('');
            $this->line('and re-run:  php artisan whatsapp:otp-template');
            return self::FAILURE;
        }

        $base = "https://graph.facebook.com/{$version}/{$wabaId}/message_templates";

        // Already present?
        $existing = Http::withToken($token)->get($base, [
            'fields' => 'name,status,category,language',
            'limit'  => 200,
        ]);
        if ($existing->failed()) {
            $this->error('Could not list templates: ' . $existing->body());
            return self::FAILURE;
        }
        $match = collect($existing->json('data', []))
            ->firstWhere(fn ($t) => ($t['name'] ?? null) === $name && ($t['language'] ?? null) === $lang);

        if ($match && !$this->option('force')) {
            $this->info("Template '{$name}' ({$lang}) already exists — status: {$match['status']}.");
            $this->line('Use --force to recreate. Nothing to do.');
            return self::SUCCESS;
        }

        $payload = [
            'name'       => $name,
            'language'   => $lang,
            'category'   => 'AUTHENTICATION',
            'components' => [
                ['type' => 'BODY',   'add_security_recommendation' => true],
                ['type' => 'FOOTER', 'code_expiration_minutes' => 10],
                ['type' => 'BUTTONS', 'buttons' => [
                    ['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => 'Copy code'],
                ]],
            ],
        ];

        $this->line("Creating AUTHENTICATION template '{$name}' ({$lang}) on WABA {$wabaId}…");
        $res = Http::withToken($token)->post($base, $payload);

        if ($res->failed()) {
            $this->error('Template creation failed: ' . $res->body());
            return self::FAILURE;
        }

        $body = $res->json();
        $this->info('Created. id=' . ($body['id'] ?? '?') . ' status=' . ($body['status'] ?? 'PENDING'));
        $this->line('Authentication templates usually approve within minutes. Re-run this');
        $this->line('command (without --force) to check the status.');
        return self::SUCCESS;
    }
}
