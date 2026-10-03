<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InterestCart;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransfer;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductSerial;
use App\Models\Quotation;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\StepsUp;
use Tests\TestCase;

/**
 * Phase 4A, every domain in the scope table: an outlet manager assigned to
 * outlet A sees A's records and none of B's — in the list, by id (404, not
 * 403), and with ?outlet_id=B (a filter inside scope, never a grant).
 *
 * The real catalogue is used (permission:sync), so a role definition that
 * drifts away from the table shows up here.
 */
class OutletScopeDomainsTest extends TestCase
{
    use RefreshDatabase, StepsUp;

    private Outlet $a;
    private Outlet $b;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        $this->a       = Outlet::factory()->create(['name' => 'Outlet A']);
        $this->b       = Outlet::factory()->create(['name' => 'Outlet B']);
        $this->product = Product::factory()->create();
    }

    private function as(string $role, ?Outlet $outlet = null): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName($role, 'sanctum'));
        if ($outlet) {
            $user->outlets()->attach($outlet->id, ['is_primary' => true]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = $user->fresh();
        Sanctum::actingAs($user);
        $this->stepUp($user);   // the orders export is a step-up route (Phase 4C)

        return $user;
    }

    private function om(): User
    {
        return $this->as('outlet_manager', $this->a);
    }

    private function orderAt(Outlet $outlet, array $extra = []): Order
    {
        return Order::factory()->create(array_merge([
            'order_type' => 'pos', 'status' => 'confirmed', 'outlet_id' => $outlet->id,
            'currency_code' => 'KES', 'total_amount' => 1000,
        ], $extra));
    }

    private function ids($response, string $key = 'data'): array
    {
        return collect($response->assertOk()->json($key))->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
    }

    // ── Orders ───────────────────────────────────────────────────────────────

    public function test_orders_list_detail_filter_and_export_are_bounded_to_the_managers_outlets(): void
    {
        $mine   = $this->orderAt($this->a);
        $theirs = $this->orderAt($this->b);
        $this->om();

        $this->assertSame([$mine->id], $this->ids($this->getJson('/api/v1/admin/orders')));
        $this->getJson("/api/v1/admin/orders/{$theirs->id}")->assertNotFound();
        $this->assertSame([], $this->ids($this->getJson("/api/v1/admin/orders?outlet_id={$this->b->id}")),
            'outlet_id=B is a filter inside scope — empty, not a grant');

        $csv = $this->get('/api/v1/admin/orders/export')->assertOk()->getContent();
        $this->assertStringContainsString($mine->order_number, $csv);
        $this->assertStringNotContainsString($theirs->order_number, $csv);
    }

    public function test_invoices_follow_their_orders(): void
    {
        $mine   = $this->orderAt($this->a);
        $theirs = $this->orderAt($this->b);
        $invMine   = SalesDocument::create(['type' => SalesDocument::INVOICE, 'number' => 'INV-A', 'status' => 'issued',
            'amount' => 1000, 'documentable_type' => Order::class, 'documentable_id' => $mine->id]);
        $invTheirs = SalesDocument::create(['type' => SalesDocument::INVOICE, 'number' => 'INV-B', 'status' => 'issued',
            'amount' => 1000, 'documentable_type' => Order::class, 'documentable_id' => $theirs->id]);
        $this->om();

        $this->assertSame([$invMine->id], $this->ids($this->getJson('/api/v1/admin/invoices')));
        $this->getJson("/api/v1/admin/invoices/{$invTheirs->id}")->assertNotFound();
    }

    public function test_quotations_are_bounded_and_a_new_one_lands_at_the_managers_outlet(): void
    {
        $mk = fn (Outlet $o) => Quotation::create(['outlet_id' => $o->id, 'source' => 'admin', 'status' => Quotation::DRAFT,
            'currency_code' => 'KES', 'customer_first_name' => 'Q', 'subtotal' => 1, 'tax_amount' => 0, 'total_amount' => 1]);
        $mine   = $mk($this->a);
        $theirs = $mk($this->b);
        $this->om();

        $this->assertSame([$mine->id], $this->ids($this->getJson('/api/v1/admin/quotations')));
        $this->getJson("/api/v1/admin/quotations/{$theirs->id}")->assertNotFound();

        $this->postJson('/api/v1/admin/quotations', [
            'outlet_id' => $this->b->id, 'customer_first_name' => 'X', 'customer_phone' => '0733418290',
            'items' => [['product_id' => $this->product->id, 'product_name' => 'Cassock', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertForbidden();
    }

    public function test_the_pending_queue_is_the_shops_worklist_for_a_cashier_and_a_manager(): void
    {
        $colleague = User::factory()->create();
        $hereByColleague = $this->orderAt($this->a, ['status' => 'pending', 'payment_status' => 'pending', 'created_by' => $colleague->id]);
        $elsewhere       = $this->orderAt($this->b, ['status' => 'pending', 'payment_status' => 'pending']);

        $this->as('pos_clerk', $this->a);
        $rows = collect($this->getJson('/api/v1/admin/orders/pending-queue')->assertOk()->json('rows'))->pluck('id');
        $this->assertTrue($rows->contains($hereByColleague->id), 'a cashier works her outlet\'s queue, not only her own');
        $this->assertFalse($rows->contains($elsewhere->id));

        $this->om();
        $rows = collect($this->getJson('/api/v1/admin/orders/pending-queue')->assertOk()->json('rows'))->pluck('id');
        $this->assertSame([$hereByColleague->id], $rows->all());
    }

    public function test_interest_carts_are_record_specific_for_a_bounded_caller(): void
    {
        $lead = InterestCart::create(['token' => 'BH-7K2Q', 'channel' => 'whatsapp', 'status' => 'open',
            'name' => 'Lead', 'phone' => '0711000111', 'items' => [], 'subtotal' => 0, 'currency' => 'KES']);
        InterestCart::create(['token' => 'BH-9Z9Z', 'channel' => 'web', 'status' => 'open',
            'name' => 'Other', 'phone' => '0711000222', 'items' => [], 'subtotal' => 0, 'currency' => 'KES']);
        $this->om();

        $this->assertSame([], $this->ids($this->getJson('/api/v1/admin/interest-carts')), 'no browsable lead list');
        $this->assertSame([], $this->ids($this->getJson('/api/v1/admin/interest-carts?q=Lead')), 'no name search');
        $this->assertSame([$lead->id], $this->ids($this->getJson('/api/v1/admin/interest-carts?q=bh-7k2q')),
            'the token the customer hands over opens their cart');
    }

    // ── Payments ─────────────────────────────────────────────────────────────

    public function test_payments_awaiting_approval_are_bounded(): void
    {
        $mine   = Payment::factory()->create(['order_id' => $this->orderAt($this->a)->id, 'requires_approval' => true, 'approval_status' => 'pending_review']);
        $theirs = Payment::factory()->create(['order_id' => $this->orderAt($this->b)->id, 'requires_approval' => true, 'approval_status' => 'pending_review']);
        $this->om();

        $res = $this->getJson('/api/v1/admin/payments/pending-approval')->assertOk();
        $this->assertSame([$mine->id], collect($res->json('data'))->pluck('id')->all());
        $this->assertSame(1, $res->json('pending_count'));
    }

    // ── Inventory ────────────────────────────────────────────────────────────

    public function test_stock_levels_adjustments_and_serials_are_bounded(): void
    {
        $itemA = InventoryItem::create(['product_id' => $this->product->id, 'outlet_id' => $this->a->id, 'quantity_on_hand' => 5, 'quantity_reserved' => 0, 'reorder_point' => 0]);
        $itemB = InventoryItem::create(['product_id' => $this->product->id, 'outlet_id' => $this->b->id, 'quantity_on_hand' => 9, 'quantity_reserved' => 0, 'reorder_point' => 0]);
        $adjA = InventoryTransaction::create(['inventory_item_id' => $itemA->id, 'transaction_type' => 'damaged', 'quantity_change' => -1, 'quantity_before' => 6, 'quantity_after' => 5, 'status' => 'approved']);
        $adjB = InventoryTransaction::create(['inventory_item_id' => $itemB->id, 'transaction_type' => 'damaged', 'quantity_change' => -1, 'quantity_before' => 10, 'quantity_after' => 9, 'status' => 'approved']);
        $serA = ProductSerial::create(['serial_number' => 'SN-A', 'product_id' => $this->product->id, 'outlet_id' => $this->a->id, 'status' => 'in_stock']);
        $serB = ProductSerial::create(['serial_number' => 'SN-B', 'product_id' => $this->product->id, 'outlet_id' => $this->b->id, 'status' => 'in_stock']);
        $this->om();

        $levels = $this->getJson('/api/v1/admin/inventory/stock-levels')->assertOk();
        $this->assertSame([$itemA->id], collect($levels->json('data'))->pluck('id')->all());
        $this->assertSame(1, $levels->json('stats.total_skus'), 'the cards count the manager\'s shop');
        $this->getJson("/api/v1/admin/inventory/stock-levels/{$itemB->id}")->assertNotFound();
        $this->assertSame([], collect($this->getJson("/api/v1/admin/inventory/stock-levels?outlet_id={$this->b->id}")->json('data'))->all());

        $this->assertSame([$adjA->id], collect($this->getJson('/api/v1/admin/inventory/adjustments')->assertOk()->json('data'))->pluck('id')->all());
        $this->getJson("/api/v1/admin/inventory/adjustments/{$adjB->id}")->assertNotFound();

        $this->assertSame([$serA->id], collect($this->getJson('/api/v1/admin/product-serials')->assertOk()->json('data'))->pluck('id')->all());
        $this->getJson("/api/v1/admin/product-serials/{$serB->id}")->assertNotFound();
    }

    public function test_a_transfer_is_in_scope_when_either_side_is(): void
    {
        $c = Outlet::factory()->create();
        $mk = fn (Outlet $from, Outlet $to) => InventoryTransfer::create([
            'transfer_number' => 'TR-' . $from->id . '-' . $to->id, 'from_outlet_id' => $from->id,
            'to_outlet_id' => $to->id, 'status' => 'pending', 'transfer_date' => now()->toDateString()]);
        $outbound  = $mk($this->a, $this->b);
        $inbound   = $mk($this->b, $this->a);
        $elsewhere = $mk($this->b, $c);
        $this->om();

        $ids = collect($this->getJson('/api/v1/admin/inventory/transfers')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$outbound->id, $inbound->id], $ids);
        $this->getJson("/api/v1/admin/inventory/transfers/{$elsewhere->id}")->assertNotFound();
    }

    // ── Production ───────────────────────────────────────────────────────────

    public function test_production_orders_raised_at_the_managers_outlet_only(): void
    {
        $mk = fn (string $n, Outlet $o) => ProductionOrder::create(['order_number' => $n, 'product_id' => $this->product->id,
            'quantity' => 1, 'status' => 'pending', 'outlet_id' => $o->id]);
        $mine   = $mk('PRD-A', $this->a);
        $theirs = $mk('PRD-B', $this->b);
        $this->om();

        $ids = collect($this->getJson('/api/v1/admin/production-orders')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
        $this->getJson("/api/v1/admin/production-orders/{$theirs->id}")->assertNotFound();
    }

    // ── Customers ────────────────────────────────────────────────────────────

    private function customer(string $first, ?Outlet $createdAt = null): Customer
    {
        $c = Customer::create(['first_name' => $first, 'last_name' => 'Test', 'phone' => '07' . random_int(10000000, 99999999), 'status' => 'active']);
        if ($createdAt) {
            $c->forceFill(['created_outlet_id' => $createdAt->id])->save();
        }

        return $c;
    }

    public function test_a_managers_customers_are_those_with_activity_or_created_at_their_outlets(): void
    {
        $boughtHere = $this->customer('Boughthere');
        $this->orderAt($this->a, ['customer_id' => $boughtHere->id]);
        $createdHere = $this->customer('Createdhere', $this->a);
        $elsewhere   = $this->customer('Elsewhere');
        $this->orderAt($this->b, ['customer_id' => $elsewhere->id]);
        $this->om();

        $this->assertSame([$boughtHere->id, $createdHere->id], $this->ids($this->getJson('/api/v1/admin/customers')));
        $this->getJson("/api/v1/admin/customers/{$elsewhere->id}")->assertNotFound();
        $this->putJson("/api/v1/admin/customers/{$elsewhere->id}", ['first_name' => 'Hacked'])->assertNotFound();
        $this->assertSame([], $this->ids($this->getJson('/api/v1/admin/customers?search=Elsewhere')),
            'scoped BEFORE matching: a search does not reach across outlets');
    }

    public function test_a_customer_a_manager_creates_is_stamped_with_their_outlet(): void
    {
        $this->om();

        $id = $this->postJson('/api/v1/admin/customers', ['first_name' => 'New', 'last_name' => 'Walkin', 'phone' => '0722333444'])
            ->assertCreated()->json('customer.id');

        $this->assertSame($this->a->id, (int) DB::table('customers')->where('id', $id)->value('created_outlet_id'));
        $this->getJson("/api/v1/admin/customers/{$id}")->assertOk();
    }

    public function test_a_cashier_opens_only_the_customer_on_her_open_sale(): void
    {
        $clerk   = $this->as('pos_clerk', $this->a);
        $onSale  = $this->customer('Onsale');
        $settled = $this->customer('Settled');
        $other   = $this->customer('Other');
        $this->orderAt($this->a, ['customer_id' => $onSale->id, 'created_by' => $clerk->id, 'status' => 'pending', 'payment_status' => 'pending']);
        $this->orderAt($this->a, ['customer_id' => $settled->id, 'created_by' => $clerk->id, 'status' => 'completed', 'payment_status' => 'paid']);

        $this->assertSame([$onSale->id], $this->ids($this->getJson('/api/v1/admin/customers')));
        $this->getJson("/api/v1/admin/customers/{$onSale->id}")->assertOk();
        $this->getJson("/api/v1/admin/customers/{$settled->id}")->assertNotFound();
        $this->getJson("/api/v1/admin/customers/{$other->id}")->assertNotFound();
    }

    // ── Shipments ────────────────────────────────────────────────────────────

    public function test_shipments_follow_their_orders(): void
    {
        $mk = fn (Order $o) => DB::table('order_shipments')->insertGetId(['order_id' => $o->id, 'shipment_number' => 'SH-' . $o->id,
            'status' => 'processing', 'created_at' => now(), 'updated_at' => now()]);
        $mine   = $mk($this->orderAt($this->a));
        $theirs = $mk($this->orderAt($this->b));
        $this->om();

        $this->assertSame([$mine], $this->ids($this->getJson('/api/v1/admin/shipments')));
        $this->getJson("/api/v1/admin/shipments/{$theirs}")->assertNotFound();
    }

    // ── The unbounded roles are unchanged ────────────────────────────────────

    public function test_admin_and_finance_still_see_every_outlet(): void
    {
        $this->orderAt($this->a);
        $this->orderAt($this->b);

        foreach (['admin', 'finance_manager', 'accountant'] as $role) {
            $this->as($role);
            $this->assertCount(2, $this->getJson('/api/v1/admin/orders')->assertOk()->json('data'), $role);
        }
    }

    public function test_procurement_sees_no_orders(): void
    {
        $this->orderAt($this->a);
        $this->as('procurement_officer');

        $this->getJson('/api/v1/admin/orders')->assertForbidden();
        $this->assertSame([], collect($this->getJson('/api/v1/admin/search?q=POS&types[]=orders')->assertOk()->json('results'))->all());
    }
}
