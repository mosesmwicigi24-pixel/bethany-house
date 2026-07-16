<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sale creation dedupes on an EXACT client key, never on content.
 *
 * recordPosPay (PR #112) can use a short same-order/method/amount window because
 * a second identical payment on one order within seconds cannot be real. Creating
 * a SALE is the opposite: two identical sales are routine — a queue, where the
 * next customer buys the same item at the same till moments later. A content
 * guess would eventually swallow a real sale, and the risks are not symmetric:
 * a duplicate order is visible and fixable, a swallowed one is lost revenue and a
 * customer who paid for nothing.
 *
 * The second test is therefore the important one: it pins the behaviour that a
 * heuristic would have broken.
 *
 * These drive the model + unique index rather than the HTTP endpoint, because a
 * successful createSale is not HTTP-testable under RefreshDatabase (its
 * post-commit admin notification poisons the wrapping transaction — the same
 * reason the suite has never covered a sale end-to-end).
 */
class PosSaleIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function actingWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    /** The key is persisted and mass-assignable — if it were missing from
     *  $fillable it would be silently dropped and every submit would look new. */
    public function test_client_request_id_is_persisted_through_mass_assignment(): void
    {
        $outlet = Outlet::factory()->create();

        $order = Order::factory()->create([
            'order_type'        => 'pos',
            'outlet_id'         => $outlet->id,
            'client_request_id' => 'attempt-abc',
        ]);

        $this->assertSame('attempt-abc', $order->fresh()->client_request_id);
    }

    /** The unique index is what makes the read-check a guard and not a race. */
    public function test_the_same_key_cannot_create_two_orders(): void
    {
        $outlet = Outlet::factory()->create();

        Order::factory()->create([
            'order_type'        => 'pos',
            'outlet_id'         => $outlet->id,
            'client_request_id' => 'attempt-xyz',
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        Order::factory()->create([
            'order_type'        => 'pos',
            'outlet_id'         => $outlet->id,
            'client_request_id' => 'attempt-xyz',
        ]);
    }

    /**
     * THE ONE THAT MATTERS. Two identical real sales — same outlet, same total,
     * moments apart — is a queue, not a double-tap. Both must exist. A
     * content-based guard would have swallowed the second and lost the money.
     */
    public function test_two_identical_sales_with_different_keys_both_survive(): void
    {
        $outlet = Outlet::factory()->create();

        $first = Order::factory()->create([
            'order_type'        => 'pos',
            'outlet_id'         => $outlet->id,
            'total_amount'      => 200,
            'client_request_id' => 'customer-one',
        ]);

        $second = Order::factory()->create([
            'order_type'        => 'pos',
            'outlet_id'         => $outlet->id,
            'total_amount'      => 200,
            'client_request_id' => 'customer-two',
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, Order::whereIn('client_request_id', ['customer-one', 'customer-two'])->count());
    }

    /** Orders without a key must stay writable — old clients still work, and the
     *  partial index must not treat two NULLs as a collision. */
    public function test_orders_without_a_key_are_unaffected(): void
    {
        $outlet = Outlet::factory()->create();

        Order::factory()->create(['order_type' => 'pos', 'outlet_id' => $outlet->id, 'client_request_id' => null]);
        Order::factory()->create(['order_type' => 'pos', 'outlet_id' => $outlet->id, 'client_request_id' => null]);

        $this->assertSame(2, Order::whereNull('client_request_id')->count());
    }

    /** The index must actually exist — a missing partial index would leave the
     *  read-check racing silently. */
    public function test_the_partial_unique_index_exists(): void
    {
        $found = DB::selectOne(
            "select indexdef from pg_indexes where tablename = 'orders' and indexname = 'orders_client_request_id_unique'"
        );

        $this->assertNotNull($found, 'orders_client_request_id_unique is missing');
        $this->assertStringContainsString('UNIQUE', strtoupper($found->indexdef));
    }
}
