<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sign-in safety is a property of the TOKEN (its age, its idle time, its lock,
 * its step-up), so these helpers use real bearer tokens — Sanctum::actingAs
 * skips the token check that enforces it.
 */
trait SignsInForReal
{
    use StepsUp;

    protected function staffWithRoles(array $roles, array $attrs = []): User
    {
        $u = User::factory()->create(array_merge(['user_type' => 'staff', 'status' => 'active'], $attrs));
        foreach ($roles as $r) {
            $u->assignRole(Role::findOrCreate($r, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    /** A system_admin as production grants it: the role carries users.view (Phase 2 catalogue). */
    protected function systemAdmin(): User
    {
        $role = Role::findOrCreate('system_admin', 'sanctum');
        $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('users.view', 'sanctum'));

        return $this->staffWithRoles(['system_admin']);
    }

    protected function bearerFor(User $u): string
    {
        return $u->createAuthToken('auth_token')->plainTextToken;
    }

    /** One request with a bearer token, authenticated afresh (the guard caches the user otherwise). */
    protected function withBearer(string $token, string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        return $this->withHeaders(array_merge(['Authorization' => "Bearer {$token}"], $headers))
            ->json($method, $uri, $data);
    }
}
