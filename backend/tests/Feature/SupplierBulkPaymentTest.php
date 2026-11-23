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
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\StepsUp;
use Tests\TestCase;

/**
 * Marking several purchase orders as paid in one go (owner, 2026-11-23:
 * stock already on the shelf was paid for long ago, and KES 929,143 showed
 * as owed). One payment per order, for exactly what that order still owes,
 * through SupplierPayables::record. Every figure is worked out by hand:
 *
 *   A  KES 4,360 approved, nothing paid          → owes 4,360
 *   B  KES 6,600 received, 2,000 already paid    → owes 4,600
 *   C  USD 100 approved (128 KES/USD)            → owes USD 100 = KES 12,800
 *   D  KES 1,750 draft                           → not a debt yet
 *   E  KES 2,800 approved, paid in full          → owes 0
 *
 *   Owed before: 4,360 + 4,600 + 12,800 = KES 21,760 across three orders.
 */
class SupplierBulkPaymentTest extends TestCase
{
    use RefreshDatabase;
    use StepsUp;

    private const BULK = '/api/v1/admin/purchase-orders/payments/bulk';
    private const OWED = '/api/v1/admin/purchase-orders/payables/orders';

    private User $owner;
    private int $supplierId;
    private PurchaseOrder $a, $b, $c, $d, $e;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');

