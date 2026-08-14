<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The global scope bounds Eloquent. It does not bound the query builder.
 *
 * `DB::table('payments as p')->join('orders as o', …)` never touches the Order
 * model, so ViewerScope never applies — the boundary simply is not there. That
 * is a real limit of the approach, and the claim that "every endpoint inherits
 * the boundary" is true of Eloquent and false of raw SQL.
 *
 * There are roughly 95 raw builder queries against orders and payments in this
 * codebase. Most sit in reporting a cashier cannot reach. These are the two a
 * scoped cashier CAN reach, and they need the constraint applied by hand.
 */
class RawQueryScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function clerk(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName('pos_clerk', 'sanctum'));
        $user->outlets()->attach(Outlet::factory()->create()->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        return $user->fresh();
    }

    private function pendingPaymentOn(User $creator): Payment
    {
        $order = Order::factory()->create(['order_type' => 'pos', 'created_by' => $creator->id]);

        return Payment::factory()->create([
            'order_id'          => $order->id,
            'status'            => 'paid',
            'requires_approval' => true,
            'approval_status'   => 'pending_review',
        ]);
    }

    public function test_the_approvals_queue_shows_a_cashier_only_their_own(): void
    {
        $theirs = $this->pendingPaymentOn(User::factory()->create());
        $clerk  = $this->clerk();
        $mine   = $this->pendingPaymentOn($clerk);

        $response = $this->getJson('/api/v1/admin/payments/pending-approval')->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
    }

    public function test_the_pending_badge_matches_the_scoped_list(): void
    {
        $this->pendingPaymentOn(User::factory()->create());
        $this->pendingPaymentOn(User::factory()->create());
        $clerk = $this->clerk();
        $this->pendingPaymentOn($clerk);

        $this->getJson('/api/v1/admin/payments/pending-approval')
            ->assertOk()
            ->assertJsonPath('pending_count', 1);
    }

    public function test_the_cash_report_is_no_longer_a_cashiers_key(): void
    {
        // Every cash payment in the group for a date range, with customer names
        // and phone numbers. payments.view is what a cashier holds to record a
        // takings; it is not the right lock for a finance report.
        $this->clerk();

        $this->getJson('/api/v1/admin/payments/cash-report?start_date=2026-01-01&end_date=2026-12-31')
            ->assertForbidden();
    }

    public function test_the_ledger_key_still_opens_the_cash_report(): void
    {
        $user = User::factory()->create();
        foreach (['payments.view', 'payments.transactions'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/admin/payments/cash-report?start_date=2026-01-01&end_date=2026-12-31')
            ->assertOk();
    }
}
