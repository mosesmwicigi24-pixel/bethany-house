<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * What a limited user — the till clerk — may and may not see.
 *
 * Four boundaries, each previously open:
 *  - the invoice book followed orders.view, so any cashier read every
 *    invoice ever served;
 *  - raising a production order granted READING the whole floor
 *    (scopeVisibleTo treated raise_order as a coordinator);
 *  - the customer directory served addresses, credit and balances to
 *    anyone who could attach a walk-in to a sale;
 *  - expenses were all-or-nothing: a clerk either saw the company's
 *    spending or could not record her own.
 *
 * The schedule board is the deliberate exception, tested as such: full
 * floor, lean fields, because a promise made blind to the backlog is the
 * worst promise.
 */
class LimitedClerkAccessTest extends TestCase
{
    use RefreshDatabase;

    /** A role whose data_scope is 'own', granting exactly $permissions. */
    private function ownScopeRole(string $name, array $permissions): Role
    {
        $role = Role::findOrCreate($name, 'sanctum');
        foreach ($permissions as $p) {
            $role->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        DB::table('roles')->where('id', $role->id)->update(['data_scope' => 'own']);

        return $role->fresh();
    }

    private function actAs(User $user): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());
        $this->actingAs($user->fresh());

        return $user;
    }

    private function invoice(string $number, ?User $creator): SalesDocument
    {
        return SalesDocument::create([
            'type'       => SalesDocument::INVOICE,
            'number'     => $number,
            'status'     => 'issued',
            'amount'     => 1000,
            'created_by' => $creator?->id,
        ]);
    }

    // ── Invoices ─────────────────────────────────────────────────────────────

    public function test_a_clerk_sees_only_the_invoices_they_created(): void
    {
        $clerk = User::factory()->create();
        $clerk->assignRole($this->ownScopeRole('till_clerk', ['orders.view']));
        $other = User::factory()->create();

        $mine   = $this->invoice('INV-MINE', $clerk);
        $theirs = $this->invoice('INV-THEIRS', $other);

        $this->actAs($clerk);

        $numbers = collect($this->getJson('/api/v1/admin/invoices')->assertOk()->json('data'))
            ->pluck('invoice_number');
        $this->assertTrue($numbers->contains('INV-MINE'));
        $this->assertFalse($numbers->contains('INV-THEIRS'), 'A clerk can read another user\'s invoice.');

        $this->getJson("/api/v1/admin/invoices/{$mine->id}")->assertOk();
        $this->getJson("/api/v1/admin/invoices/{$theirs->id}")->assertStatus(404);
    }

    public function test_an_all_scope_viewer_keeps_the_whole_invoice_book(): void
    {
        $manager = User::factory()->create();
        $role    = Role::findOrCreate('sales_manager', 'sanctum');
        $role->givePermissionTo(Permission::findOrCreate('orders.view', 'sanctum'));
        $manager->assignRole($role); // data_scope defaults to 'all'

        $this->invoice('INV-A', User::factory()->create());
        $this->invoice('INV-B', User::factory()->create());

        $this->actAs($manager);

        $numbers = collect($this->getJson('/api/v1/admin/invoices')->assertOk()->json('data'))
            ->pluck('invoice_number');
        $this->assertTrue($numbers->contains('INV-A'));
        $this->assertTrue($numbers->contains('INV-B'));
    }

    // ── Production: raising an order is not reading the floor ────────────────

    private function raiser(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('production.view', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('production.raise_order', 'sanctum'));

        return $user;
    }

    /** @return array{0: ProductionOrder, 1: ProductionOrder} mine, not-mine */
    private function twoProductionOrders(User $me): array
    {
        $product = Product::factory()->create();

        $mine = ProductionOrder::create([
            'order_number' => 'PRD-RAISED-BY-ME', 'product_id' => $product->id,
            'quantity' => 1, 'status' => 'in_progress',
            'created_by' => $me->id, 'due_date' => now()->addDays(5),
        ]);
        $other = ProductionOrder::create([
            'order_number' => 'PRD-SOMEONE-ELSES', 'product_id' => $product->id,
            'quantity' => 2, 'status' => 'pending',
            'created_by' => User::factory()->create()->id, 'due_date' => now()->addDays(9),
        ]);

        return [$mine, $other];
    }

    public function test_a_raiser_lists_only_the_production_orders_they_created(): void
    {
        $me = $this->raiser();
        $this->actAs($me);
        [, $other] = $this->twoProductionOrders($me);

        $numbers = collect($this->getJson('/api/v1/admin/production-orders')->assertOk()->json('data'))
            ->pluck('order_number');
        $this->assertTrue($numbers->contains('PRD-RAISED-BY-ME'));
        $this->assertFalse($numbers->contains('PRD-SOMEONE-ELSES'), 'Raising orders still grants reading the whole floor.');

        $this->getJson("/api/v1/admin/production-orders/{$other->id}")->assertStatus(404);
    }

    public function test_the_schedule_board_shows_the_whole_floor_without_money(): void
    {
        $me = $this->raiser();
        $this->actAs($me);
        $this->twoProductionOrders($me);

        $upcoming = collect($this->getJson('/api/v1/admin/production/schedule')->assertOk()->json('upcoming_orders'));

        // Full floor: the board exists so a promise is made against the real
        // backlog, including orders the viewer cannot open.
        $this->assertTrue($upcoming->pluck('order_number')->contains('PRD-SOMEONE-ELSES'));

        // Lean fields: schedule facts, names — never money.
        $row = $upcoming->firstWhere('order_number', 'PRD-SOMEONE-ELSES');
        $this->assertArrayHasKey('product_name', $row);
        $this->assertArrayHasKey('created_by_name', $row);
        $this->assertArrayHasKey('completion_percentage', $row);
        foreach (['estimated_cost', 'actual_cost', 'amount', 'total_amount', 'unit_cost'] as $money) {
            $this->assertArrayNotHasKey($money, $row);
        }
    }

    // ── Customers: picker vs directory ───────────────────────────────────────

    private function customerWithMoneyFields(): Customer
    {
        return Customer::create([
            'first_name'          => 'Grace',
            'last_name'           => 'Wanjiru',
            'phone'               => '0700000001',
            'customer_type'       => 'individual',
            'status'              => 'active',
            'credit_limit'        => 50000,
            'outstanding_balance' => 12000,
            'loyalty_points'      => 40,
            'notes'               => 'VIP — never quote list price',
        ]);
    }

    public function test_customers_view_alone_gets_the_picker_not_the_profile(): void
    {
        $clerk = User::factory()->create();
        $clerk->givePermissionTo(Permission::findOrCreate('customers.view', 'sanctum'));
        $this->actAs($clerk);

        $customer = $this->customerWithMoneyFields();

        $row = collect($this->getJson('/api/v1/admin/customers')->assertOk()->json('data'))->first();
        $this->assertSame('Grace', $row['first_name']);
        $this->assertSame('0700000001', $row['phone']);
        foreach (['credit_limit', 'outstanding_balance', 'loyalty_points', 'notes', 'addresses', 'date_of_birth', 'tax_id'] as $private) {
            $this->assertArrayNotHasKey($private, $row, "customers.view leaks '{$private}'.");
        }

        $show = $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk()->json();
        $this->assertNull($show['stats']);
        $this->assertArrayNotHasKey('credit_limit', $show['customer']);

        $this->getJson("/api/v1/admin/customers/{$customer->id}/orders")->assertStatus(403);
    }

    public function test_customers_insights_unlocks_the_full_profile(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo(Permission::findOrCreate('customers.view', 'sanctum'));
        $manager->givePermissionTo(Permission::findOrCreate('customers.insights', 'sanctum'));
        $this->actAs($manager);

        $customer = $this->customerWithMoneyFields();

        $row = collect($this->getJson('/api/v1/admin/customers')->assertOk()->json('data'))->first();
        $this->assertEquals(50000, $row['credit_limit']);

        $this->getJson("/api/v1/admin/customers/{$customer->id}/orders")->assertOk();
    }

    // ── Expenses: mine, with an unbroken day sequence ────────────────────────

    public function test_a_clerk_sees_only_their_own_expenses_and_the_sequence_never_collides(): void
    {
        $category = ExpenseCategory::create(['name' => 'Office', 'code' => 'OFF']);
        $other    = User::factory()->create();

        // Someone else's expense, recorded outside any session (scope = All).
        $theirs = Expense::create([
            'title' => 'Their airtime', 'category_id' => $category->id,
            'amount' => 500, 'amount_kes' => 500, 'expense_date' => today(),
            'payment_method' => 'cash', 'status' => 'approved',
            'created_by' => $other->id,
        ]);

        $clerk = User::factory()->create();
        $clerk->assignRole($this->ownScopeRole('till_clerk_exp', ['expenses.view', 'expenses.create']));
        $this->actAs($clerk);

        // Recorded under the clerk's narrowed session: the day sequence must
        // still count EVERYONE's rows, or references collide.
        $mine = Expense::create([
            'title' => 'Packaging bags', 'category_id' => $category->id,
            'amount' => 200, 'amount_kes' => 200, 'expense_date' => today(),
            'payment_method' => 'cash', 'status' => 'approved',
            'created_by' => $clerk->id,
        ]);
        $this->assertNotSame($theirs->reference_number, $mine->reference_number);

        $titles = collect($this->getJson('/api/v1/admin/expenses')->assertOk()->json('expenses.data'))
            ->pluck('title');
        $this->assertTrue($titles->contains('Packaging bags'));
        $this->assertFalse($titles->contains('Their airtime'), 'A clerk can read another user\'s expense.');

        $this->getJson("/api/v1/admin/expenses/{$theirs->id}")->assertStatus(404);

        // The summary cards follow the same boundary: her spend, not the shop's.
        $summary = $this->getJson('/api/v1/admin/expenses/summary')->assertOk()->json();
        $this->assertEquals(200, (float) $summary['totals']['total_amount']);
    }
}
