<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A customer's organisation belongs in its own field.
 *
 * 2026-09-25: a cashier had nowhere to record that Boniface was buying for
 * Cooperative Bank of Kenya, so she typed the bank into the phone box. It broke
 * the order (too long for the column), and had it fit it would have been worse:
 * phone is what the hub and Neema match a customer on across channels, so the
 * bank's name would have become his phone number forever.
 *
 * `customers.company` already existed and the Customers page already wrote it.
 * What had no field was every place staff meet a customer in the moment — the
 * POS panel, the order screen, the quick-create. These pin that each of those
 * paths carries it, and that the field behaves at its edges.
 */
class CustomerCompanyTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;
    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create();
        foreach (['pos.access', 'orders.view', 'orders.edit', 'customers.view', 'customers.create'] as $p) {
            $this->staff->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($this->staff);
        $this->outlet = Outlet::factory()->create(['country_code' => 'KE']);
        $this->staff->outlets()->sync([$this->outlet->id]);

        DB::table('currencies')->updateOrInsert(
            ['code' => 'KES'],
            ['name' => 'KES', 'symbol' => 'KES', 'exchange_rate' => 1, 'is_base' => true,
             'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        );
        \App\Services\CurrencyPricing::forget();
    }

    /** A stocked product to put on the order. */
    private function product(float $price = 3000): Product
    {
        $product = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        ProductPrice::create([
            'product_id' => $product->id, 'product_variant_id' => null,
            'currency_code' => 'KES', 'regular_price' => $price,
        ]);
        InventoryItem::create([
            'product_id' => $product->id, 'product_variant_id' => null,
            'outlet_id' => $this->outlet->id, 'quantity_on_hand' => 20,
            'quantity_reserved' => 0, 'reorder_point' => 0,
        ]);

        return $product;
    }

    /** The POS two-step: a pending order carrying a brand-new customer. */
    private function pendingOrderFor(array $newCustomer)
    {
        return $this->postJson('/api/v1/admin/pos/pending-order', [
            'outlet_id'         => $this->outlet->id,
            'channel'           => 'pos',
            'client_request_id' => 'req-' . bin2hex(random_bytes(6)),
            'new_customer'      => $newCustomer,
            'items'             => [[
                'product_id' => $this->product()->id, 'quantity' => 1, 'unit_price' => 3000,
            ]],
        ]);
    }

    private function quickCreate(array $overrides = [])
    {
        return $this->postJson('/api/v1/admin/customers/quick-create', array_merge([
            'first_name' => 'Boniface',
            'last_name'  => 'Karanja',
            'phone'      => '+254727891989',
            'company'    => 'Cooperative Bank of Kenya',
        ], $overrides));
    }

    // ── the field carries, on the paths staff actually use ──────────────────

    public function test_quick_create_records_the_company(): void
    {
        $res = $this->quickCreate()->assertSuccessful();

        $this->assertDatabaseHas('customers', [
            'first_name' => 'Boniface',
            'phone'      => '+254727891989',
            'company'    => 'Cooperative Bank of Kenya',
        ]);
        $this->assertNotNull($res->json('customer.id') ?? $res->json('data.id') ?? $res->json('id'));
    }

    public function test_the_customers_page_still_records_it_as_before(): void
    {
        $this->postJson('/api/v1/admin/customers', [
            'first_name' => 'Grace', 'last_name' => 'Wanjiru',
            'email' => 'grace@example.test', 'phone' => '0722000111',
            'type' => 'business', 'company_name' => 'St Mary Parish',
        ])->assertSuccessful();

        $this->assertDatabaseHas('customers', ['first_name' => 'Grace', 'company' => 'St Mary Parish']);
    }

    public function test_the_pos_carries_the_company_onto_the_new_customer(): void
    {
        // The exact situation from 2026-09-25, with a field to put the bank in.
        $res = $this->pendingOrderFor([
            'first_name' => 'Boniface',
            'phone'      => '+254727891989',
            'company'    => 'Cooperative Bank of Kenya',
        ])->assertStatus(201);

        $order = Order::findOrFail($res->json('order_id'));

        // The order must point at the customer it created — mass assignment used
        // to drop customer_id, so the sale and the buyer never met again.
        $this->assertNotNull($order->customer_id, 'the new customer must be linked to the order');
        $customer = Customer::findOrFail($order->customer_id);

        $this->assertSame('Cooperative Bank of Kenya', $customer->company);
        $this->assertSame('+254727891989', $customer->phone, 'the bank must not end up in the phone');
    }

    public function test_the_pos_still_works_with_no_company_given(): void
    {
        $res = $this->pendingOrderFor(['first_name' => 'Walk', 'phone' => '0700000040'])->assertStatus(201);

        $order = Order::findOrFail($res->json('order_id'));
        $this->assertNotNull($order->customer_id);
        $this->assertNull(Customer::findOrFail($order->customer_id)->company);
    }

    public function test_the_pos_refuses_a_company_longer_than_the_column(): void
    {
        $this->pendingOrderFor([
            'first_name' => 'Boniface', 'phone' => '0700000041', 'company' => str_repeat('A', 256),
        ])->assertStatus(422)->assertJsonValidationErrors(['new_customer.company']);
    }

    public function test_the_order_screen_carries_the_company_too(): void
    {
        // A walk-in sale starts with no customer details — details are captured
        // once and then frozen, so this is the only state the screen can attach to.
        $order = Order::findOrFail($this->postJson('/api/v1/admin/pos/pending-order', [
            'outlet_id'         => $this->outlet->id,
            'channel'           => 'pos',
            'client_request_id' => 'req-' . bin2hex(random_bytes(6)),
            'items'             => [['product_id' => $this->product()->id, 'quantity' => 1, 'unit_price' => 3000]],
        ])->assertStatus(201)->json('order_id'));

        $this->postJson("/api/v1/admin/orders/{$order->id}/attach-customer", [
            'new_customer' => [
                'first_name' => 'Boniface', 'last_name' => 'Karanja',
                'phone' => '0700000043', 'company' => 'St Andrew Cathedral',
            ],
        ])->assertSuccessful();

        $this->assertDatabaseHas('customers', ['phone' => '0700000043', 'company' => 'St Andrew Cathedral']);
    }

    // ── the edges ───────────────────────────────────────────────────────────

    public function test_company_is_optional_everywhere(): void
    {
        $this->quickCreate(['company' => null, 'phone' => '0700000001'])->assertSuccessful();
        $this->assertDatabaseHas('customers', ['phone' => '0700000001', 'company' => null]);

        $payload = ['first_name' => 'No', 'last_name' => 'Company', 'phone' => '0700000002'];
        $this->postJson('/api/v1/admin/customers/quick-create', $payload)->assertSuccessful();
        $this->assertDatabaseHas('customers', ['phone' => '0700000002', 'company' => null]);
    }

    public function test_a_company_at_the_limit_is_kept_and_one_past_it_is_refused(): void
    {
        $this->quickCreate(['company' => str_repeat('A', 255), 'phone' => '0700000003'])->assertSuccessful();
        $this->assertDatabaseHas('customers', ['phone' => '0700000003', 'company' => str_repeat('A', 255)]);

        $this->quickCreate(['company' => str_repeat('A', 256), 'phone' => '0700000004'])
            ->assertStatus(422)->assertJsonValidationErrors(['company']);
    }

    public function test_surrounding_whitespace_does_not_become_part_of_the_name(): void
    {
        $this->quickCreate(['company' => '  Cooperative Bank of Kenya  ', 'phone' => '0700000005'])->assertSuccessful();

        $this->assertDatabaseHas('customers', ['phone' => '0700000005', 'company' => 'Cooperative Bank of Kenya']);
    }

    public function test_names_that_are_not_plain_ascii_survive(): void
    {
        foreach ([
            ['Église Saint-Paul', '0700000010'],
            ['Kanisa la Mungu — Nairobi', '0700000011'],
            ["St Mary's & Sons (K) Ltd.", '0700000012'],
        ] as [$company, $phone]) {
            $this->quickCreate(['company' => $company, 'phone' => $phone])->assertSuccessful();
            $this->assertDatabaseHas('customers', ['phone' => $phone, 'company' => $company]);
        }
    }

    public function test_a_company_is_recorded_as_typed_and_not_treated_as_markup(): void
    {
        // Stored verbatim — escaping belongs to whatever renders it, and a name
        // with an ampersand or a quote is ordinary in this trade.
        $company = '<b>Bank</b> & "Co"';
        $this->quickCreate(['company' => $company, 'phone' => '0700000013'])->assertSuccessful();

        $this->assertSame($company, DB::table('customers')->where('phone', '0700000013')->value('company'));
    }

    public function test_two_customers_may_share_a_company(): void
    {
        $this->quickCreate(['phone' => '0700000020'])->assertSuccessful();
        $this->quickCreate(['first_name' => 'Mary', 'phone' => '0700000021'])->assertSuccessful();

        $this->assertSame(2, Customer::where('company', 'Cooperative Bank of Kenya')->count());
    }

    // ── the point of the field: the phone stays a phone ─────────────────────

    public function test_the_company_does_not_land_in_the_phone(): void
    {
        $this->quickCreate(['phone' => '+254727891989'])->assertSuccessful();

        $row = DB::table('customers')->where('company', 'Cooperative Bank of Kenya')->first();
        $this->assertSame('+254727891989', $row->phone, 'the phone field must still hold a phone number');
        $this->assertStringNotContainsString('Bank', (string) $row->phone);
    }

    public function test_a_customer_can_be_found_by_their_company(): void
    {
        $this->quickCreate()->assertSuccessful();

        $hits = $this->getJson('/api/v1/admin/search?q=Cooperative')->assertOk()->json('results');

        $this->assertNotEmpty($hits, 'searching the company name should find the customer');
        $this->assertSame('Boniface Karanja', $hits[0]['title']);
        // And it says WHY it matched — a name plus an email address explains
        // nothing to someone who searched for a bank.
        $this->assertStringContainsString('Cooperative Bank of Kenya', $hits[0]['subtitle']);
    }

    public function test_the_company_comes_back_on_the_customer_payload(): void
    {
        $id = Customer::create([
            'first_name' => 'Boniface', 'last_name' => 'K', 'phone' => '0700000030',
            'email' => 'b@example.test', 'company' => 'Cooperative Bank of Kenya',
        ])->id;

        $this->getJson("/api/v1/admin/customers/{$id}")->assertOk()
            ->assertJsonFragment(['company' => 'Cooperative Bank of Kenya']);
    }

    // ── and the phone itself, now that the column is 32 ─────────────────────

    public function test_a_phone_up_to_the_column_width_is_accepted(): void
    {
        // Two numbers in one field is normal here, and 27 characters used to be
        // refused by a max:20 rule against a column that is now 32.
        $this->quickCreate(['phone' => '0722 000 000 / 0733 111 111', 'company' => null])->assertSuccessful();

        $this->assertDatabaseHas('customers', ['phone' => '0722 000 000 / 0733 111 111']);
    }
}
