<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use App\Rules\CustomerPhone;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A customer's phone is a real number or nothing — at every place staff type
 * one. The values below are the shapes found in production on 2026-10-01.
 *
 * Endpoint checks are validation-only: a rejection must name the phone; an
 * acceptance sends a valid phone beside a deliberately missing required field,
 * so validation stops the request before anything is written, and the phone
 * must not be among the errors.
 */
class CustomerPhoneEntryTest extends TestCase
{
    use RefreshDatabase;

    private const REAL = [
        '0722 123 456', '0722123456', '+254 722 123 456', '254722123456', '722123456', '0110 123 456',
        '0202 123 456', '+256 772 123 456', '+44 7401 182 700', '0044 7401 182700', '+1 (415) 555-0134', '+263 77 123 4567',
        '0723 456 789', '0101 234 567', '0722 000 000 / 0733 111 111', '0722 123 456, +256 772 123 456',
    ];

    private const NOT_PHONES = [
        'cash' => 'note', 'refer to iand m' => 'note', 'ATC Measurements' => 'note', 'MPESA REF 123456789' => 'note',
        '0722#123456' => 'only contain', '68252933' => 'not a full', '072295583' => 'not a full', '123456789' => 'not a full',
        '25468769102' => '9 digits after the 0', '+25400000000' => '9 digits after the 0', '0700000000' => 'placeholder',
        '0711111111' => 'placeholder', '0712345678' => 'placeholder', '+2547123456789012' => 'too long',
        '0722 123 456 / 07229' => '07229: That is not a full', '0722123456/0733123456/0711234567/0720123456' => 'up to three',
    ];

    private function check(?string $value, array $onFile = []): ?string
    {
        $v = Validator::make(['phone' => $value], ['phone' => [new CustomerPhone($onFile)]]);

        return $v->fails() ? $v->errors()->first('phone') : null;
    }

    public function test_real_numbers_in_every_shape_staff_type_them_pass(): void
    {
        foreach (self::REAL as $phone) {
            $this->assertNull($this->check($phone), "{$phone} is a real number");
        }
        $this->assertNull($this->check(null));
        $this->assertNull($this->check('   '), 'empty is allowed; required decides');
    }

    public function test_notes_fragments_and_placeholders_are_refused_with_a_reason(): void
    {
        foreach (self::NOT_PHONES as $value => $reason) {
            $msg = $this->check($value);
            $this->assertNotNull($msg, "{$value} must be refused");
            $this->assertStringContainsStringIgnoringCase($reason, $msg, "{$value}: {$msg}");
        }
    }

    public function test_the_example_numbers_in_the_messages_are_themselves_accepted(): void
    {
        foreach (self::NOT_PHONES as $value => $_) {
            preg_match_all('/(\+?\d[\d ]{8,}\d)/', $this->check($value), $m);
            foreach ($m[1] as $example) {
                $this->assertNull($this->check($example), "the message suggests {$example}, so it must pass");
            }
        }
    }

    public function test_a_value_already_on_file_may_be_resent_but_not_retyped_differently(): void
    {
        $this->assertNull($this->check('refer to iand m', ['refer to iand m']), 'the stored legacy note, resent');
        $this->assertNotNull($this->check('cash', ['refer to iand m']), 'a different note is newly typed');
    }

    /** The write-time rule must read a number exactly as the reports do. */
    public function test_php_reads_every_shape_exactly_as_the_sql_function_does(): void
    {
        $shapes = array_merge(self::REAL, array_keys(self::NOT_PHONES), ['0044 7401 182700', '00256772123456', '+254 (0) 722 123456', ' 0722123456 ', '01234567890']);
        foreach ($shapes as $raw) {
            $sql = DB::selectOne('SELECT normalize_phone(?) AS v', [$raw])->v;
            $this->assertSame($sql, Phone::e164($raw), "PHP and SQL disagree on '{$raw}'");
        }
    }

    // ── Every staff entry point ──────────────────────────────────────────────

