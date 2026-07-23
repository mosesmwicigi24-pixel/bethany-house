<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A Made-to-Order line captured at POS must carry its measurements onto the
 * production order — the workshop reads production_orders.measurements, so if
 * the cashier's measurements never land there they're invisible to production.
 */
class PosProductionMeasurementsTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $user = User::factory()->create();
        foreach (['pos.access', 'production.view'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
        return $user;
    }

    private function openRegister(Outlet $outlet, User $user): void
    {
        CashRegister::create([
            'register_number' => "REG-{$outlet->id}-{$user->id}",
            'outlet_id'       => $outlet->id,
            'register_name'   => 'Test Register',
            'status'          => 'open',
            'currency_code'   => 'KES',
            'opening_balance' => 5000, 'expected_cash' => 5000,
            'total_sales' => 0, 'total_cash_sales' => 0, 'total_card_sales' => 0,
            'total_mpesa_sales' => 0, 'total_refunds' => 0, 'transaction_count' => 0,
            'opened_by' => $user->id, 'opened_at' => now(),
        ]);
    }

    public function test_pos_mto_measurements_land_on_the_production_order(): void
    {
        $user   = $this->actor();
        $outlet = Outlet::factory()->create();
        $this->openRegister($outlet, $user);
        $product = Product::factory()->create();

        $res = $this->postJson('/api/v1/admin/pos/pending-order', [
            'outlet_id'        => $outlet->id,
            'production_items' => [[
                'product_id'         => $product->id,
                'quantity'           => 2,
                'production_notes'    => 'Purple, ordination',
                'measurement_values' => ['Chest' => '40 in', 'Sleeve' => '24 in'],
            ]],
        ])->assertOk();

        $poNumber = $res->json('production_orders.0');
        $this->assertNotNull($poNumber);

        $po = DB::table('production_orders')->where('order_number', $poNumber)->first();
        $this->assertNotNull($po);
        $this->assertSame(2, (int) $po->quantity);             // line quantity, not 1
        $this->assertSame('Purple, ordination', $po->notes);

        $measurements = json_decode($po->measurements, true);
        $this->assertSame('40 in', $measurements['Chest'] ?? null);
        $this->assertSame('24 in', $measurements['Sleeve'] ?? null);
    }
}
