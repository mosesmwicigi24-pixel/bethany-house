<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\UserPurger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * UserPurger::byEmail removes an account and its entire notification/auth
 * footprint. This backs the one-off removal of the seeded Ngigi Nyoro
 * super_admin (nyorojnr@gmail.com) and guarantees the account can never again
 * be a notification recipient (NotificationService::usersWithRole reads roles).
 */
class UserPurgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_removes_the_account_its_roles_and_push_targets(): void
    {
        $user = User::factory()->create([
            'email'  => 'nyorojnr@gmail.com',
            'status' => 'active',
        ]);
        $user->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $morph = $user->getMorphClass();

        DB::table('push_subscriptions')->insert([
            'user_id'    => $user->id,
            'endpoint'   => 'https://push.example/endpoint-1',
            'p256dh'     => 'k', 'auth' => 'a',
            'is_active'  => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = UserPurger::byEmail('nyorojnr@gmail.com');

        $this->assertSame(1, $result['found']);

        // The role assignment is gone → the account can never be resolved by
        // usersWithRole('admin','super_admin','finance') again.
        $this->assertDatabaseMissing('model_has_roles', [
            'model_id'   => $user->id,
            'model_type' => $morph,
        ]);

        // Push targets removed → no device notification can reach it.
        $this->assertDatabaseMissing('push_subscriptions', ['user_id' => $user->id]);

        // With no referencing records the row is hard-deleted outright
        // (bypassing SoftDeletes) — the original address exists nowhere.
        $this->assertSame(1, $result['deleted']);
        $this->assertDatabaseMissing('users', ['email' => 'nyorojnr@gmail.com']);
    }

    public function test_it_is_a_no_op_when_the_account_does_not_exist(): void
    {
        User::factory()->create(['email' => 'someone.else@example.com']);

        $result = UserPurger::byEmail('nyorojnr@gmail.com');

        $this->assertSame(['found' => 0, 'deleted' => 0, 'neutralized' => 0], $result);
        $this->assertDatabaseHas('users', ['email' => 'someone.else@example.com']);
    }
}