        DB::table('currencies')->updateOrInsert(['code' => 'KES'], ['name' => 'Kenyan Shilling', 'symbol' => 'KES',
            'exchange_rate' => 1, 'reporting_rate_to_kes' => 1, 'is_base' => true, 'is_active' => true]);
        DB::table('currencies')->updateOrInsert(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$',
            'exchange_rate' => 0.01, 'reporting_rate_to_kes' => 128, 'is_base' => false, 'is_active' => true]);
        ReportingCurrency::forget();

        $this->owner = $this->person('super_admin');
        $this->supplierId = DB::table('suppliers')->insertGetId([
            'code' => 'SUP-' . uniqid(), 'name' => 'DK Textiles', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->a = $this->po(4360, 'KES', 'approved', '2026-08-03');
        $this->b = $this->po(6600, 'KES', 'received', '2026-09-14');
        $this->c = $this->po(100, 'USD', 'approved', '2026-10-01');
        $this->d = $this->po(1750, 'KES', 'draft', '2026-10-20');
        $this->e = $this->po(2800, 'KES', 'approved', '2026-07-01');

        $this->seedPayment($this->b, 2000);
        $this->seedPayment($this->e, 2800);

        Sanctum::actingAs($this->owner);
        $this->stepUp($this->owner);
    }

    private function person(string $role): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function po(float $total, string $currency, string $status, string $date): PurchaseOrder
    {
        return PurchaseOrder::create([
            'po_number' => 'PO-BULK-' . uniqid(), 'supplier_id' => $this->supplierId,
            'order_date' => $date, 'status' => $status, 'currency_code' => $currency,
            'subtotal' => $total, 'tax_amount' => 0, 'shipping_amount' => 0, 'total_amount' => $total,
            'payment_status' => 'unpaid',
        ]);
    }

    private function seedPayment(PurchaseOrder $po, float $amount): void
    {
        \App\Services\Procurement\SupplierPayables::record($po, ['amount' => $amount, 'method' => 'cash'], $this->owner);
    }

    private function bulk(array $ids, array $extra = [])
    {
        return $this->postJson(self::BULK, $extra + [
            'purchase_order_ids' => $ids, 'method' => 'pre_records', 'date_mode' => 'order_date',
            'client_request_id' => 'batch-1',
        ]);
    }

    public function test_the_owed_list_is_every_order_that_still_owes_and_ties_to_the_owed_figure(): void
    {
        $rows = collect($this->getJson(self::OWED)->assertOk()->json('data'))->keyBy('id');

        $this->assertEqualsCanonicalizing([$this->a->id, $this->b->id, $this->c->id], $rows->keys()->all());
        $this->assertEquals(4360.00, $rows[$this->a->id]['balance']);
        $this->assertEquals(4600.00, $rows[$this->b->id]['balance']);
        $this->assertEquals(100.00, $rows[$this->c->id]['balance']);
        $this->assertEquals(12800.00, $rows[$this->c->id]['balance_kes']);

        // 4,360 + 4,600 + 12,800 — and the same figure as the "Owed to suppliers" chip.
        $this->assertEquals(21760.00, $this->getJson(self::OWED)->json('total_owed_kes'));
        $this->assertEquals(21760.00, $this->getJson('/api/v1/admin/purchase-orders/payables')->json('total_owed_kes'));
    }

    public function test_each_order_gets_one_payment_for_exactly_what_it_owed_dated_on_its_own_order_date(): void
    {
        $res = $this->bulk([$this->a->id, $this->b->id, $this->c->id])->assertOk();
        $this->assertCount(3, $res->json('paid'));
        $this->assertCount(0, $res->json('skipped'));

        $pay = fn (PurchaseOrder $po) => SupplierPayment::where('purchase_order_id', $po->id)->where('method', 'pre_records')->sole();

        $this->assertEquals(4360.00, (float) $pay($this->a)->amount);
        $this->assertSame('2026-08-03', $pay($this->a)->paid_at->toDateString());
        $this->assertEquals(4600.00, (float) $pay($this->b)->amount, 'only the balance, not the total');
        $this->assertSame('2026-09-14', $pay($this->b)->paid_at->toDateString());
        $this->assertEquals(100.00, (float) $pay($this->c)->amount);
        $this->assertSame('USD', $pay($this->c)->currency_code);
        $this->assertEquals(12800.00, (float) $pay($this->c)->amount_kes);
        $this->assertNull($pay($this->a)->reference);
        $this->assertSame($this->owner->id, (int) $pay($this->a)->recorded_by);

        foreach ([$this->a, $this->b, $this->c] as $po) {
            $this->assertSame('paid', $po->fresh()->payment_status);
        }

        // Nothing is owed any more, on either figure.
        $this->assertSame([], $this->getJson(self::OWED)->json('data'));
        $this->assertEquals(0.0, $this->getJson('/api/v1/admin/purchase-orders/payables')->json('total_owed_kes'));

        // Not an expense, and one batch entry on the trail beside the three payments.
        $this->assertSame(0, DB::table('expenses')->count());
        $this->assertSame(1, DB::table('activity_log')->where('action', 'supplier_payments_bulk_recorded')->count());
    }

    public function test_sending_the_same_batch_again_pays_nothing_twice(): void
    {
        $this->bulk([$this->a->id, $this->b->id])->assertOk();
        $before = SupplierPayment::count();

        $again = $this->bulk([$this->a->id, $this->b->id])->assertOk();

        $this->assertSame($before, SupplierPayment::count());
        $this->assertCount(2, $again->json('paid'), 'the first payments are returned, not new ones');
        $this->assertEquals(4360.00, (float) SupplierPayment::where('purchase_order_id', $this->a->id)->sum('amount'));
    }

    public function test_orders_that_cannot_be_paid_are_skipped_and_named_and_the_rest_still_go(): void
    {
        $res = $this->bulk([$this->a->id, $this->d->id, $this->e->id, 999999])->assertOk();

        $this->assertSame([$this->a->id], array_column($res->json('paid'), 'id'));
        $skipped = collect($res->json('skipped'))->keyBy('id');
        $this->assertStringContainsString('once it is approved', $skipped[$this->d->id]['reason']);
        $this->assertStringContainsString('Nothing is outstanding', $skipped[$this->e->id]['reason']);
        $this->assertStringContainsString('no longer exists', $skipped[999999]['reason']);

        $this->assertSame(0, SupplierPayment::where('purchase_order_id', $this->d->id)->count());
        $this->assertEquals(2800.00, (float) SupplierPayment::where('purchase_order_id', $this->e->id)->sum('amount'));
    }

    public function test_one_date_for_the_batch_and_a_real_method_needs_its_reference(): void
    {
        // A bank payment with no reference: every order is skipped, nothing is written.
        $res = $this->bulk([$this->a->id], ['method' => 'im_bank', 'date_mode' => 'one_date', 'paid_at' => '2026-11-10', 'client_request_id' => 'b2']);
        $this->assertCount(0, $res->json('paid'));
        $this->assertStringContainsString('transaction reference', $res->json('skipped.0.reason'));
        $this->assertSame(0, SupplierPayment::where('purchase_order_id', $this->a->id)->count());

        $this->bulk([$this->a->id], ['method' => 'im_bank', 'reference' => 'FT26314XYZ', 'date_mode' => 'one_date', 'paid_at' => '2026-11-10', 'client_request_id' => 'b3'])->assertOk();
        $p = SupplierPayment::where('purchase_order_id', $this->a->id)->sole();
        $this->assertSame('2026-11-10', $p->paid_at->toDateString());
        $this->assertSame('FT26314XYZ', $p->reference);

        // one_date without a date is refused outright.
        $this->bulk([$this->b->id], ['date_mode' => 'one_date', 'client_request_id' => 'b4'])->assertStatus(422);
    }

    public function test_only_the_super_admin_and_only_after_re_confirming(): void
    {
        $finance = $this->person('finance_manager');
        Sanctum::actingAs($finance);
        $this->stepUp($finance);
        $this->bulk([$this->a->id])->assertStatus(403);

        $owner2 = $this->person('super_admin');   // has not re-confirmed
        Sanctum::actingAs($owner2);
        $this->assertNotSame(200, $this->bulk([$this->a->id])->status());

        $this->assertSame(0, SupplierPayment::where('purchase_order_id', $this->a->id)->count());
    }

    public function test_a_payment_marked_in_bulk_is_voided_like_any_other_and_the_debt_returns(): void
    {
        $this->bulk([$this->a->id])->assertOk();
        $p = SupplierPayment::where('purchase_order_id', $this->a->id)->sole();

        $this->postJson("/api/v1/admin/purchase-orders/{$this->a->id}/payments/{$p->id}/void", ['reason' => 'Marked paid in error'])
            ->assertOk();

        $this->assertNotNull($p->fresh()->voided_at, 'voided, never deleted');
        $this->assertSame('unpaid', $this->a->fresh()->payment_status);
        $this->assertEquals(4360.00, collect($this->getJson(self::OWED)->json('data'))->firstWhere('id', $this->a->id)['balance']);
    }
}
