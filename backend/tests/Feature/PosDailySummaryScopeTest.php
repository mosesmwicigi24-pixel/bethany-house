<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The POS daily report told whoever asked second what whoever asked first saw.
 *
 * The figures are bounded to the caller — a cashier's day is her own takings,
 * which is what #291 restored. But the result was cached under
 * pos_daily_{outlet}_{date}, a key with no viewer in it, so the FIRST caller
 * inside the TTL decided the answer for everybody:
 *
 *   manager first → the cashier was served the whole shop's takings out of the
 *                   cache, which is the disclosure #288 and #290 closed;
 *   cashier first → the manager was told the outlet had taken her figure and
 *                   no more, which is a false number on the screen someone
 *                   reconciles the till against.
 *
 * Sixty seconds for today, a full hour for any past date.
 *
 * The second half is quieter and was not in the original report: total_sales
 * came from Eloquent and was bounded, while top_products was a raw DB::table
 * join and was not. One response carried the caller's own money above the
 * whole outlet's best-sellers.
 */
class PosDailySummaryScopeTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;
    private User $clerk;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        Cache::flush();

        $this->outlet = Outlet::factory()->create();

        $this->clerk = User::factory()->create(['first_name' => 'Jackline', 'last_name' => 'Mwicigi']);
        $this->clerk->assignRole(Role::findByName('pos_clerk', 'sanctum'));
        $this->clerk->outlets()->attach($this->outlet->id);

        $this->manager = User::factory()->create();
        $this->manager->assignRole(Role::findByName('admin', 'sanctum'));
        $this->manager->outlets()->attach($this->outlet->id);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Her sale: 1,000 of cassocks. A colleague's: 9,000 of chairs.
        $this->sale($this->clerk, 1000, 'Cassock');
        $this->sale(User::factory()->create(), 9000, 'Chair');
    }

    private function sale(User $seller, float $amount, string $productName): Order
    {
        $order = Order::factory()->create([
            'created_by'   => $seller->id,
            'outlet_id'    => $this->outlet->id,
            'order_type'   => 'pos',
            'status'       => 'completed',
            'total_amount' => $amount,
        ]);

        OrderItem::create([
            'order_id'        => $order->id,
            'product_id'      => Product::factory()->create()->id,
            'sku'             => strtoupper(substr($productName, 0, 3)) . '-' . $order->id,
            'product_name'    => $productName,
            'quantity'        => 1,
            'unit_price'      => $amount,
            'discount_amount' => 0,
            'tax_amount'      => 0,
            'total_price'     => $amount,
        ]);

        return $order;
    }

    private function summaryAs(User $user): array
    {
        Sanctum::actingAs($user->fresh());

        return $this->getJson("/api/v1/admin/pos/reports/daily?outlet_id={$this->outlet->id}")
            ->assertOk()
            ->json('summary');
    }

    // ── the leak ────────────────────────────────────────────────────────────

    /**
     * The order these two run in is the whole point, so it is fixed here
     * rather than left to chance.
     */
    public function test_a_manager_looking_first_does_not_hand_the_cashier_the_shops_takings(): void
    {
        $this->assertSame(10000.0, (float) $this->summaryAs($this->manager)['total_sales'],
            'the manager sees the whole outlet');

        $this->assertSame(1000.0, (float) $this->summaryAs($this->clerk)['total_sales'],
            'and the cashier still sees only her own, rather than the cached 10,000');
    }

    public function test_a_cashier_looking_first_does_not_leave_the_manager_short(): void
    {
        $this->assertSame(1000.0, (float) $this->summaryAs($this->clerk)['total_sales']);

        $this->assertSame(10000.0, (float) $this->summaryAs($this->manager)['total_sales'],
            'the manager gets the real figure, not her cached one');
    }

    /**
     * The cache must still be a cache. Same viewer, same day, one entry.
     */
    public function test_the_same_viewer_is_still_served_from_cache(): void
    {
        $first = $this->summaryAs($this->clerk);

        // A sale that lands after the entry is warm must not appear until the
        // TTL turns over — which is what proves we are reading the cache.
        $this->sale($this->clerk, 500, 'Stole');

        $this->assertSame(
            (float) $first['total_sales'],
            (float) $this->summaryAs($this->clerk)['total_sales'],
            'still cached for her'
        );
    }

    // ── the quieter half: the raw join ──────────────────────────────────────

    public function test_the_best_sellers_are_hers_too_not_the_outlets(): void
    {
        $names = collect($this->summaryAs($this->clerk)['top_products'])->pluck('name');

        $this->assertTrue($names->contains('Cassock'), 'her own line is there');
        $this->assertFalse($names->contains('Chair'),
            "a colleague's best-seller must not sit under her own takings");
    }

    public function test_a_manager_still_sees_every_best_seller(): void
    {
        $names = collect($this->summaryAs($this->manager)['top_products'])->pluck('name');

        $this->assertTrue($names->contains('Cassock'));
        $this->assertTrue($names->contains('Chair'));
    }

    // ── the figures have to agree with each other ───────────────────────────

    /**
     * net_sales subtracts returns from takings. If the takings were bounded to
     * the caller and the returns were not, the subtraction would be between
     * two different populations and the number on the screen would be a
     * fiction — worse than either half alone.
     */
    public function test_net_sales_subtracts_her_returns_from_her_takings(): void
    {
        $hers    = Order::withoutViewerScope()->where('created_by', $this->clerk->id)->firstOrFail();
        $theirs  = Order::withoutViewerScope()->where('created_by', '!=', $this->clerk->id)->firstOrFail();

        $this->refund($hers, 100);      // hers: 1000 taken, 100 back  → 900
        $this->refund($theirs, 4000);   // not hers, must not touch her net

        $summary = $this->summaryAs($this->clerk);

        $this->assertSame(1000.0, (float) $summary['total_sales']);
        $this->assertSame(100.0,  (float) $summary['total_returns'], 'her refund only');
        $this->assertSame(900.0,  (float) $summary['net_sales'],
            'takings and returns come from the same population');
    }

    private function refund(Order $order, float $amount): void
    {
        \App\Models\OrderReturn::create([
            'return_number' => 'RET-' . $order->id,
            'order_id'      => $order->id,
            'status'        => 'refunded',
            'return_reason' => 'test',
            'refund_amount' => $amount,
            'requested_at'  => now(),
            'refunded_at'   => now(),
        ]);
    }
}
