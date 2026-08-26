<?php

namespace App\Console\Commands;

use App\Enums\DataScope;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\User;
use App\Services\DataScopeResolver;
use Illuminate\Console\Command;

/**
 * Why does this person see these records and not those?
 *
 * Data scope is decided from three things at once — which roles a user holds,
 * which of those roles grant the capability, and the data_scope on each — and
 * when the answer surprises somebody there is no way to see any of it from the
 * UI. "The super admin cannot see Everlyne's draft quotations" is a question
 * about a row in `roles`, but it arrives as a bug report about a page.
 *
 * This prints the whole chain for one account, and then the counts the API
 * would actually return for them, so the answer is a fact rather than a
 * deduction.
 *
 *   php artisan user:visibility mwicigi@icloud.com
 */
class ExplainVisibility extends Command
{
    protected $signature = 'user:visibility {email : The account to explain}';

    protected $description = "Explain what one user can see, and why";

    /** Restricted models, and the capability that governs reading each. */
    private const RESOURCES = [
        'Quotations' => Quotation::class,
        'Orders'     => Order::class,
    ];

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (!$user) {
            $this->error("No account with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("{$user->first_name} {$user->last_name} <{$user->email}>");

        $roles = $user->roles;

        if ($roles->isEmpty()) {
            $this->warn('  Holds NO roles. Scope resolves to "all" — no role narrows anything.');
        } else {
            $this->table(
                ['Role', 'data_scope', 'Permissions'],
                $roles->map(fn ($r) => [
                    $r->name,
                    $r->data_scope ?? '(null → treated as all)',
                    $r->permissions->count(),
                ])->all(),
            );
        }

        $isSuper = $user->hasRole('super_admin');
        if ($isSuper) {
            $this->line('  Holds super_admin: every check is bypassed and scope is always "all".');
        }

        // What the API would return for this person, from the same code path.
        $rows = [];
        foreach (self::RESOURCES as $label => $model) {
            $permission = $model::viewPermission();
            $scope      = DataScopeResolver::for($user, $permission);

            $granting = $roles->filter(fn ($r) => $r->permissions->contains('name', $permission));
            $why = match (true) {
                $isSuper                => 'super_admin bypass',
                $granting->isEmpty()    => "no role grants {$permission}, so nothing narrows it",
                default                 => 'widest of ' . $granting->map(
                    fn ($r) => $r->name . '=' . ($r->data_scope ?? 'all')
                )->join(', '),
            };

            // Count as this user, through the global scope, exactly as a
            // request would. Then the same query with the scope lifted.
            auth()->setUser($user);
            $visible = $model::query()->count();
            auth()->forgetUser();
            $total = $model::withoutViewerScope()->count();

            $rows[] = [
                $label,
                $scope->value,
                "{$visible} of {$total}",
                $why,
            ];
        }

        $this->newLine();
        $this->table(['Resource', 'Scope', 'Can see', 'Why'], $rows);

        $narrowed = collect($rows)->firstWhere(1, DataScope::Own->value);
        if ($narrowed && !$isSuper) {
            $this->newLine();
            $this->warn(
                'Scope "own" means this account sees only rows it created. If that is wrong for'
                . ' this person, widen the data_scope on the role named above, or give them a role'
                . ' that grants the capability at "all" — the widest granting role wins.'
            );
        }

        return self::SUCCESS;
    }
}
