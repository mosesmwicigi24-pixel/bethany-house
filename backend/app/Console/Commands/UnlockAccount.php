<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\AccountLockout;
use Illuminate\Console\Command;

/**
 * Break-glass unlock for an account held locked after failed sign-ins
 * (Phase 4C). The console's Unlock action needs a system_admin (Tier 2–3) or
 * another super_admin; this is for when no such person can sign in — e.g.
 * every super_admin locked by a password-guesser. Server access only.
 */
class UnlockAccount extends Command
{
    protected $signature = 'auth:unlock {email : The locked account\'s email address}';

    protected $description = 'Unlock a staff account locked after failed sign-ins (break-glass)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (!$user) {
            $this->error('No account with that email.');
            return self::FAILURE;
        }

        $was = app(AccountLockout::class)->lockOf($user)['kind'] ?? 'not_locked';
        app(AccountLockout::class)->unlock($user, null, 'artisan auth:unlock');

        $this->info("Unlocked {$user->email} (was: {$was}).");

        return self::SUCCESS;
    }
}
