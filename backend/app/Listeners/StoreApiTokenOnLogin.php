<?php

namespace App\Listeners;

use App\Services\ApiClient;
use Illuminate\Auth\Events\Login;

/**
 * StoreApiTokenOnLogin
 *
 * Listens for Laravel's built-in Login event (fired whenever any guard
 * authenticates a user - including Livewire login components).
 *
 * Creates a Sanctum personal access token named "admin-session" and stores
 * the plain-text token in the web session under ApiClient::TOKEN_KEY.
 *
 * This token is then automatically attached to every API request made
 * through ApiClient, so the admin web app authenticates with the API
 * using the same credentials as the logged-in user.
 *
 * Register in app/Providers/EventServiceProvider.php (or AppServiceProvider):
 *
 *   use Illuminate\Auth\Events\Login;
 *   use App\Listeners\StoreApiTokenOnLogin;
 *
 *   Event::listen(Login::class, StoreApiTokenOnLogin::class);
 *
 * Or in bootstrap/app.php with:
 *
 *   ->withEvents(discover: [__DIR__.'/../app/Listeners'])
 */
class StoreApiTokenOnLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        // Only issue tokens to users who can access the admin panel
        if (! method_exists($user, 'createToken')) {
            return;
        }

        // Revoke any existing admin-session tokens to avoid accumulation
        $user->tokens()->where('name', 'admin-session')->delete();

        // Create a fresh Sanctum token and store it in the web session
        $token = $user->createAuthToken('admin-session')->plainTextToken;

        ApiClient::storeToken($token);
    }
}