<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Three access-control holes closed together, each of which let a cashier reach
 * something no cashier should reach.
 *
 *  1. The payment ledger, including its CSV export, sat inside
 *     `permission:payments.view` — the permission every cashier holds so they
 *     can record a takings. `payments.transactions` was defined for exactly
 *     this group and never wired up.
 *  2. GET /admin/dashboard carried no permission at all and returned
 *     today_sales: every paid order in the business, to any authenticated user.
 *  3. PosController::authoriseOutletAccess fell OPEN for a non-admin with no
 *     outlet assignment — no assignment meant every outlet rather than none.
 *
 * Each test asserts both directions: the role that should be refused is, and
 * the role that should still get through does.
 */
class AccessControlHolesTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<string> $permissions */
    private function actingAsUserWith(array $permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function actingAsCustomer(): User
    {
        // Exactly what POST /auth/register mints: an active customer with a
        // ['*'] token and no roles or permissions. UserFactory defaults to
        // 'staff', so the customer state is explicit here.
        $user = User::factory()->customer()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    // ── 0. The staff boundary on the whole /admin/* surface ──────────────────

    public function test_a_self_registered_customer_is_refused_the_admin_api(): void
    {
        // Before EnsureStaff, a customer token reached every admin route without
        // a per-route permission gate: internal chat (read AND write), internal
        // comments with user enumeration, and global search.
        $this->actingAsCustomer();

        $this->getJson('/api/v1/admin/sidebar-badges')->assertForbidden();
        $this->getJson('/api/v1/admin/channels')->assertForbidden();
        $this->getJson('/api/v1/admin/comments')->assertForbidden();
        $this->getJson('/api/v1/admin/comments/users')->assertForbidden();
        $this->getJson('/api/v1/admin/search')->assertForbidden();
        // A permission-gated route is refused too — now by the staff gate,
        // which runs ahead of the permission check.
        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_the_staff_gate_lets_a_plain_staff_user_through(): void
    {
        // A staff user with no extra permissions still reaches a bare admin
        // route: EnsureStaff is an outer boundary, not a permission. The default
        // UserFactory user_type is 'staff'.
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/sidebar-badges')->assertOk();
    }

    // ── 1. The payment ledger ────────────────────────────────────────────────

    public function test_a_cashiers_payments_view_no_longer_opens_the_ledger(): void
    {
        // Exactly what pos_clerk holds in the payments module.
        $this->actingAsUserWith(['payments.view', 'payments.record', 'payments.upload_proof']);

        $this->getJson('/api/v1/admin/payment-transactions')->assertForbidden();
        $this->getJson('/api/v1/admin/payment-transactions/analytics')->assertForbidden();
        $this->getJson('/api/v1/admin/payment-transactions/export')->assertForbidden();
    }

    public function test_the_ledger_key_still_opens_the_ledger(): void
    {
        $this->actingAsUserWith(['payments.view', 'payments.transactions']);

        $this->getJson('/api/v1/admin/payment-transactions')->assertOk();
    }

    public function test_a_cashier_keeps_the_payment_routes_the_till_needs(): void
    {
        // The ledger moved; the rest of the payments group did not. Proof
        // uploads and the approval queue are inside the same payments.view
        // group and must still answer.
        $this->actingAsUserWith(['payments.view', 'payments.record', 'payments.upload_proof']);

        $this->getJson('/api/v1/admin/payments/pending-approval')->assertOk();
    }

    // ── 2. The dashboard ─────────────────────────────────────────────────────

    public function test_the_dashboard_now_requires_a_permission(): void
    {
        $this->actingAsUserWith(['pos.access']);   // authenticated, no dashboard.view

        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_a_cashier_sees_the_dashboard_without_group_revenue(): void
    {
        // dashboard.view is granted to pos_clerk so the screen still loads;
        // the money figures are withheld separately.
        $this->actingAsUserWith(['dashboard.view', 'pos.access']);

        $response = $this->getJson('/api/v1/admin/dashboard')->assertOk();

        $this->assertArrayNotHasKey('today_sales', $response->json('stats'));
        $this->assertArrayNotHasKey('pending_payment_approvals', $response->json('stats'));
        // Non-financial counts still render, so the screen is not empty.
        $this->assertArrayHasKey('today_orders', $response->json('stats'));
    }

    public function test_a_reports_holder_still_sees_group_revenue(): void
    {
        $this->actingAsUserWith(['dashboard.view', 'reports.view']);

        Order::factory()->create([
            'payment_status' => 'paid',
            'total_amount'   => 2500,
            'created_at'     => now(),
        ]);

        $response = $this->getJson('/api/v1/admin/dashboard')->assertOk();

        $this->assertArrayHasKey('today_sales', $response->json('stats'));
        $this->assertEqualsWithDelta(2500.0, (float) $response->json('stats.today_sales'), 0.001);
    }

    // ── 3. Actions on a payment are not the ledger key ───────────────────────

    public function test_refunding_a_payment_does_not_require_the_ledger_key(): void
    {
        // Reversing a payment you are already handling is a different job from
        // reading every payment in the group. Gating the whole
        // payment-transactions prefix on payments.transactions would have
        // coupled them — 23 existing tests caught it.
        $this->actingAsUserWith(['payments.view', 'orders.refund']);

        // 404 (no such payment), not 403: the route is reachable. `reason` is
        // supplied because the endpoint validates it before the lookup — a
        // 422 would prove reachability too, but pinning 404 keeps the test
        // about authorisation rather than about the validation rules.
        $this->postJson('/api/v1/admin/payment-transactions/999999/refund', [
            'amount' => 1,
            'reason' => 'test',
        ])->assertStatus(404);
    }

    public function test_voiding_a_payment_does_not_require_the_ledger_key(): void
    {
        $this->actingAsUserWith(['payments.view', 'payments.void']);

        $this->postJson('/api/v1/admin/payment-transactions/999999/void', ['reason' => 'x'])
            ->assertStatus(404);
    }
}

/*
 * The outlet fall-open (LEAK-14) is deliberately NOT in this PR.
 *
 * Making an empty outlet assignment mean "nothing" rather than "everything" is
 * correct, but it is a behavioural change, not a one-line hole: ~20 existing
 * tests create a POS actor with no outlet and rely on being let through, which
 * is the codebase's own assumption written down. Shipping it here would mean
 * either rewriting those tests inside an unrelated PR, or locking out any real
 * user who has no outlet attached.
 *
 * It gets its own PR, which starts by auditing which live users have no outlet
 * assignment.
 */
