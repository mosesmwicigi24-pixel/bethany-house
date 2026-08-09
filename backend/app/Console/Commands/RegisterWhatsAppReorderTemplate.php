<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Create (or inspect) the WhatsApp MARKETING template used for automated
 * reorder reminders (replenishment pings). MARKETING templates are
 * business-initiated, so they reach a customer with no open 24-hour window —
 * exactly what a proactive "you're due to reorder" nudge needs.
 *
 * Unlike AUTHENTICATION templates (where Meta owns the copy), we declare the
 * full body here. Marketing templates go through Meta review — usually
 * minutes to hours, occasionally a day.
 *
 *   php artisan whatsapp:reorder-template          # create if missing, else show
 *   php artisan whatsapp:reorder-template --force   # (re)create
 *
 * Requires WABA_ID (the WhatsApp Business Account id — WhatsApp Manager →
 * Account tools → the numeric ID; already set in the prod .env). WABA_TOKEN
 * is reused; it needs the whatsapp_business_management scope (it has it).
 */
class RegisterWhatsAppReorderTemplate extends Command
{
    protected $signature = 'whatsapp:reorder-template {--force : Recreate even if it already exists} {--lang= : Language code (default from config)}';

    protected $description = 'Create/inspect the WhatsApp marketing template for automated reorder reminders';

    public function handle(): int
    {
        $token   = config('services.whatsapp.token');
        $wabaId  = config('services.whatsapp.business_account_id');
        $version = config('services.whatsapp.api_version', 'v19.0');
        $name    = config('services.whatsapp.reorder_template', 'reorder_reminder');
        $lang    = $this->option('lang') ?: config('services.whatsapp.reorder_template_lang', 'en');

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
            $this->line('and re-run:  php artisan whatsapp:reorder-template');
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

        // {{1}} first name, {{2}} days since last order, {{3}} product name.
        $payload = [
            'name'       => $name,
            'language'   => $lang,
            'category'   => 'MARKETING',
            'components' => [
                [
                    'type' => 'BODY',
                    'text' => "Hello {{1}}! Greetings from Bethany House \u{1F64F}. "
                        . "It's been about {{2}} days since your last {{3}} order. "
                        . "Shall we prepare your usual for you? Reply YES and we'll have it ready — we deliver.",
                    'example' => ['body_text' => [['Grace', '30', 'Holy Communion Bread']]],
                ],
                [
                    'type'    => 'BUTTONS',
                    'buttons' => [
                        ['type' => 'QUICK_REPLY', 'text' => 'Yes, prepare my order'],
                    ],
                ],
            ],
        ];

        $this->line("Creating MARKETING template '{$name}' ({$lang}) on WABA {$wabaId}…");
        $res = Http::withToken($token)->post($base, $payload);

        if ($res->failed()) {
            $this->error('Template creation failed: ' . $res->body());
            return self::FAILURE;
        }

        $body = $res->json();
        $this->info('Created. id=' . ($body['id'] ?? '?') . ' status=' . ($body['status'] ?? 'PENDING'));
        $this->line('Marketing templates go through Meta review (usually fast). Re-run this');
        $this->line('command (without --force) to check the status; sends fail with error');
        $this->line('132001 until the template is APPROVED.');
        return self::SUCCESS;
    }
}