    private function staff(array $perms, bool $admin = false): User
    {
        $u = User::factory()->create();
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        if ($admin) {
            $u->assignRole(Role::findOrCreate('admin', 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u);

        return $u;
    }

    public function test_the_till_refuses_a_note_and_accepts_a_number_on_every_sale_path(): void
    {
        $this->staff(['pos.access']);
        foreach (['/api/v1/admin/pos/sales', '/api/v1/admin/pos/pending-order'] as $url) {
            $this->postJson($url, ['customer_phone' => 'cash'])->assertStatus(422)->assertJsonValidationErrors('customer_phone');
            $this->postJson($url, ['new_customer' => ['first_name' => 'Ann', 'phone' => 'refer to iand m']])
                ->assertStatus(422)->assertJsonValidationErrors('new_customer.phone');
            // outlet_id missing on purpose: validation stops here, nothing is written.
            $this->postJson($url, ['customer_phone' => '0722 123 456'])->assertStatus(422)
                ->assertJsonValidationErrors('outlet_id')->assertJsonMissingValidationErrors('customer_phone');
        }
    }

    public function test_a_sale_to_a_customer_whose_record_holds_a_note_is_never_blocked(): void
    {
        $this->staff(['pos.access']);
        $legacy = Customer::create(['customer_number' => 'CP-1', 'first_name' => 'Ben', 'last_name' => 'K',
            'email' => 'cp1@example.test', 'phone' => 'refer to iand m']);

        $this->postJson('/api/v1/admin/pos/sales', ['customer_id' => $legacy->id, 'customer_phone' => 'refer to iand m'])
            ->assertStatus(422)->assertJsonMissingValidationErrors('customer_phone');
    }

    public function test_editing_a_pending_order_keeps_its_legacy_phone_but_refuses_a_new_note(): void
    {
        $this->staff(['pos.access'], admin: true);
        $outlet = Outlet::factory()->create();
        $order = Order::create(['order_number' => 'CP-PEND', 'order_type' => 'pos', 'status' => 'pending', 'payment_status' => 'pending',
            'currency_code' => 'KES', 'subtotal' => 100, 'total_amount' => 100, 'outlet_id' => $outlet->id, 'customer_phone' => 'cash']);

        $this->patchJson("/api/v1/admin/pos/pending-order/{$order->id}", ['customer_phone' => 'cash', 'customer_email' => 'not-an-email'])
            ->assertStatus(422)->assertJsonMissingValidationErrors('customer_phone');
        $this->patchJson("/api/v1/admin/pos/pending-order/{$order->id}", ['customer_phone' => 'paid via bank'])
            ->assertStatus(422)->assertJsonValidationErrors('customer_phone');
    }

    public function test_customers_quotations_and_order_attach_refuse_a_note(): void
    {
        $this->staff(['customers.view', 'customers.create', 'customers.edit', 'quotations.view', 'quotations.create', 'orders.view', 'orders.edit']);

        $this->postJson('/api/v1/admin/customers', ['first_name' => 'Ann', 'phone' => 'ATC Measurements'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->postJson('/api/v1/admin/customers/quick-create', ['first_name' => 'Ann', 'last_name' => 'K', 'phone' => '0700000000'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->postJson('/api/v1/admin/quotations', ['customer_phone' => 'cash'])
            ->assertStatus(422)->assertJsonValidationErrors('customer_phone');

        $legacy = Customer::create(['customer_number' => 'CP-2', 'first_name' => 'Cy', 'last_name' => 'K',
            'email' => 'cp2@example.test', 'phone' => 'refer to iand m']);
        $this->putJson("/api/v1/admin/customers/{$legacy->id}", ['phone' => 'refer to iand m', 'email' => 'not-an-email'])
            ->assertStatus(422)->assertJsonMissingValidationErrors('phone');
        $this->putJson("/api/v1/admin/customers/{$legacy->id}", ['phone' => 'see notes'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');

        $order = Order::create(['order_number' => 'CP-ATT', 'order_type' => 'pos', 'status' => 'completed', 'payment_status' => 'paid',
            'currency_code' => 'KES', 'subtotal' => 100, 'total_amount' => 100]);
        $this->postJson("/api/v1/admin/orders/{$order->id}/attach-customer", ['customer_phone' => 'cash'])
            ->assertStatus(422)->assertJsonValidationErrors('customer_phone');
    }
}
