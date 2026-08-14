<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Queries that must see EVERY row, even for a scoped caller.
 *
 * A global scope bounds reads, which is the point — but some queries are not
 * reads on the caller's behalf. A uniqueness check asks "does this order number
 * already exist ANYWHERE", and answering it from inside one clerk's view makes
 * it a guarantee about that clerk rather than about the table. The generator
 * would then hand two clerks the same order number and the unique index would
 * reject the second sale at the till.
 *
 * Same for the idempotency lookups: a replayed submit must be recognised
 * against every order, not only the ones the submitter can see.
 *
 * These are the queries that need Order::withoutViewerScope(). This test exists
 * so the next person to add one finds out here rather than at a counter.
 */
class ScopedSystemQueryTest extends TestCase
{
    use RefreshDatabase;

    private function scopedClerk(): User
    {
        $role = Role::findOrCreate('pos_clerk', 'sanctum');
        $role->givePermissionTo(Permission::findOrCreate('orders.view', 'sanctum'));
        DB::table('roles')->where('id', $role->id)->update(['data_scope' => 'own']);

        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());
        $this->actingAs($user->fresh());

        return $user->fresh();
    }

    public function test_an_order_number_uniqueness_check_sees_other_peoples_orders(): void
    {
        $someoneElse = User::factory()->create();
        $theirs = Order::factory()->create([
            'created_by'   => $someoneElse->id,
            'order_number' => 'POS-COLLIDE-1',
        ]);

        $this->scopedClerk();

        // The scoped view correctly hides the row…
        $this->assertNull(Order::find($theirs->id));

        // …but the uniqueness check must still find it, or the generator will
        // hand this clerk a number that is already taken.
        $this->assertTrue(
            Order::withoutViewerScope()->where('order_number', 'POS-COLLIDE-1')->exists(),
            'order-number uniqueness must be checked against the whole table',
        );
    }

    public function test_an_idempotency_lookup_sees_other_peoples_orders(): void
    {
        $someoneElse = User::factory()->create();
        Order::factory()->create([
            'created_by'        => $someoneElse->id,
            'client_request_id' => 'attempt-abc',
        ]);

        $this->scopedClerk();

        $this->assertNotNull(
            Order::withoutViewerScope()->where('client_request_id', 'attempt-abc')->first(),
            'a replayed submit must be recognised against every order',
        );
    }

    /**
     * The bug this guards against, stated as the failing case: a plain scoped
     * query does NOT see the other clerk's row, so a uniqueness check written
     * that way silently answers "available" about a number that is taken.
     */
    public function test_a_plain_scoped_query_is_blind_to_them_which_is_why_the_exemption_exists(): void
    {
        $someoneElse = User::factory()->create();
        Order::factory()->create([
            'created_by'   => $someoneElse->id,
            'order_number' => 'POS-COLLIDE-2',
        ]);

        $this->scopedClerk();

        $this->assertFalse(
            Order::where('order_number', 'POS-COLLIDE-2')->exists(),
            'this is the trap: scoped, the number looks free when it is taken',
        );
    }
}
