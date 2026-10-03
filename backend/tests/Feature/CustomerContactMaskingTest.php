<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Quotation;
use App\Models\User;
use App\Support\CustomerContacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 4A masking (plan §9): what a role's browser receives of a customer's
 * phone, email and delivery address.
 *
 *   phone/email   unmasked: super_admin, admin, finance_manager, outlet_manager
 *                 masked (07••••1853, n•••82@gmail.com): accountant, pos_clerk
 *                 omitted: procurement, tailor, system_admin
 *   address       masked for finance_manager and pos_clerk (and accountant)
 *
 * In the serializer (the response), never in the browser: the raw value must
 * not be anywhere in a masked role's JSON or CSV. One rulebook —
 * App\Support\CustomerContacts — resolved per user, strictest mask among the
 * roles that grant the read.
 */
class CustomerContactMaskingTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE   = '0712341853';
    private const EMAIL   = 'nancy82@gmail.com';
    private const ADDRESS = '14 Ngong Road, Kilimani';

    private Outlet $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        $this->shop = Outlet::factory()->create();
    }

    private function as(string ...$roles): User
    {
        $user = User::factory()->create();
        foreach ($roles as $r) {
            $user->assignRole(Role::findByName($r, 'sanctum'));
        }
        $user->outlets()->attach($this->shop->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = $user->fresh();
        Sanctum::actingAs($user);

        return $user;
    }

    private function order(array $extra = []): Order
    {
        return Order::factory()->create(array_merge([
            'order_type' => 'pos', 'status' => 'pending', 'payment_status' => 'pending',
            'outlet_id' => $this->shop->id, 'currency_code' => 'KES', 'total_amount' => 1000,
            'customer_first_name' => 'Nancy', 'customer_last_name' => 'Wanjiru',
            'customer_phone' => self::PHONE, 'customer_email' => self::EMAIL,
            'shipping_address_line1' => self::ADDRESS, 'shipping_city' => 'Nairobi',
            'notes' => 'Call her on ' . self::PHONE . ' before delivery',
        ], $extra));
    }

    // ── The formats ──────────────────────────────────────────────────────────

    public function test_the_mask_formats_are_the_plans(): void
    {
        $this->assertSame('07••••1853', CustomerContacts::maskPhone('0712341853'));
        $this->assertSame('n•••82@gmail.com', CustomerContacts::maskEmail('nancy82@gmail.com'));
        $this->assertSame('+2••••1853', CustomerContacts::maskPhone('+254712341853'));
        $this->assertTrue(CustomerContacts::isMasked('07••••1853'));
        $this->assertFalse(CustomerContacts::isMasked(self::PHONE));
    }

    // ── The policy, per role ─────────────────────────────────────────────────

    public function test_the_policy_matrix_resolves_per_user(): void
    {
        $cases = [
            'admin'               => ['full', 'full'],
            'finance_manager'     => ['full', 'masked'],
            'outlet_manager'      => ['full', 'full'],
            'accountant'          => ['masked', 'masked'],
            'pos_clerk'           => ['masked', 'masked'],
            'procurement_manager' => ['omitted', 'omitted'],
            'tailor'              => ['omitted', 'omitted'],
            'system_admin'        => ['omitted', 'omitted'],
        ];
        foreach ($cases as $role => [$contacts, $address]) {
            $user   = $this->as($role);
            $policy = CustomerContacts::policyFor($user, 'orders.view');
            $this->assertSame($contacts, $policy['contacts'], "{$role} contacts");
            $this->assertSame($address, $policy['address'], "{$role} address");
        }
    }

    public function test_the_strictest_mask_among_the_granting_roles_wins(): void
    {
        // Both grant orders.view: accountant's mask is the stricter.
        $user = $this->as('finance_manager', 'accountant');
        $this->assertSame('masked', CustomerContacts::policyFor($user, 'orders.view')['contacts']);

        // procurement_officer grants no orders.view, so it does not get a say.
        $user = $this->as('outlet_manager', 'procurement_officer');
        $this->assertSame('full', CustomerContacts::policyFor($user, 'orders.view')['contacts']);
    }

    // ── Orders: list, detail, export ─────────────────────────────────────────

    public function test_an_accountant_never_receives_the_raw_contacts(): void
    {
        $order = $this->order();
        $this->as('accountant');

        foreach (['/api/v1/admin/orders', "/api/v1/admin/orders/{$order->id}", '/api/v1/admin/orders/pending-queue'] as $url) {
            $body = $this->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString(self::PHONE, $body, $url);
            $this->assertStringNotContainsString(self::EMAIL, $body, $url);
            $this->assertStringNotContainsString(self::ADDRESS, $body, $url);
        }

        $detail = $this->getJson("/api/v1/admin/orders/{$order->id}")->json('order');
        $this->assertSame('07••••1853', $detail['customer_phone']);
        $this->assertSame('n•••82@gmail.com', $detail['customer_email']);
        $this->assertStringContainsString('07••••1853', $detail['notes'], 'free text is masked too');

        $csv = $this->get('/api/v1/admin/orders/export')->assertOk()->getContent();
        $this->assertStringNotContainsString(self::PHONE, $csv);
        $this->assertStringNotContainsString(self::EMAIL, $csv);
        $this->assertStringContainsString('07••••1853', $csv);
    }

    public function test_finance_sees_contacts_but_not_the_delivery_address(): void
    {
        $order = $this->order();
        $this->as('finance_manager');

        $detail = $this->getJson("/api/v1/admin/orders/{$order->id}")->assertOk()->json('order');
        $this->assertSame(self::PHONE, $detail['customer_phone']);
        $this->assertSame(self::EMAIL, $detail['customer_email']);
        $this->assertNotSame(self::ADDRESS, $detail['shipping_address_line1']);
        $this->assertSame('Nairobi', $detail['shipping_city'], 'the city stays — it is where, not who');
    }

    public function test_an_outlet_manager_and_admin_see_everything_at_their_outlets(): void
    {
        $order = $this->order();
        foreach (['outlet_manager', 'admin'] as $role) {
            $this->as($role);
            $detail = $this->getJson("/api/v1/admin/orders/{$order->id}")->assertOk()->json('order');
            $this->assertSame(self::PHONE, $detail['customer_phone'], $role);
            $this->assertSame(self::ADDRESS, $detail['shipping_address_line1'], $role);
        }
    }

    // ── Customers ────────────────────────────────────────────────────────────

    public function test_a_cashier_sees_the_customer_on_her_sale_masked(): void
    {
        $clerk    = $this->as('pos_clerk');
        $customer = Customer::create(['first_name' => 'Nancy', 'last_name' => 'Wanjiru', 'phone' => self::PHONE, 'email' => self::EMAIL]);
        $this->order(['customer_id' => $customer->id, 'created_by' => $clerk->id]);

        $body = $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk();
        $this->assertSame('07••••1853', $body->json('customer.phone'));
        $this->assertStringNotContainsString(self::PHONE, $body->getContent());

        $list = $this->getJson('/api/v1/admin/customers')->assertOk()->getContent();
        $this->assertStringNotContainsString(self::EMAIL, $list);
    }

    // ── Quotations and shipments ─────────────────────────────────────────────

    public function test_a_cashier_reads_her_quotation_masked(): void
    {
        $clerk = $this->as('pos_clerk');
        $q = Quotation::create(['outlet_id' => $this->shop->id, 'source' => 'admin', 'status' => Quotation::DRAFT,
            'currency_code' => 'KES', 'customer_first_name' => 'Nancy', 'customer_phone' => self::PHONE,
            'customer_email' => self::EMAIL, 'subtotal' => 1, 'tax_amount' => 0, 'total_amount' => 1, 'created_by' => $clerk->id]);

        $body = $this->getJson("/api/v1/admin/quotations/{$q->id}")->assertOk();
        $this->assertSame('07••••1853', $body->json('quotation.customer_phone'));
        $this->assertStringNotContainsString(self::EMAIL, $body->getContent());
    }

    public function test_a_cashier_saving_her_quotation_keeps_the_number_on_file(): void
    {
        // The edit form is filled from what she was served — the masked
        // number. Sending it back must not overwrite the real one, nor fail.
        $clerk = $this->as('pos_clerk');
        $q = Quotation::create(['outlet_id' => $this->shop->id, 'source' => 'admin', 'status' => Quotation::DRAFT,
            'currency_code' => 'KES', 'customer_first_name' => 'Nancy', 'customer_phone' => self::PHONE,
            'customer_email' => self::EMAIL, 'subtotal' => 1, 'tax_amount' => 0, 'total_amount' => 1, 'created_by' => $clerk->id]);

        $this->putJson("/api/v1/admin/quotations/{$q->id}", [
            'customer_first_name' => 'Nancy', 'customer_phone' => '07••••1853', 'customer_email' => 'n•••82@gmail.com',
            'items' => [['product_name' => 'Cassock', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertOk();

        $this->assertSame(self::PHONE, $q->fresh()->customer_phone);
        $this->assertSame(self::EMAIL, $q->fresh()->customer_email);
    }

    // ── Omitted roles ────────────────────────────────────────────────────────

    public function test_an_omitted_role_gets_null_not_a_mask(): void
    {
        $data = CustomerContacts::apply(
            ['customer_phone' => self::PHONE, 'notes' => 'ring ' . self::PHONE, 'shipping_address_line1' => self::ADDRESS],
            ['contacts' => 'omitted', 'address' => 'omitted'],
        );

        $this->assertNull($data['customer_phone']);
        $this->assertNull($data['shipping_address_line1']);
        $this->assertStringNotContainsString(self::PHONE, $data['notes']);
    }
}
