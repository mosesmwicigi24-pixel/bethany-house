<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

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