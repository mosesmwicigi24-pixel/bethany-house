<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Reports build — the drill-down is authoritative. The backend says what each
 * row is AND where it may lead; the page renders that and invents nothing.
 * A link is offered only when the viewer may open its destination.
 */
class ReportDrillLinksTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('currencies')->updateOrInsert(['code' => 'KES'], [
            'name' => 'KES', 'symbol' => 'KES', 'exchange_rate' => 1, 'reporting_rate_to_kes' => 1,
            'is_base' => true, 'is_active' => true,
        ]);
        \App\Support\ReportingCurrency::forget();

        $this->customer = Customer::create(['customer_number' => 'C-DL-1', 'first_name' => 'Ann', 'phone' => '0700000001']);
        $this->order = Order::create([
            'order_number' => 'DL-1', 'order_type' => 'pos', 'status' => 'completed', 'payment_status' => 'paid',
            'currency_code' => 'KES', 'subtotal' => 5_000, 'total_amount' => 5_000, 'customer_id' => $this->customer->id,
        ]);
        Payment::create([
            'order_id' => $this->order->id, 'amount' => 5_000, 'currency_code' => 'KES',
            'payment_method' => 'cash', 'status' => 'paid', 'paid_at' => now(),
        ]);
    }

    private function actAs(array $perms): void
    {
        $u = User::factory()->create();
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u);
    }

    private function drill(string $metric): array
    {
        return $this->getJson("/api/v1/admin/reports/drill/{$metric}?period=this_month")->assertOk()->json();
    }

    public function test_rows_lead_to_the_order_the_customer_and_the_payment_itself(): void
    {
        $this->actAs([...\Tests\ReportAccess::PAGES, 'orders.view', 'customers.view', 'payments.transactions']);

        $rev = $this->drill('revenue');
        $this->assertNotEmpty($rev['definition'], 'the number says what it is');
        $this->assertSame("/sales/orders/{$this->order->id}", $rev['rows'][0]['links']['order']);
        $this->assertSame("/sales/customers/{$this->customer->id}", $rev['rows'][0]['links']['customer'],
            'the person, not the customer list');

        $paid = $this->drill('collected')['rows'][0];
        $this->assertStringStartsWith('/finance/transactions?search=PAY', $paid['links']['payment'], 'the payment, not just its order');
        $this->assertSame("/sales/orders/{$this->order->id}", $paid['links']['order']);
    }

    public function test_a_drill_never_offers_a_door_the_viewer_cannot_open(): void
    {
        $this->actAs([...\Tests\ReportAccess::PAGES]);

        $row = $this->drill('collected')['rows'][0];
        $this->assertSame([], $row['links'] ?? [], 'no orders.view, customers.view or payments.transactions — no links');
        $this->assertSame('DL-1', $row['ref'], 'the evidence is still listed');
    }

    public function test_report_viewers_can_list_outlets_for_the_filter(): void
    {
        Outlet::factory()->create(['name' => 'Sonalux Store', 'is_active' => true]);
        Outlet::factory()->create(['name' => 'Closed Shop', 'is_active' => false]);
        $this->actAs([...\Tests\ReportAccess::PAGES]);

        $names = collect($this->getJson('/api/v1/admin/reports/outlets')->assertOk()->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Sonalux Store'));
        $this->assertFalse($names->contains('Closed Shop'), 'inactive outlets are not offered');
    }
}
