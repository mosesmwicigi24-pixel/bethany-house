<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        User::class => UserPolicy::class,
        // Add more model-policy mappings as you create them
        // Example:
        // Product::class => ProductPolicy::class,
        // Order::class => OrderPolicy::class,
        // etc.
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // Define any additional gates here if needed
        // Example:
        // Gate::define('viewAdmin', function (User $user) {
        //     return $user->hasAnyRole(['super_admin', 'admin']);
        // });

        // A token belongs to an account, not to whoever holds it. Deactivating
        // a person used to leave every token they held working until it
        // expired (EnsureStaff checked the user TYPE only, and nothing after
        // login read `status`), so an inactive or suspended account kept the
        // whole API. Every bearer request now re-reads the account: anything
        // but active is unauthenticated (401), which the console treats as
        // "signed out".
        //
        // Phase 4C extends the same check (one mechanism, not two): a staff
        // session also ends at its role's idle / absolute limit, and a
        // clerk's idle session is held behind the terminal PIN — see
        // App\Services\Auth\SessionPolicy. An account held locked after too
        // many failed sign-ins is refused like an inactive one.
        Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);
        Sanctum::authenticateAccessTokensUsing(
            fn ($token, bool $isValid) => $isValid
                && ($token->tokenable?->status ?? null) === 'active'
                && $token->tokenable->locked_at === null
                && app(\App\Services\Auth\SessionPolicy::class)->admit($token)
        );

        // Implicitly grant "Super Admin" role all permissions.
        // Uses explicit sanctum guard to avoid "no permission for guard web" errors
        // when Gate resolves can() for API token users.
        Gate::before(function (User $user, string $ability) {
            if ($user->hasRole('super_admin', 'sanctum')) {
                return true;
            }
        });
    }
}