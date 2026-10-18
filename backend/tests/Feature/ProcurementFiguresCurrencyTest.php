<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Purchase-order money outside the procurement report. The supplier page
 * (shown as "KES …") and the PO statistics summed total_amount across
 * currencies, so a USD 100 order added 100 shillings. The procurement
 * report counted statuses that do not exist ('partial', 'pending'), so its
 * part-received value was always 0 and its pending count left out orders
 * awaiting approval — while its PDF copy counted them.
 *
 * Fixture, by hand, one supplier:
 *   KES 10,000 approved · USD 100 ordered (= 12,800) · EUR 50 approved (no rate)
 *   KES 4,000 partially_received · KES 2,500 pending_approval · KES 9,999 cancelled
 *   Supplier total (not cancelled, convertible) = 10,000 + 12,800 + 4,000 + 2,500 = 29,300
 *   Outstanding (approved/ordered/partially_received, convertible) = 10,000 + 12,800 + 4,000 = 26,800
 *   Report: partial_value 4,000; pending_count 3 (2,500 pending_approval, 10,000 approved, USD ordered);
 *   the EUR order is approved too → pending_count 4 (it is a count, not money).
 */
class ProcurementFiguresCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private int $supplierId;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole(Role::findByName('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u);

        DB::table('currencies')->updateOrInsert(['code' => 'KES'], ['name' => 'Kenyan Shilling', 'symbol' => 'KES',
            'exchange_rate' => 1, 'reporting_rate_to_kes' => 1, 'is_base' => true, 'is_active' => true]);
        DB::table('currencies')->updateOrInsert(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$',
            'exchange_rate' => 0.01, 'reporting_rate_to_kes' => 128, 'is_base' => false, 'is_active' => true]);
        DB::table('currencies')->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€',
            'exchange_rate' => 0.0085, 'reporting_rate_to_kes' => null, 'is_base' => false, 'is_active' => true]);
        ReportingCurrency::forget();

        $this->supplierId = DB::table('suppliers')->insertGetId([
            'code' => 'SUP-FIG', 'name' => 'Mint Fabrics', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([
            [10000, 'KES', 'approved'], [100, 'USD', 'ordered'], [50, 'EUR', 'approved'],
            [4000, 'KES', 'partially_received'], [2500, 'KES', 'pending_approval'], [9999, 'KES', 'cancelled'],
        ] as [$total, $cur, $status]) {
            PurchaseOrder::create([
                'po_number' => 'PO-FIG-' . uniqid(), 'supplier_id' => $this->supplierId, 'order_date' => now()->toDateString(),
                'status' => $status, 'currency_code' => $cur, 'subtotal' => $total, 'total_amount' => $total,
            ]);
        }
    }

    public function test_the_supplier_total_is_in_shillings_and_names_what_it_left_out(): void
    {
        $this->getJson("/api/v1/admin/suppliers/{$this->supplierId}")->assertOk()
            ->assertJsonPath('stats.total_value', 29300)
            ->assertJsonPath('stats.unconverted_orders', 1);

        $this->assertSame(29300.0, \App\Models\Supplier::find($this->supplierId)->getTotalPurchaseAmount());
    }

    public function test_the_po_statistics_are_in_shillings(): void
    {
        $this->getJson('/api/v1/admin/purchase-orders/statistics')->assertOk()
            ->assertJsonPath('total_value', 29300)
            ->assertJsonPath('outstanding_value', 26800);
    }

    public function test_the_procurement_report_counts_the_statuses_that_exist(): void
    {
        $s = $this->getJson('/api/v1/admin/reports/purchase-orders')->assertOk()->json('summary');
        $this->assertEquals(4000, $s['partial_value']);
        $this->assertSame(4, (int) $s['pending_count']);
    }
}
