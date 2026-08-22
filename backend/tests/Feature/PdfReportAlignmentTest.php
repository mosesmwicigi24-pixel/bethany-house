<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ReportPdfController;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The PDF is the report that leaves the building — it gets carried into
 * meetings and quoted back later. These tests pin it to the SAME definitions
 * as the screens: recognised revenue, reporting-rate conversion, real COGS.
 * The old version ran on payment_status='paid' with KES-only filters (and one
 * block that mixed currencies raw), so the printout disagreed with the page
 * it was exported from.
 *
 * The data methods are tested directly — a rendered PDF binary cannot be
 * asserted against, which is exactly why salesData()/financialData() exist.
 */
class PdfReportAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private function pdfData(string $method): array
    {
        $req = Request::create('/pdf', 'GET', [
            'start_date' => now()->subDay()->toDateString(),
            'end_date'   => now()->addDay()->toDateString(),
        ]);

        return app(ReportPdfController::class)->{$method}($req);
    }

    public function test_the_sales_pdf_recognises_and_converts_like_the_page(): void
    {
        Order::factory()->create(['status' => 'confirmed', 'payment_status' => 'paid',
            'currency_code' => 'KES', 'total_amount' => 1000, 'customer_phone' => '0722000001']);
        Order::factory()->create(['status' => 'confirmed', 'payment_status' => 'paid',
            'currency_code' => 'USD', 'total_amount' => 10, 'customer_phone' => '0722000002']);
        Order::factory()->create(['status' => 'confirmed', 'payment_status' => 'paid',
            'currency_code' => 'ZMW', 'total_amount' => 100, 'customer_phone' => '0722000003']);
        // A deposit-paid order IS revenue (recognised); the old paid-basis dropped it.
        Order::factory()->create(['status' => 'confirmed', 'payment_status' => 'deposit',
            'currency_code' => 'KES', 'total_amount' => 500, 'customer_phone' => '0722000004']);
        // An abandoned cart is NOT revenue, whatever basis you prefer.
        Order::factory()->create(['status' => 'pending', 'payment_status' => 'pending',
            'currency_code' => 'KES', 'total_amount' => 90000, 'customer_phone' => '0722000005']);

        $d = $this->pdfData('salesData');

        // 1000 + 10x128 + 100x6.5 + 500 = 4,430. Face value reads 1,610;
        // the old paid-basis KES-only version reads 1,000.
        $this->assertSame(4430.0, (float) $d['summary']->total_revenue);
        $this->assertSame(4, (int) $d['summary']->total_orders, 'the cart stays out');
        $this->assertSame(4, (int) $d['summary']->unique_customers,
            'walk-ins have phones, not accounts — COUNT(user_id) saw zero of them');
    }

    public function test_the_financial_pdf_agrees_with_the_pl_page_to_the_cent(): void
    {
        $order = Order::factory()->create(['status' => 'completed', 'payment_status' => 'paid',
            'currency_code' => 'KES', 'total_amount' => 10000]);
        $product = \App\Models\Product::factory()->create();
        DB::table('order_items')->insert([
            'order_id' => $order->id, 'product_id' => $product->id,
            'product_name' => 'Cassock', 'quantity' => 2, 'unit_price' => 5000,
            'total_price' => 10000, 'cost_price' => 3000,   // snapshot COGS = 6,000
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Order::factory()->create(['status' => 'confirmed', 'payment_status' => 'paid',
            'currency_code' => 'USD', 'total_amount' => 10]);

        $pdf = $this->pdfData('financialData');

        $viewer = User::factory()->create();
        $viewer->assignRole(Role::findOrCreate('admin', 'sanctum'));
        foreach (['reports.view', 'reports.financial'] as $p) {
            $viewer->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        $page = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/admin/reports/financial/profit-loss?start_date='
                . now()->subDay()->toDateString() . '&end_date=' . now()->addDay()->toDateString())
            ->assertOk()->json();

        $this->assertSame((float) $page['revenue'], (float) $pdf['revenue'],
            'the printout must never disagree with the page it was exported from');
        $this->assertSame((float) $page['cost_of_goods_sold'], (float) $pdf['cogs']);
        $this->assertSame((float) $page['gross_profit'], (float) $pdf['grossProfit']);
        $this->assertSame(11280.0, (float) $pdf['revenue'], '10,000 + 10x128');
        $this->assertSame(6000.0, (float) $pdf['cogs'], 'the snapshot cost, not "no COGS tracked yet"');
        $this->assertSame(5280.0, (float) $pdf['grossProfit']);
    }

    public function test_the_pl_page_itself_now_recognises_a_deposit_order(): void
    {
        // The page's own P&L was still on paid-basis — a deposit-paid vestment
        // order was revenue on the sales report and missing from the P&L one
        // menu item away.
        Order::factory()->create(['status' => 'confirmed', 'payment_status' => 'deposit',
            'currency_code' => 'KES', 'total_amount' => 7000]);

        $viewer = User::factory()->create();
        $viewer->assignRole(Role::findOrCreate('admin', 'sanctum'));
        foreach (['reports.view', 'reports.financial'] as $p) {
            $viewer->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        $page = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/admin/reports/financial/profit-loss?start_date='
                . now()->subDay()->toDateString() . '&end_date=' . now()->addDay()->toDateString())
            ->assertOk()->json();

        $this->assertSame(7000.0, (float) $page['revenue']);
    }
}
