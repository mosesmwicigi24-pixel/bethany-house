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
 */
class SuperAdminPromoterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_promotes_an_existing_account(): void
    {
        $user = User::factory()->create([
            'email'     => 'mwicigi@icloud.com',
            'user_type' => UserType::CUSTOMER->value,
            'status'    => 'inactive',
        ]);

        $result = SuperAdminPromoter::ensure('mwicigi@icloud.com');

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
        $result = SuperAdminPromoter::ensure('mwicigi@icloud.com');

        $this->assertTrue($result['created']);
        $user = User::where('email', 'mwicigi@icloud.com')->firstOrFail();
        $this->assertTrue($user->hasRole('super_admin', 'sanctum'));
        $this->assertSame(UserType::SYSTEM, $user->user_type);
        $this->assertSame('active', $user->status);
    }

    public function test_it_is_idempotent(): void
    {
        SuperAdminPromoter::ensure('mwicigi@icloud.com');
        SuperAdminPromoter::ensure('mwicigi@icloud.com');

        $this->assertSame(1, User::where('email', 'mwicigi@icloud.com')->count());
    }
}
