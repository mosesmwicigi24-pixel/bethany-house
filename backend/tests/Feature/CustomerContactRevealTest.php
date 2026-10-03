<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 4A reveal (plan §9): POST /admin/customers/{id}/reveal
 *   {field: phone|email|address, reason: <from the list>, context: {type, id}}
 *
 * Allowed for the customer on an open sale (or shipment) the caller works, or
 * for a role that already holds the field unmasked. Every reveal is audited —
 * actor, customer, field, reason, context, IP, session — and never the value.
 * More than 20 in an hour: blocked, and the caller's outlet manager and the
 * super admins are told.
 */
class CustomerContactRevealTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '0712341853';

    private Outlet $shop;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        $this->shop     = Outlet::factory()->create();
        $this->customer = Customer::create(['first_name' => 'Nancy', 'last_name' => 'Wanjiru',
            'phone' => self::PHONE, 'email' => 'nancy82@gmail.com']);
    }

    private function as(string $role, ?User $user = null): User
    {
        $user ??= User::factory()->create();
        $user->assignRole(Role::findByName($role, 'sanctum'));
        $user->outlets()->syncWithoutDetaching([$this->shop->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = $user->fresh();
        Sanctum::actingAs($user);

        return $user;
    }

    private function saleBy(User $clerk, array $extra = []): Order
    {
        return Order::factory()->create(array_merge([
            'order_type' => 'pos', 'status' => 'pending', 'payment_status' => 'pending',
            'outlet_id' => $this->shop->id, 'created_by' => $clerk->id, 'customer_id' => $this->customer->id,
            'customer_phone' => self::PHONE, 'shipping_address_line1' => '14 Ngong Road',
        ], $extra));
    }

    private function reveal(array $body)
    {
        return $this->postJson("/api/v1/admin/customers/{$this->customer->id}/reveal", $body);
    }

    public function test_a_cashier_reveals_the_phone_of_the_customer_on_her_open_sale_and_it_is_audited(): void
    {
        $clerk = $this->as('pos_clerk');
        $sale  = $this->saleBy($clerk);

        $res = $this->reveal(['field' => 'phone', 'reason' => 'payment_follow_up',
            'context' => ['type' => 'order', 'id' => $sale->id]])->assertOk();
        $this->assertSame(self::PHONE, $res->json('value'));

        $row = DB::table('activity_log')->where('event', 'customer_contact_revealed')->first();
        $this->assertNotNull($row, 'every reveal leaves a row');
        $this->assertSame($clerk->id, (int) $row->causer_id);
        $this->assertSame($this->customer->id, (int) $row->subject_id);
        $props = json_decode($row->properties, true);
        $this->assertSame('phone', $props['field']);
        $this->assertSame('payment_follow_up', $props['reason']);
        $this->assertEquals(['type' => 'order', 'id' => $sale->id], $props['context']);
        $this->assertArrayHasKey('ip', $props);
        $this->assertArrayHasKey('session', $props);
        $this->assertStringNotContainsString(self::PHONE, $row->properties, 'the audit never stores the value');
    }

    public function test_the_address_is_revealed_for_delivery(): void
    {
        $clerk = $this->as('pos_clerk');
        $sale  = $this->saleBy($clerk);

        $this->reveal(['field' => 'address', 'reason' => 'delivery', 'context' => ['type' => 'order', 'id' => $sale->id]])
            ->assertOk()->assertJsonPath('value.line1', '14 Ngong Road');
    }

    public function test_a_cashier_cannot_reveal_through_a_colleagues_sale_or_a_closed_one(): void
    {
        $clerk     = $this->as('pos_clerk');
        $colleague = User::factory()->create();
        $theirs    = $this->saleBy($colleague);
        $closed    = $this->saleBy($clerk, ['status' => 'completed', 'payment_status' => 'paid']);

        $this->reveal(['field' => 'phone', 'reason' => 'payment_follow_up', 'context' => ['type' => 'order', 'id' => $theirs->id]])
            ->assertForbidden();
        $this->reveal(['field' => 'phone', 'reason' => 'payment_follow_up', 'context' => ['type' => 'order', 'id' => $closed->id]])
            ->assertForbidden();
        $this->assertSame(2, DB::table('activity_log')->where('event', 'customer_contact_reveal_refused')->count());
    }

    public function test_a_masked_role_must_say_which_sale_and_why(): void
    {
        $this->as('pos_clerk');

        $this->reveal(['field' => 'phone', 'reason' => 'payment_follow_up'])->assertStatus(422);
        $this->reveal(['field' => 'phone', 'reason' => 'curiosity', 'context' => ['type' => 'order', 'id' => 1]])->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_a_role_holding_the_field_unmasked_reveals_without_a_sale_and_is_still_audited(): void
    {
        $this->as('admin');

        $this->reveal(['field' => 'phone', 'reason' => 'customer_callback'])->assertOk()->assertJsonPath('value', self::PHONE);
        $this->assertSame(1, DB::table('activity_log')->where('event', 'customer_contact_revealed')->count());
    }

    public function test_more_than_twenty_reveals_an_hour_are_blocked_and_reported(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole(Role::findByName('outlet_manager', 'sanctum'));
        $manager->outlets()->attach($this->shop->id);
        $owner = User::factory()->create();
        $owner->assignRole(Role::findByName('super_admin', 'sanctum'));

        $clerk = $this->as('pos_clerk');
        $sale  = $this->saleBy($clerk);
        $body  = ['field' => 'phone', 'reason' => 'payment_follow_up', 'context' => ['type' => 'order', 'id' => $sale->id]];

        for ($i = 0; $i < 20; $i++) {
            $this->reveal($body)->assertOk();
        }
        $this->reveal($body)->assertStatus(429)->assertJsonPath('code', 'reveal_limit');
        $this->reveal($body)->assertStatus(429);

        $notified = DB::table('notifications')->where('data', 'like', '%reveal%')->pluck('notifiable_id')->map(fn ($id) => (int) $id);
        $this->assertTrue($notified->contains($manager->id), 'the cashier\'s outlet manager is told');
        $this->assertTrue($notified->contains($owner->id), 'and the super admins');
        $this->assertSame($notified->unique()->count(), $notified->count(), 'told once, not once per blocked attempt');
        $this->assertFalse($notified->contains($clerk->id), 'not the person who was blocked');
        $this->assertSame(20, DB::table('activity_log')->where('event', 'customer_contact_revealed')->count());
    }
}
