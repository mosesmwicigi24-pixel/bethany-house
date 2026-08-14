<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * php artisan pos:outlet-check
 *
 * Read-only. Lists the staff who would be refused at the till now that an empty
 * outlet assignment means "no outlet" rather than "every outlet".
 *
 * Run this BEFORE deploying that change. A non-admin with POS access and no
 * outlet attached used to pass every outlet check; they will now be refused
 * with a message telling them to ask for an assignment. That is the correct
 * behaviour, but it is better discovered here than at a counter with a customer
 * waiting.
 */
class CheckOutletAssignments extends Command
{
    protected $signature   = 'pos:outlet-check';
    protected $description = 'List staff who can reach the till but have no outlet assigned.';

    public function handle(): int
    {
        // Admins are exempt: PosController::authoriseOutletAccess returns before
        // it reads any assignment, so they are unaffected either way.
        $affected = User::query()
            ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['admin', 'super_admin']))
            ->whereDoesntHave('outlets')
            ->with('roles:id,name')
            ->get(['id', 'first_name', 'last_name', 'email', 'status']);

        $withPos = $affected->filter(fn (User $u) => $u->can('pos.access'));

        $this->info('Outlet assignment check');
        $this->line(sprintf('  non-admin staff with no outlet : %d', $affected->count()));
        $this->line(sprintf('  …of whom can reach the till    : %d', $withPos->count()));
        $this->newLine();

        if ($withPos->isEmpty()) {
            $this->line('  <fg=green>✓</> Nobody is locked out by the outlet-scope change.');
        } else {
            $this->line('  <fg=red>These accounts will be refused at the till until an outlet is assigned:</>');
            $this->table(
                ['id', 'name', 'email', 'status', 'roles'],
                $withPos->map(fn (User $u) => [
                    $u->id,
                    trim($u->first_name . ' ' . $u->last_name),
                    $u->email,
                    $u->status,
                    $u->roles->pluck('name')->implode(', ') ?: '—',
                ])->all(),
            );
            $this->line('  Fix: Settings → Users → edit → Assigned Outlet.');
        }

        // The rest have no till access, so the change cannot affect them today —
        // but an unassigned account is still a setup gap worth seeing.
        $others = $affected->diff($withPos);
        if ($others->isNotEmpty()) {
            $this->newLine();
            $this->line(sprintf('  %d other unassigned account(s) without till access: %s',
                $others->count(), $others->pluck('email')->implode(', ')));
        }

        return self::SUCCESS;
    }
}
