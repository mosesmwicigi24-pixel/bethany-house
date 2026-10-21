<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\Auth\StepUp;
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

    private function pendingExpenseBy(User $as, float $amount = 5000): Expense
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
        $e = Expense::findOrFail($id);
        $this->assertSame('pending_approval', $e->status);

        return $e;
    }

    private function approve(Expense $e, array $body = [])
    {
        return $this->postJson("/api/v1/admin/expenses/{$e->id}/approve", $body);
    }

    public function test_without_asking_for_the_override_the_owner_is_refused_like_everyone(): void
    {
        $owner = $this->owner();
        $e     = $this->pendingExpenseBy($owner);

        $this->approve($e)->assertForbidden()->assertJsonPath('code', 'SELF_APPROVAL');
        $this->assertSame('pending_approval', $e->fresh()->status);
    }

    public function test_the_override_first_asks_the_owner_to_confirm_it_is_them(): void
    {
        $owner = $this->owner();
        $e     = $this->pendingExpenseBy($owner);

        $this->approve($e, ['owner_override' => true])
            ->assertForbidden()->assertJsonPath('code', 'step_up_required');
        $this->assertSame('pending_approval', $e->fresh()->status);
        $this->assertDatabaseMissing('activity_log', ['event' => 'self_approval_overridden']);
    }

    public function test_after_confirming_the_owner_approves_their_own_expense_and_it_is_marked_everywhere(): void
    {
        $owner = $this->owner();
        $e     = $this->pendingExpenseBy($owner);
        app(StepUp::class)->confirmFor($owner, null);

        $res = $this->approve($e, ['owner_override' => true, 'comments' => 'Paid the pastor for the boxes'])->assertOk();
        $this->assertSame('Expense approved.', $res->json('message'));
        $this->assertTrue($res->json('approval.signatures.0.owner_override'));

        $e->refresh();
        $this->assertSame('approved', $e->status);
        $this->assertSame($owner->id, (int) $e->approved_by);
        $this->assertDatabaseHas('approval_signatures', ['signer_id' => $owner->id, 'decision' => 'approved', 'owner_override' => true]);
        $this->assertDatabaseHas('expense_approvals', ['expense_id' => $e->id, 'approver_id' => $owner->id, 'action' => 'approved', 'owner_override' => true]);
        $this->assertDatabaseHas('activity_log', ['event' => 'self_approval_overridden', 'causer_id' => $owner->id]);
        $this->assertDatabaseMissing('activity_log', ['event' => 'self_approval_blocked']);

        // The detail the console shows carries the mark too.
        $detail = $this->getJson("/api/v1/admin/expenses/{$e->id}")->assertOk();
        $this->assertTrue(collect($detail->json('expense.approvals'))->firstWhere('action', 'approved')['owner_override']);
    }

    public function test_an_approval_by_someone_else_is_not_marked(): void
    {
        $owner = $this->owner();
        $e     = $this->pendingExpenseBy($owner);
        $finance = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $finance->assignRole(Role::findOrCreate('finance_manager', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($finance->fresh());
        $this->approve($e)->assertOk();
        $this->assertDatabaseHas('expense_approvals', ['expense_id' => $e->id, 'approver_id' => $finance->id, 'owner_override' => false]);
    }

    public function test_another_super_admin_cannot_override_even_after_confirming(): void
    {
        $other = $this->otherSuperAdmin();
        $e     = $this->pendingExpenseBy($other);
        app(StepUp::class)->confirmFor($other, null);

        $this->approve($e, ['owner_override' => true])->assertForbidden()->assertJsonPath('code', 'SELF_APPROVAL');
        $this->assertSame('pending_approval', $e->fresh()->status);
        $this->assertDatabaseHas('activity_log', ['event' => 'self_approval_blocked', 'causer_id' => $other->id]);
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
