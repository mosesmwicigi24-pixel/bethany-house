<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * service-accounts:grant-history-reader — give the named machine accounts
 * (config security.service_accounts: the chat agent) the read-only
 * `customer_history_reader` role, BESIDE whatever role they already hold
 * (owner, 2026-11-24: "assign it now").
 *
 * Narrow on purpose. It grants ONE role, to ONLY the configured service
 * accounts — it is not a way to give anybody anything. A dry run unless
 * --apply, which needs --actor: the super admin on whose word it is done,
 * recorded on the audit trail. Running it again changes nothing.
 *
 * Prints counts only: the repository's workflow logs are public.
 */
class GrantCustomerHistoryReader extends Command
{
    private const ROLE = 'customer_history_reader';

    protected $signature = 'service-accounts:grant-history-reader
        {--apply : Grant it (otherwise only count)}
        {--actor= : The super admin approving it — user id}';

    protected $description = 'Grant the read-only customer history role to the configured service accounts (dry run unless --apply --actor).';

    public function handle(): int
    {
        $emails = array_values(array_filter(array_map(
            fn ($e) => strtolower(trim((string) $e)),
            (array) config('security.service_accounts', []),
        )));

        $accounts = $emails === []
            ? collect()
            : User::whereIn(\DB::raw('LOWER(email)'), $emails)->get()->filter->isServiceAccount()->values();

        $role = Role::where('name', self::ROLE)->where('guard_name', 'sanctum')->first();
        if (!$role) {
            $this->error('The role does not exist yet: run permission:sync (a deploy does).');

            return self::FAILURE;
        }

        $holding = $accounts->filter(fn (User $u) => $u->hasRole(self::ROLE));
        $missing = $accounts->reject(fn (User $u) => $u->hasRole(self::ROLE));

        $this->line('Service accounts found: ' . $accounts->count());
        $this->line('Already hold the role: ' . $holding->count());

        if (!$this->option('apply')) {
            $this->line('Would be granted: ' . $missing->count());
            $this->line('Dry run: nothing changed.');

            return self::SUCCESS;
        }

        $actor = ctype_digit((string) $this->option('actor')) ? User::find((int) $this->option('actor')) : null;
        if (!$actor || !$actor->isActive() || !$actor->hasRole('super_admin')) {
            $this->error('--apply needs --actor=<the user id of an active super admin>. Nothing changed.');

            return self::FAILURE;
        }

        foreach ($missing as $account) {
            $before = $account->getRoleNames()->sort()->values()->all();
            $account->assignRole($role);

            ActivityLogService::log('service_account_role_granted', $account, [
                'role'         => self::ROLE,
                'roles_before' => $before,
                'roles_after'  => $account->fresh()->getRoleNames()->sort()->values()->all(),
                'via'          => 'service-accounts:grant-history-reader',
            ], 'Read-only customer history role granted to a service account', $actor);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->line('Granted now: ' . $missing->count());

        return self::SUCCESS;
    }
}
