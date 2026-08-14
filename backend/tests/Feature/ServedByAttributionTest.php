<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Who served this.
 *
 * Every order already recorded created_by — it is the column the viewer scope
 * owns an order by — but nothing ever showed it. OrderController::show set
 * cashier_name to a hardcoded null, and the admin had a "Cashier" row that
 * therefore rendered nothing, on every order, forever.
 *
 * The name and the boundary come from the same column on purpose: the orders a
 * cashier can see and the orders that carry their name are the same set, so
 * attribution cannot disagree with scoping.
 */
class ServedByAttributionTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        $this->outlet = Outlet::factory()->create();
    }

    private function staff(string $first, string $last, array $permissions = []): User
    {
        $user = User::factory()->create(['first_name' => $first, 'last_name' => $last]);
        foreach ($permissions as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        $user->outlets()->sync([$this->outlet->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function orderRaisedBy(User $staff): Order
    {
        return Order::factory()->create([
            'created_by' => $staff->id,
            'outlet_id'  => $this->outlet->id,
        ]);
    }

    // ── The order ───────────────────────────────────────────────────────────

    public function test_the_order_says_who_raised_it(): void
    {
        $jackline = $this->staff('Jackline', 'Mwicigi');
        $order    = $this->orderRaisedBy($jackline);

        Sanctum::actingAs($this->staff('Ann', 'Manager', ['orders.view']));

        $this->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.cashier_name', 'Jackline Mwicigi');
    }

    public function test_the_order_list_says_who_raised_each_one(): void
    {
        $jackline = $this->staff('Jackline', 'Mwicigi');
        $peter    = $this->staff('Peter', 'Kamau');
        $a = $this->orderRaisedBy($jackline);
        $b = $this->orderRaisedBy($peter);

        Sanctum::actingAs($this->staff('Ann', 'Manager', ['orders.view']));

        $rows = collect($this->getJson('/api/v1/admin/orders')->assertOk()->json('data'))
            ->keyBy('id');

        $this->assertSame('Jackline Mwicigi', $rows[$a->id]['cashier_name']);
        $this->assertSame('Peter Kamau',      $rows[$b->id]['cashier_name']);
    }

    /**
     * An order raised by nobody — an online checkout, a seeded row — must say
     * nothing rather than inventing a name or breaking the page.
     */
    public function test_an_order_nobody_raised_says_nothing(): void
    {
        $order = Order::factory()->create(['created_by' => null, 'outlet_id' => $this->outlet->id]);

        Sanctum::actingAs($this->staff('Ann', 'Manager', ['orders.view']));

        $this->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.cashier_name', null);
    }

    /**
     * The point of deriving the name from created_by rather than a free-text
     * column: a cashier scoped to their own work sees their own name, and the
     * set of orders they can see is the set that carries it.
     */
    public function test_a_scoped_cashier_sees_their_own_name_on_their_own_orders(): void
    {
        $jackline = $this->staff('Jackline', 'Mwicigi');
        $jackline->assignRole(Role::findByName('pos_clerk', 'sanctum'));
        $mine = $this->orderRaisedBy($jackline);
        $this->orderRaisedBy($this->staff('Peter', 'Kamau'));   // not hers

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($jackline->fresh());

        $rows = $this->getJson('/api/v1/admin/orders')->assertOk()->json('data');

        $this->assertCount(1, $rows, 'the viewer scope still bounds the list');
        $this->assertSame($mine->id, $rows[0]['id']);
        $this->assertSame('Jackline Mwicigi', $rows[0]['cashier_name']);
    }

    // ── The invoice ─────────────────────────────────────────────────────────

    public function test_the_invoice_says_who_issued_it(): void
    {
        $jackline = $this->staff('Jackline', 'Mwicigi');
        $order    = $this->orderRaisedBy($jackline);

        SalesDocument::create([
            'type'              => SalesDocument::INVOICE,
            'number'            => 'INV-0001',
            'documentable_type' => Order::class,
            'documentable_id'   => $order->id,
            'issued_at'         => now(),
            'status'            => 'issued',
            'amount'            => 100.00,
            'currency_code'     => 'KES',
            'created_by'        => $jackline->id,
        ]);

        Sanctum::actingAs($this->staff('Ann', 'Manager', ['orders.view']));

        $this->getJson('/api/v1/admin/invoices')
            ->assertOk()
            ->assertJsonPath('data.0.served_by', 'Jackline Mwicigi');
    }

    /**
     * Documents written before created_by was recorded on them fall back to
     * whoever raised the order, rather than showing a blank.
     */
    public function test_an_invoice_without_its_own_author_falls_back_to_the_order(): void
    {
        $jackline = $this->staff('Jackline', 'Mwicigi');
        $order    = $this->orderRaisedBy($jackline);

        SalesDocument::create([
            'type'              => SalesDocument::INVOICE,
            'number'            => 'INV-0002',
            'documentable_type' => Order::class,
            'documentable_id'   => $order->id,
            'issued_at'         => now(),
            'status'            => 'issued',
            'amount'            => 100.00,
            'currency_code'     => 'KES',
            'created_by'        => null,
        ]);

        Sanctum::actingAs($this->staff('Ann', 'Manager', ['orders.view']));

        $this->getJson('/api/v1/admin/invoices')
            ->assertOk()
            ->assertJsonPath('data.0.served_by', 'Jackline Mwicigi');
    }
}
