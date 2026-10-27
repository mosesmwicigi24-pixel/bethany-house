<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Prints a fresh VAPID key pair for browser (Web Push) notifications.
 *
 * Nothing is written anywhere: the operator pastes the two lines into the
 * host .env (docs/DEPLOY.md). Rotating the pair invalidates existing browser
 * subscriptions, which re-register on the next console sign-in.
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'webpush:vapid';

    protected $description = 'Generate a VAPID key pair for Web Push (printed, never stored)';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line('VAPID_PUBLIC_KEY=' . $keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY=' . $keys['privateKey']);
        $this->newLine();
        $this->comment('Add both lines to the host .env, then restart the laravel, queue and scheduler containers.');

        return self::SUCCESS;
    }
}
