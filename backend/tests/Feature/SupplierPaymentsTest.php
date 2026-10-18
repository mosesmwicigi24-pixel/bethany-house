<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The payables ledger: money paid to suppliers against purchase orders.
 *
 * Payable = the supplier's invoice amount when recorded, else the PO total;
 * Paid = payments not voided; Balance = Payable − Paid. A supplier payment
 * is cash out, never an operating expense. Every figure below is worked out
 * by hand from the fixture.
 */
class SupplierPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;
    private User $finance;
    private int $supplierId;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        $this->accountant = $this->person('accountant');
        $this->finance    = $this->person('finance_manager');
        $this->supplierId = $this->supplier('Mint Fabrics');

        DB::table('currencies')->updateOrInsert(['code' => 'KES'], ['name' => 'Kenyan Shilling', 'symbol' => 'KES',
            'exchange_rate' => 1, 'reporting_rate_to_kes' => 1, 'is_base' => true, 'is_active' => true]);
        DB::table('currencies')->updateOrInsert(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$',
            'exchange_rate' => 0.01, 'reporting_rate_to_kes' => 128, 'is_base' => false, 'is_active' => true]);
        ReportingCurrency::forget();
    }

    private function person(string $role): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function supplier(string $name): int
    {
        return DB::table('suppliers')->insertGetId([
            'code' => 'SUP-' . uniqid(), 'name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function po(float $total, string $currency = 'KES', string $status = 'approved', ?int $supplierId = null): PurchaseOrder
    {
        return PurchaseOrder::create([
            'po_number' => 'PO-PAY-' . uniqid(), 'supplier_id' => $supplierId ?? $this->supplierId,
            'order_date' => now()->toDateString(), 'status' => $status, 'currency_code' => $currency,
            'subtotal' => $total, 'tax_amount' => 0, 'shipping_amount' => 0, 'total_amount' => $total,
            'payment_status' => 'unpaid',
        ]);
    }

    private function pay(PurchaseOrder $po, float $amount, array $extra = [])
    {
        return $this->postJson("/api/v1/admin/purchase-orders/{$po->id}/payments", $extra + [
            'amount' => $amount, 'method' => 'bank_transfer', 'reference' => 'FT' . uniqid(),
        ]);
    }

    public function test_paying_in_parts_moves_the_status_and_never_past_what_is_owed(): void
    {
        Sanctum::actingAs($this->accountant);
        $po = $this->po(10000);

        $this->pay($po, 4000)->assertCreated()
            ->assertJsonPath('summary.paid', 4000)->assertJsonPath('summary.balance', 6000)
            ->assertJsonPath('summary.status', 'partially_paid');
        $this->assertSame('partially_paid', $po->fresh()->payment_status);

        // 6,000.01 is one cent more than is owed.
        $this->pay($po, 6000.01)->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->assertStringContainsString('KES 6,000.00 is outstanding', $this->pay($po, 6000.01)->json('errors.amount.0'));

        $this->pay($po, 6000)->assertCreated()->assertJsonPath('summary.status', 'paid')->assertJsonPath('summary.balance', 0);
        $this->assertSame('paid', $po->fresh()->payment_status);
        $this->pay($po, 1)->assertStatus(422);

        // Independent reconciliation: 10,000 − (4,000 + 6,000) = 0, two rows.
        $rows = DB::table('supplier_payments')->where('purchase_order_id', $po->id)->whereNull('voided_at')->pluck('amount');
        $this->assertSame(['4000.00', '6000.00'], $rows->map(fn ($a) => (string) $a)->sort()->values()->all());
        $this->assertSame(2, DB::table('activity_log')->where('action', 'supplier_payment_recorded')->count());

        // Not an expense: nothing reaches the expenses ledger (and so the P&L).
        $this->assertSame(0, DB::table('expenses')->count());
    }

    public function test_a_retried_request_records_one_payment(): void
    {
        Sanctum::actingAs($this->accountant);
        $po = $this->po(5000);
        $first  = $this->pay($po, 2000, ['client_request_id' => 'tap-1', 'reference' => 'FT1'])->assertCreated();
        $second = $this->pay($po, 2000, ['client_request_id' => 'tap-1', 'reference' => 'FT1'])->assertOk();

        $this->assertSame($first->json('payment.id'), $second->json('payment.id'));
        $this->assertSame(1, SupplierPayment::where('purchase_order_id', $po->id)->count());
        $this->assertSame('partially_paid', $po->fresh()->payment_status);
    }

    public function test_the_suppliers_invoice_amount_is_what_is_owed_and_the_status_follows_it(): void
    {
        Sanctum::actingAs($this->accountant);
        $po = $this->po(10000);
        $po->update(['supplier_invoice_amount' => 9500]);   // billed less than ordered

        $this->pay($po, 9500)->assertCreated()->assertJsonPath('summary.payable_source', 'supplier_invoice')
            ->assertJsonPath('summary.status', 'paid');

        // The supplier re-bills at 10,000: 500 is owed again, with no new payment.
        $po->fresh()->update(['supplier_invoice_amount' => 10000]);
        $this->assertSame('partially_paid', $po->fresh()->payment_status);

        // Re-billed at 9,000: 500 paid over — a credit with the supplier.
        $po->fresh()->update(['supplier_invoice_amount' => 9000]);
        $this->assertSame('overpaid', $po->fresh()->payment_status);
        $this->getJson("/api/v1/admin/purchase-orders/{$po->id}/payments")->assertOk()->assertJsonPath('summary.balance', -500);
    }

    public function test_a_dollar_payment_keeps_the_rate_it_was_paid_at(): void
    {
        Sanctum::actingAs($this->accountant);
        $po = $this->po(250, 'USD');

        $this->pay($po, 100)->assertCreated();
        $p = SupplierPayment::where('purchase_order_id', $po->id)->sole();
        $this->assertSame('12800.00', (string) $p->amount_kes, '100 × 128');
        $this->assertSame('USD', $p->currency_code);

        // The reporting rate moves to 130: what was paid is not rewritten;
        // the 150 still owed is stated at today's rate (150 × 130 = 19,500).
        DB::table('currencies')->where('code', 'USD')->update(['reporting_rate_to_kes' => 130]);
        ReportingCurrency::forget();
        $this->getJson("/api/v1/admin/purchase-orders/{$po->id}/payments")->assertOk()
            ->assertJsonPath('summary.paid_kes', 12800)
            ->assertJsonPath('summary.balance', 150)
            ->assertJsonPath('summary.balance_kes', 19500);
        $this->assertSame('12800.00', (string) $p->fresh()->amount_kes);
    }

    public function test_voiding_is_someone_elses_and_the_record_never_changes_otherwise(): void
    {
        Sanctum::actingAs($this->accountant);
        $po  = $this->po(3000);
        $id  = $this->pay($po, 3000)->assertCreated()->json('payment.id');
        $url = "/api/v1/admin/purchase-orders/{$po->id}/payments/{$id}/void";

        // The accountant has no void key at all.
        $this->postJson($url, ['reason' => 'Paid the wrong supplier'])->assertForbidden();

        // A finance manager who recorded a payment can't void their own.
        Sanctum::actingAs($this->finance);
        $own = $this->pay($this->po(800), 800)->assertCreated();
        $ownPo = $own->json('payment.purchase_order_id');
        $this->postJson("/api/v1/admin/purchase-orders/{$ownPo}/payments/{$own->json('payment.id')}/void", ['reason' => 'Typed twice'])
            ->assertForbidden()->assertJsonPath('code', 'SELF_APPROVAL');

        // Finance voids the accountant's payment.
        $this->postJson($url, ['reason' => 'Paid the wrong supplier'])->assertOk()->assertJsonPath('summary.status', 'unpaid');
        $this->postJson($url, ['reason' => 'Paid the wrong supplier'])->assertStatus(422);
        $this->assertSame('unpaid', $po->fresh()->payment_status);
        $this->assertSame(1, DB::table('activity_log')->where('action', 'supplier_payment_voided')->count());

        $p = SupplierPayment::find($id);
        $this->assertNotNull($p->voided_at);
        $this->expectException(LogicException::class);
        $p->update(['amount' => 1]);
    }

    public function test_a_payment_is_never_deleted(): void
    {
        Sanctum::actingAs($this->accountant);
        $po = $this->po(1000);
        $this->pay($po, 500)->assertCreated();

        $this->expectException(LogicException::class);
        SupplierPayment::firstOrFail()->delete();
    }

    public function test_what_a_payment_needs(): void
    {
        Sanctum::actingAs($this->accountant);
        $po = $this->po(1000);

        $this->pay($po, 100, ['reference' => ''])->assertStatus(422)->assertJsonValidationErrors('reference');
        $this->pay($po, 100, ['method' => 'cash', 'reference' => ''])->assertCreated();
        $this->pay($po, 100, ['paid_at' => now()->addDay()->toIso8601String()])->assertStatus(422)->assertJsonValidationErrors('paid_at');
        $this->pay($po, 0)->assertStatus(422);

        foreach (['draft', 'pending_approval', 'rejected', 'cancelled'] as $status) {
            $this->pay($this->po(1000, 'KES', $status), 100)->assertStatus(422);
        }

        // Neither buying nor the till pays suppliers.
        Sanctum::actingAs($this->person('procurement_manager'));
        $this->pay($po, 100)->assertForbidden();
        Sanctum::actingAs($this->person('pos_clerk'));
        $this->pay($po, 100)->assertForbidden();
    }

    public function test_an_order_with_money_paid_against_it_is_not_cancelled(): void
    {
        Sanctum::actingAs($this->accountant);
        $po = $this->po(2000);
        $id = $this->pay($po, 500)->assertCreated()->json('payment.id');

        Sanctum::actingAs($this->person('super_admin'));
        $this->postJson("/api/v1/admin/purchase-orders/{$po->id}/cancel", ['reason' => 'Supplier closed'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $this->patchJson("/api/v1/admin/purchase-orders/{$po->id}/status", ['status' => 'cancelled'])->assertStatus(422);
        $this->assertSame('approved', $po->fresh()->status);

        $this->postJson("/api/v1/admin/purchase-orders/{$po->id}/payments/{$id}/void", ['reason' => 'Recorded in error'])->assertOk();
        $this->postJson("/api/v1/admin/purchase-orders/{$po->id}/cancel", ['reason' => 'Supplier closed'])->assertOk();
    }

    public function test_goods_sent_back_lower_what_is_owed_unless_the_supplier_has_invoiced(): void
    {
        Sanctum::actingAs($this->accountant);
        // 10 × 1,000 = 10,000; 2 sent back on an approved return = 2,000.
        $po = $this->po(10000);
        $line = \App\Models\PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'item_type' => 'material', 'material_id' => \App\Models\Material::create([
                'code' => 'MAT-RET', 'name' => 'Lining', 'category' => 'fabric', 'unit_of_measure' => 'm', 'unit_cost' => 1000, 'is_active' => true,
            ])->id,
            'description' => 'Lining', 'quantity' => 10, 'quantity_received' => 10, 'unit_price' => 1000, 'total_price' => 10000,
        ]);
        $ret = DB::table('purchase_returns')->insertGetId([
            'return_number' => 'PR-T1', 'purchase_order_id' => $po->id, 'supplier_id' => $this->supplierId,
            'return_date' => now()->toDateString(), 'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('purchase_return_items')->insert(['return_id' => $ret, 'po_item_id' => $line->id, 'quantity' => 2]);
        // A rejected return moves nothing.
        $rej = DB::table('purchase_returns')->insertGetId([
            'return_number' => 'PR-T2', 'purchase_order_id' => $po->id, 'supplier_id' => $this->supplierId,
            'return_date' => now()->toDateString(), 'status' => 'rejected', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('purchase_return_items')->insert(['return_id' => $rej, 'po_item_id' => $line->id, 'quantity' => 5]);

        $this->getJson("/api/v1/admin/purchase-orders/{$po->id}/payments")->assertOk()
            ->assertJsonPath('summary.returned', 2000)->assertJsonPath('summary.payable', 8000);
        $this->pay($po, 8000.01)->assertStatus(422);
        $this->pay($po, 8000)->assertCreated()->assertJsonPath('summary.status', 'paid');
        $this->assertEquals(0, collect($this->getJson('/api/v1/admin/purchase-orders/payables')->json('data'))->sum('balance'));

        // The supplier's own invoice governs once recorded; the return is shown, not deducted again.
        $po->fresh()->update(['supplier_invoice_amount' => 9000]);
        $this->getJson("/api/v1/admin/purchase-orders/{$po->id}/payments")->assertOk()
            ->assertJsonPath('summary.payable', 9000)->assertJsonPath('summary.returned', 2000)
            ->assertJsonPath('summary.balance', 1000)->assertJsonPath('summary.status', 'partially_paid');
    }

    public function test_expenses_linked_to_the_order_are_pointed_out(): void
    {
        Sanctum::actingAs($this->accountant);
        $po = $this->po(4000);
        $cat = DB::table('expense_categories')->insertGetId(['name' => 'Freight', 'code' => 'FRT-PAY', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['approved', 1200], ['paid', 300], ['pending_approval', 999]] as [$status, $amt]) {
            DB::table('expenses')->insert([
                'reference_number' => 'EXP-' . uniqid(), 'category_id' => $cat, 'title' => 'Freight', 'amount' => $amt, 'currency_code' => 'KES', 'payment_method' => 'cash',
                'amount_kes' => $amt, 'expense_date' => now()->toDateString(), 'status' => $status, 'purchase_order_id' => $po->id,
                'created_by' => $this->accountant->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Approved + paid only: 1,200 + 300.
        $this->getJson("/api/v1/admin/purchase-orders/{$po->id}/payments")->assertOk()
            ->assertJsonPath('summary.linked_expenses.count', 2)
            ->assertJsonPath('summary.linked_expenses.amount_kes', 1500);
    }

    public function test_cash_out_counts_supplier_payments_apart_from_expenses(): void
    {
        Sanctum::actingAs($this->accountant);
        $usd = $this->po(300, 'USD');
        $this->pay($usd, 100)->assertCreated();                    // 12,800 at 128
        $kes = $this->po(5000);
        $voidMe = $this->pay($kes, 2000)->assertCreated()->json('payment.id');
        Sanctum::actingAs($this->finance);
        $this->postJson("/api/v1/admin/purchase-orders/{$kes->id}/payments/{$voidMe}/void", ['reason' => 'Wrong order'])->assertOk();

        $cat = DB::table('expense_categories')->insertGetId(['name' => 'Rent', 'code' => 'RENT-CF', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('expenses')->insert([
            'reference_number' => 'EXP-CF', 'category_id' => $cat, 'title' => 'Rent', 'amount' => 700, 'currency_code' => 'KES',
            'payment_method' => 'cash', 'amount_kes' => 700, 'expense_date' => now()->toDateString(), 'status' => 'approved',
            'created_by' => $this->accountant->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $weeks = \App\Services\Reporting\MetricEngine::for($this->person('super_admin'))
            ->cashFlowWeekly(now()->startOfMonth()->subMonth(), now()->endOfDay());
        $wk = collect($weeks)->last();
        // Out = 700 expenses + 12,800 to suppliers; the voided 2,000 is not cash out.
        $this->assertEquals(700, $wk['out_expenses']);
        $this->assertEquals(12800, $wk['out_suppliers']);
        $this->assertEquals(13500, $wk['out']);
        $this->assertEquals(-13500, $wk['net']);
    }

    public function test_what_is_owed_to_each_supplier(): void
    {
        Sanctum::actingAs($this->accountant);
        $other = $this->supplier('Gold Thread Ltd');

        $a = $this->po(10000);                         // Mint, KES: 10,000 − 4,000 = 6,000
        $this->pay($a, 4000)->assertCreated();
        $b = $this->po(300, 'USD');                    // Mint, USD: 300 − 100 = 200 → 25,600
        $this->pay($b, 100)->assertCreated();
        $c = $this->po(5000, 'KES', 'approved', $other); // Gold, KES: fully paid → not listed
        $this->pay($c, 5000)->assertCreated();
        $this->po(7000, 'KES', 'draft', $other);       // a draft owes nothing

        $res = $this->getJson('/api/v1/admin/purchase-orders/payables')->assertOk();
        $rows = collect($res->json('data'))->keyBy(fn ($r) => $r['supplier'] . '/' . $r['currency']);

        $this->assertSame(['Mint Fabrics/KES', 'Mint Fabrics/USD'], $rows->keys()->all());
        $this->assertEquals(6000, $rows['Mint Fabrics/KES']['balance']);
        $this->assertEquals(200, $rows['Mint Fabrics/USD']['balance']);
        $this->assertEquals(25600, $rows['Mint Fabrics/USD']['balance_kes']);
        $this->assertEquals(31600, $res->json('total_owed_kes'), '6,000 + 25,600');

        // Not for the till or for buyers.
        Sanctum::actingAs($this->person('procurement_manager'));
        $this->getJson('/api/v1/admin/purchase-orders/payables')->assertForbidden();
    }
}
