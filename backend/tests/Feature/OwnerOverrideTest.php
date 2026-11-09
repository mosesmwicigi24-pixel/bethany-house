<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The owner approving what the owner recorded (App\Support\OwnerOverride,
 * owner's decision 2026-10-21): only the owner account, only the actions
 * listed, only after a fresh step-up on this session, and always marked.
 * Everyone and everything else keeps maker ≠ checker (MakerCheckerTest).
 */
class OwnerOverrideTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_EMAIL = 'mwicigi@icloud.com';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        config(['audit.owner_account_email' => self::OWNER_EMAIL]);
    }

    /** The owner row exists from migration 2026_07_07_100100; reuse it. */
    private function owner(): User
    {
        $u = User::where('email', self::OWNER_EMAIL)->first() ?? User::factory()->create(['email' => self::OWNER_EMAIL]);
        $u->forceFill(['status' => 'active', 'user_type' => 'staff'])->save();
        $u->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function otherSuperAdmin(): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff', 'email' => 'other.super@example.test']);
        $u->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function expenseBy(User $as, float $amount = 5000): Expense
    {
        $category = ExpenseCategory::firstOrCreate(
            ['code' => 'WORKSHOP'],
            ['name' => 'Workshop', 'requires_approval_above' => 3000, 'is_active' => true],
        );
        Sanctum::actingAs($as);
        $id = $this->postJson('/api/v1/admin/expenses', [
            'title' => 'Industrial iron boxes', 'category_id' => $category->id, 'expense_date' => now()->toDateString(),
            'amount' => $amount, 'currency_code' => 'KES', 'payment_method' => 'cash', 'vendor_name' => 'Pastor Moses',
        ])->assertCreated()->json('expense.id');

        return Expense::findOrFail($id);
    }

    private function approve(Expense $e, array $body = [])
    {
        return $this->postJson("/api/v1/admin/expenses/{$e->id}/approve", $body);
    }

    /**
     * Since 2026-11-05 a super admin's own expense is approved on entry
     * (SuperAdminSelfApproval) — no override to ask for, no step-up. It is
     * marked everywhere exactly as an override was.
     */
    public function test_the_owners_own_expense_is_approved_on_entry_and_marked_everywhere(): void
    {
        $owner = $this->owner();
        $e     = $this->expenseBy($owner);

        $e->refresh();
        $this->assertSame('approved', $e->status);
        $this->assertSame($owner->id, (int) $e->approved_by);
        $this->assertDatabaseHas('approval_signatures', ['signer_id' => $owner->id, 'decision' => 'approved', 'owner_override' => true]);
        $this->assertDatabaseHas('expense_approvals', ['expense_id' => $e->id, 'approver_id' => $owner->id, 'action' => 'approved', 'owner_override' => true]);
        $this->assertDatabaseHas('activity_log', ['event' => 'self_approved_super_admin', 'causer_id' => $owner->id]);

        // The detail the console shows carries the mark too.
        $detail = $this->getJson("/api/v1/admin/expenses/{$e->id}")->assertOk();
        $this->assertTrue(collect($detail->json('expense.approvals'))->firstWhere('action', 'approved')['owner_override']);
    }

    public function test_another_super_admins_own_expense_is_approved_on_entry_too(): void
    {
        $other = $this->otherSuperAdmin();
        $e     = $this->expenseBy($other);

        $this->assertSame('approved', $e->fresh()->status);
        $this->assertDatabaseHas('expense_approvals', ['expense_id' => $e->id, 'approver_id' => $other->id, 'owner_override' => true]);
    }

    public function test_an_approval_by_someone_else_is_not_marked(): void
    {
        $admin = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $admin->assignRole(Role::findOrCreate('finance_manager', 'sanctum'));
        $admin->assignRole(Role::findOrCreate('accountant', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $e = $this->expenseBy($admin->fresh());
        $this->assertSame('pending_approval', $e->status, 'anyone but a super admin still waits');

        $finance = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $finance->assignRole(Role::findOrCreate('finance_manager', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($finance->fresh());
        $this->approve($e)->assertOk();
        $this->assertDatabaseHas('expense_approvals', ['expense_id' => $e->id, 'approver_id' => $finance->id, 'owner_override' => false]);
    }

    public function test_the_override_is_for_expenses_only_and_the_owner_cannot_approve_their_own_purchase_order(): void
    {
        $this->assertSame(['expense.approve'], \App\Support\OwnerOverride::ACTIONS);
        $owner = $this->owner();
        $this->assertFalse(\App\Support\OwnerOverride::available($owner, 'purchase_order.approve'));
        $this->assertFalse(\App\Support\OwnerOverride::available($owner, 'payment.approve'));
        $this->assertTrue(\App\Support\OwnerOverride::available($owner, 'expense.approve'));
        $this->assertFalse(\App\Support\OwnerOverride::available($this->otherSuperAdmin(), 'expense.approve'));
    }

    public function test_the_signed_in_profile_says_who_the_owner_is(): void
    {
        Sanctum::actingAs($this->owner());
        $this->assertTrue($this->getJson('/api/v1/admin/auth/me')->assertOk()->json('user.is_owner'));

        Sanctum::actingAs($this->otherSuperAdmin());
        $this->assertFalse($this->getJson('/api/v1/admin/auth/me')->assertOk()->json('user.is_owner'));
    }
}
