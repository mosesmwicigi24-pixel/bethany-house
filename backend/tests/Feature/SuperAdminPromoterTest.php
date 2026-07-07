<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use App\Support\SuperAdminPromoter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SuperAdminPromoter::ensure makes an account THE super admin: the super_admin
 * role (Gate::before bypass), user_type SYSTEM (canAccessAdmin), and active
 * status. Backs the migration that makes mwicigi@icloud.com the owner super
 * admin after the Ngigi Nyoro account is removed.
 *
 * NB: the generic cases use a neutral email — mwicigi@icloud.com is already
 * provisioned by migration 2026_07_07_100100 in every (refreshed) test DB, which
 * the dedicated end-to-end test below asserts.
 */
class SuperAdminPromoterTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'owner-promoter-test@example.com';

    public function test_the_migration_makes_the_owner_the_super_admin(): void
    {
        $owner = User::where('email', 'mwicigi@icloud.com')->first();

        $this->assertNotNull($owner, 'migration should provision the owner account');
        $this->assertTrue($owner->hasRole('super_admin', 'sanctum'));
        $this->assertTrue($owner->isSuperAdmin());
        $this->assertTrue($owner->canAccessAdmin());
        $this->assertSame(UserType::SYSTEM, $owner->user_type);
        $this->assertSame('active', $owner->status);
    }

    public function test_it_promotes_an_existing_account(): void
    {
        $user = User::factory()->create([
            'email'     => self::EMAIL,
            'user_type' => UserType::CUSTOMER->value,
            'status'    => 'inactive',
        ]);

        $result = SuperAdminPromoter::ensure(self::EMAIL);

        $this->assertFalse($result['created']);
        $user->refresh();
        $this->assertTrue($user->hasRole('super_admin', 'sanctum'));
        $this->assertTrue($user->isSuperAdmin());
        $this->assertSame(UserType::SYSTEM, $user->user_type);
        $this->assertTrue($user->canAccessAdmin());
        $this->assertSame('active', $user->status);
    }

    public function test_it_creates_the_account_when_absent(): void
    {
        $result = SuperAdminPromoter::ensure(self::EMAIL);

        $this->assertTrue($result['created']);
        $user = User::where('email', self::EMAIL)->firstOrFail();
        $this->assertTrue($user->hasRole('super_admin', 'sanctum'));
        $this->assertSame(UserType::SYSTEM, $user->user_type);
        $this->assertSame('active', $user->status);
    }

    public function test_it_is_idempotent(): void
    {
        SuperAdminPromoter::ensure(self::EMAIL);
        SuperAdminPromoter::ensure(self::EMAIL);

        $this->assertSame(1, User::where('email', self::EMAIL)->count());
    }
}
