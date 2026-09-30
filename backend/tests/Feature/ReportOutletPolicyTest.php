<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Reports are BUSINESS-WIDE, and every family agrees on that.
 *
 * Owner's decision, 2026-09-30. Before it, MetricEngine scoped a non-admin to
 * their assigned outlets while ReportController, EnhancedReportController and
 * AnalyticsController applied no scope at all — so the one scoped user read a
 * figure about 10% below the owner's on Executive and the full figure on
 * Sales, with nothing on either page saying which was which. Reaching any
 * report already requires `reports.view`; that permission is the control.
 *
 * What this pins:
 *   - a report-capable user assigned to ONE outlet still sees the whole
 *     business, in every family, cold cache and warm;
 *   - an explicit outlet_id still filters, and is not an error;
 *   - the two families agree on the same window, which is the reconciliation
 *     the split behaviour made impossible;
 *   - `reports.view` is still required.
 *
 * Two outlets are given deliberately lopsided figures so a mistake shows up
 * as arithmetic rather than as a subtle shift: Nairobi 200,000 over 2 orders
 * and 10 units; Mombasa 42,000,000 over 6 orders and 500 units.
 */
class ReportOutletPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $mine;
    private Outlet $theirs;

    private const WINDOW = 'start_date=2020-01-01&end_date=2030-12-31&from=2020-01-01&to=2030-12-31&period=custom';

    protected function setUp(): void
    {
        parent::setUp();
        $this->mine   = Outlet::factory()->create(['name' => 'Nairobi']);
        $this->theirs = Outlet::factory()->create(['name' => 'Mombasa']);

        $this->sales($this->mine, 100_000, 2);
        $this->sales($this->theirs, 7_000_000, 6);
        $this->stock($this->mine, 10, 1_000);
        $this->stock($this->theirs, 500, 9_000);
    }

    private function sales(Outlet $outlet, float $each, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Order::create([
                'order_number'   => 'POS-' . bin2hex(random_bytes(4)),
                'outlet_id'      => $outlet->id,
                'status'         => 'completed',
                'payment_status' => 'paid',
                'currency_code'  => 'KES',
                'subtotal'       => $each,
                'total_amount'   => $each,
            ]);
        }
    }

    private function stock(Outlet $outlet, int $units, float $price): void
    {
        $product = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        ProductPrice::create(['product_id' => $product->id, 'product_variant_id' => null,
                              'currency_code' => 'KES', 'regular_price' => $price]);
        InventoryItem::create(['product_id' => $product->id, 'product_variant_id' => null,
                               'outlet_id' => $outlet->id, 'quantity_on_hand' => $units,
                               'quantity_reserved' => 0, 'reorder_point' => 0]);
    }

    /** A real manager: may read reports, and is attached to one outlet only. */
    private function outletManager(): User
    {
        $user = User::factory()->create();
        $role = Role::findOrCreate('outlet_manager', 'sanctum');
        foreach (['reports.view', 'reports.financial', 'customers.view', 'customers.insights'] as $p) {
            $role->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        $user->assignRole($role);
        $user->outlets()->sync([$this->mine->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    public static function everyReport(): array
    {
        return [
            // The legacy family.
            'sales summary'       => ['/api/v1/admin/reports/sales/summary'],
            'sales by outlet'     => ['/api/v1/admin/reports/sales/by-outlet'],
            'inventory valuation' => ['/api/v1/admin/reports/inventory/valuation'],
            'stock on hand'       => ['/api/v1/admin/reports/inventory/stock-on-hand'],
            // The MetricEngine family.
            'executive'              => ['/api/v1/admin/reports/executive'],
            'financial intelligence' => ['/api/v1/admin/reports/financial-intelligence'],
            'inventory intelligence' => ['/api/v1/admin/reports/inventory-intelligence'],
        ];
    }

    /**
     * Read twice: a cache key that varied by caller would serve the second
     * read from the first caller's answer, so warm is the interesting pass.
     */
    #[DataProvider('everyReport')]
    public function test_a_manager_assigned_to_one_outlet_still_sees_the_whole_business(string $path): void
    {
        $this->outletManager();

        foreach (['cold', 'warm'] as $pass) {
            $this->getJson($path . '?' . self::WINDOW)->assertOk();
        }
    }

    public function test_the_whole_business_means_both_outlets_figures(): void
    {
        $this->outletManager();

        $valuation = $this->getJson('/api/v1/admin/reports/inventory/valuation')->assertOk();
        $this->assertSame(510, (int) $valuation->json('grand_totals.total_units'),
            'Nairobi 10 + Mombasa 500');

        $summary = $this->getJson('/api/v1/admin/reports/sales/summary?' . self::WINDOW)->assertOk();
        $this->assertSame(42_200_000.0, (float) $summary->json('summary.total_revenue'),
            'both outlets, not one');

        $byOutlet = $this->getJson('/api/v1/admin/reports/sales/by-outlet?' . self::WINDOW)->assertOk();
        $this->assertCount(2, $byOutlet->json('outlets'), 'both outlets are listed');
    }

    public function test_an_explicit_outlet_id_filters_rather_than_failing(): void
    {
        $this->outletManager();

        $mine = $this->getJson("/api/v1/admin/reports/sales/summary?outlet_id={$this->mine->id}&" . self::WINDOW)
            ->assertOk();
        $this->assertSame(200_000.0, (float) $mine->json('summary.total_revenue'), 'Nairobi only');

        // The other outlet is a filter too, not a 403: whoever may read the
        // reports may read any of them, and the figure must be that outlet's.
        $theirs = $this->getJson("/api/v1/admin/reports/sales/summary?outlet_id={$this->theirs->id}&" . self::WINDOW)
            ->assertOk();
        $this->assertSame(42_000_000.0, (float) $theirs->json('summary.total_revenue'), 'Mombasa only');
    }

    public function test_the_engine_family_filters_by_outlet_the_same_way(): void
    {
        $this->outletManager();

        $all = $this->getJson('/api/v1/admin/reports/inventory-intelligence?' . self::WINDOW)->assertOk();
        $one = $this->getJson("/api/v1/admin/reports/inventory-intelligence?outlet_id={$this->mine->id}&" . self::WINDOW)
            ->assertOk();

        $this->assertNotSame(
            json_encode($all->json()), json_encode($one->json()),
            'filtering to one outlet must change what the engine returns',
        );
    }

    public function test_reports_still_require_the_permission(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/reports/sales/summary')->assertStatus(403);
        $this->getJson('/api/v1/admin/reports/executive')->assertStatus(403);
    }
}
