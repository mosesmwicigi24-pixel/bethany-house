<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A cashier sees their own sales, not the shop's.
 *
 * The change the whole access-control programme was for. Five cashiers stop
 * seeing each other's POS orders; the invoice list, the CSV export and the
 * search box narrow with them, because all three run the same query.
 *
 * The consequence was decided rather than discovered: a cashier cannot take a
 * balance payment or process a return against a colleague's sale. Both answer
 * with an explanation and a route out, not a bare 404 — a customer is standing
 * at the counter holding the receipt, and "not found" is untrue and useless.
 */
class PosClerkOwnSalesTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        $this->outlet = Outlet::factory()->create();
    }

    private function clerk(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName('pos_clerk', 'sanctum'));
        $user->outlets()->attach($this->outlet->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        return $user->fresh();
    }

    private function saleBy(User $clerk): Order
    {
        return Order::factory()->create([
            'order_type'     => 'pos',
            'status'         => 'confirmed',
            'outlet_id'      => $this->outlet->id,
            'created_by'     => $clerk->id,
            'total_amount'   => 1000,
            'payment_status' => 'partial',
        ]);
    }

    // ── The leak closes ──────────────────────────────────────────────────────

    public function test_pos_clerk_ships_scoped_to_own(): void
    {
        $this->assertSame(
            'own',
            DB::table('roles')->where('name', 'pos_clerk')->value('data_scope'),
            'permission:sync must apply ROLE_SCOPES',
        );
    }

    public function test_a_cashier_sees_only_their_own_sales_in_the_list(): void
    {
        $mine   = $this->saleBy($clerk = $this->clerk());
        $theirs = $this->saleBy(tap(User::factory()->create(), fn ($u) => $u->assignRole(Role::findByName('pos_clerk', 'sanctum'))));

        $ids = collect($this->getJson('/api/v1/admin/orders')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id), "another cashier's sale must not appear");
    }

    public function test_the_csv_export_narrows_with_the_list(): void
    {
        // The export mirrors index()'s filters and was the bulk-exfiltration
        // route. It runs the same query, so it inherits the same boundary.
        $mine   = $this->saleBy($this->clerk());
        $theirs = $this->saleBy(User::factory()->create());

        $csv = $this->get('/api/v1/admin/orders/export')->assertOk()->getContent();

        $this->assertStringContainsString($mine->order_number, $csv);
        $this->assertStringNotContainsString($theirs->order_number, $csv);
    }

    public function test_the_detail_route_is_bounded_too(): void
    {
        // The IDOR: order ids are sequential and enumerable.
        $this->clerk();
        $theirs = $this->saleBy(User::factory()->create());

        $this->getJson("/api/v1/admin/orders/{$theirs->id}")->assertNotFound();
    }

    // ── The decided consequence, explained rather than 404'd ─────────────────

    public function test_a_balance_payment_on_a_colleagues_sale_says_who_can_do_it(): void
    {
        $this->clerk();
        $theirs = $this->saleBy(User::factory()->create());
        Payment::factory()->create(['order_id' => $theirs->id, 'amount' => 400, 'status' => 'paid']);

        $this->postJson("/api/v1/admin/pos/pending-order/{$theirs->id}/pay", [
            'payments' => [['method' => 'cash', 'amount' => 600, 'cash_received' => 600]],
        ])
            ->assertForbidden()
            ->assertJsonFragment([
                'message' => 'This sale is not one of yours. A supervisor has to take a payment or process a return against it.',
            ]);
    }

    public function test_a_genuinely_missing_sale_still_says_not_found(): void
    {
        // The explanation must not become a blanket answer that hides a real
        // 404 — "not one of yours" about a sale that does not exist would send
        // someone hunting for a supervisor over a mistyped number.
        $this->clerk();

        $this->postJson('/api/v1/admin/pos/pending-order/999999/pay', [
            'payments' => [['method' => 'cash', 'amount' => 100, 'cash_received' => 100]],
        ])->assertNotFound();
    }

    // ── The escalation path works ────────────────────────────────────────────

    public function test_an_admin_can_still_act_on_any_sale(): void
    {
        // Option A only holds if the escalation target can actually do it.
        $clerkSale = $this->saleBy(User::factory()->create());

        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($admin->fresh());

        $this->getJson("/api/v1/admin/orders/{$clerkSale->id}")->assertOk();
    }
}
