<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\CashRegisterBalance;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Services\Auth\TerminalPin;
use App\Services\CurrencyPricing;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Currency plan Step 2 (owner, 2026-10-18): one till, every currency apart.
 *
 * A Kenyan till rings a US customer in dollars. The dollars go into the
 * till's USD row — its own float, expected cash, count and variance — and
 * never into the shilling figure. Every number below is worked by hand:
 * USD pricing rate 0.01 (100 KES/USD), reporting rate 128 KES/USD; GBP has
 * no reporting rate.
 */
class TillMultiCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const PIN_OM = '4821';

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        Artisan::call('permission:sync');
        $this->outlet = Outlet::factory()->create(['country_code' => 'KE']);

        DB::table('currencies')->updateOrInsert(['code' => 'KES'], ['name' => 'Kenyan Shilling', 'symbol' => 'KES',
            'exchange_rate' => 1, 'reporting_rate_to_kes' => 1, 'is_base' => true, 'is_active' => true]);
        DB::table('currencies')->updateOrInsert(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$',
            'exchange_rate' => 0.01, 'reporting_rate_to_kes' => 128, 'is_base' => false, 'is_active' => true]);
        DB::table('currencies')->updateOrInsert(['code' => 'GBP'], ['name' => 'Pound', 'symbol' => '£',
            'exchange_rate' => 0.0065, 'reporting_rate_to_kes' => null, 'is_base' => false, 'is_active' => true]);
        foreach ([['KE', 'Kenya', 'KES'], ['US', 'United States', 'USD']] as [$code, $name, $cur]) {
            DB::table('countries')->updateOrInsert(['code' => $code], ['name' => $name, 'default_currency_code' => $cur,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        ReportingCurrency::forget();
        CurrencyPricing::forget();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function person(string $role, bool $atOutlet = true): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole(Role::findByName($role, 'sanctum'));
        if ($atOutlet) {
            $u->outlets()->attach($this->outlet->id);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function as(User $u): User
    {
        $this->app['auth']->forgetGuards();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u);

        return $u;
    }

    /** A till opened through the API with a KES float and, optionally, foreign floats. */
    private function openTill(User $cashier, float $kesFloat = 2000, array $floats = []): CashRegister
    {
        $this->as($cashier);
        $this->postJson('/api/v1/admin/pos/register/open', [
            'outlet_id' => $this->outlet->id, 'opening_cash' => $kesFloat, 'opening_floats' => $floats ?: null,
        ])->assertCreated();

        return CashRegister::where('opened_by', $cashier->id)->latest('id')->firstOrFail();
    }

    private function product(float $kes): Product
    {
        $p = Product::factory()->create();
        DB::table('product_translations')->insert(['product_id' => $p->id, 'language_code' => 'en', 'name' => 'Chasuble', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_prices')->insert(['product_id' => $p->id, 'product_variant_id' => null, 'currency_code' => 'KES',
            'regular_price' => $kes, 'cost_price' => 100, 'created_at' => now(), 'updated_at' => now()]);
        InventoryItem::create(['product_id' => $p->id, 'product_variant_id' => null, 'outlet_id' => $this->outlet->id,
            'quantity_on_hand' => 100, 'quantity_reserved' => 0, 'reorder_point' => 0]);

        return $p;
    }

    /** A cash sale at the till, paid in full in the customer's currency. */
    private function cashSale(User $cashier, float $kesPrice, ?string $country, float $tendered): Order
    {
        $this->as($cashier);
        $res = $this->postJson('/api/v1/admin/pos/sales', array_filter([
            'outlet_id'             => $this->outlet->id,
            'customer_first_name'   => 'Walk-in',
            'customer_country_code' => $country,
            'payment_method'        => 'cash',
            'items'                 => [['product_id' => $this->product($kesPrice)->id, 'quantity' => 1, 'unit_price' => $tendered]],
            'payments'              => [['method' => 'cash', 'amount' => $tendered, 'cash_received' => $tendered]],
        ]))->assertCreated();

        return Order::withoutViewerScope()->findOrFail($res->json('order.id'));
    }

    private function submitEod(CashRegister $r): void
    {
        if (DB::table('cash_register_eod_reports')->where('register_id', $r->id)->exists()) {
            return;
        }
        DB::table('cash_register_eod_reports')->insert([
            'register_id' => $r->id, 'user_id' => $r->opened_by, 'outlet_id' => $r->outlet_id,
            'report_date' => today(), 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function submitCount(User $cashier, CashRegister $r, float $kes, array $counts = [])
    {
        $this->submitEod($r);
        $this->as($cashier);

        return $this->postJson('/api/v1/admin/pos/register/close', [
            'outlet_id' => $r->outlet_id, 'closing_cash' => $kes, 'counts' => $counts ?: null,
        ]);
    }

    private function row(CashRegister $r, string $code): ?CashRegisterBalance
    {
        return CashRegisterBalance::where('cash_register_id', $r->id)->where('currency_code', $code)->first();
    }

    // ── the drawer ───────────────────────────────────────────────────────────

    public function test_a_dollar_sale_fills_the_tills_usd_row_and_never_its_shilling_figure(): void
    {
        $cashier = $this->person('outlet_manager');
        $till    = $this->openTill($cashier, 2000);

        $kesSale = $this->cashSale($cashier, 1500, null, 1500);
        $usdSale = $this->cashSale($cashier, 4000, 'US', 40);       // KES 4,000 at 0.01 = USD 40
        $this->assertSame('KES', $kesSale->currency_code);
        $this->assertSame('USD', $usdSale->currency_code);

        // KES: 2,000 + 1,500. USD: 0 + 40. Never 3,540.
        $this->assertEquals(3500, $this->row($till, 'KES')->expected_cash);
        $this->assertEquals(40,   $this->row($till, 'USD')->expected_cash);
        $this->assertEquals(0,    $this->row($till, 'USD')->opening_balance);
        $this->assertTrue((bool) $this->row($till, 'KES')->is_home);

        // The register's own columns are the shilling row, as every older reader expects.
        $till->refresh();
        $this->assertEquals(3500, $till->expected_cash);
        $this->assertEquals(1500, $till->total_sales);
        $this->assertEquals(1500, $till->total_cash_sales);
        $this->assertSame(1, (int) $till->transaction_count);
        $this->assertSame(1, (int) $this->row($till, 'USD')->transaction_count);

        // Each ledger row names its currency.
        $this->assertDatabaseHas('cash_register_transactions', ['order_id' => $kesSale->id, 'currency_code' => 'KES', 'amount' => 1500, 'balance_after' => 3500]);
        $this->assertDatabaseHas('cash_register_transactions', ['order_id' => $usdSale->id, 'currency_code' => 'USD', 'amount' => 40, 'balance_after' => 40]);
    }

    public function test_a_foreign_float_at_opening_must_be_a_currency_the_books_can_state_in_shillings(): void
    {
        $cashier = $this->person('outlet_manager');
        $this->as($cashier);

        $this->postJson('/api/v1/admin/pos/register/open', [
            'outlet_id' => $this->outlet->id, 'opening_cash' => 1000, 'opening_floats' => ['GBP' => 20],
        ])->assertStatus(422)->assertJsonValidationErrors('opening_floats.GBP');
        $this->assertSame(0, CashRegister::count(), 'nothing opened');

        $till = $this->openTill($cashier, 1000, ['USD' => 50]);
        $this->assertEquals(50, $this->row($till, 'USD')->opening_balance);
        $this->assertEquals(50, $this->row($till, 'USD')->expected_cash);
        $this->assertEquals(1000, $till->expected_cash, 'the shilling figure is the shilling float alone');
    }

    // ── count → finalize ─────────────────────────────────────────────────────

    public function test_each_currency_is_counted_apart_and_a_short_dollar_drawer_is_a_discrepancy_in_its_own_currency(): void
    {
        $cashier  = $this->person('outlet_manager');
        $verifier = $this->person('outlet_manager');
        $till     = $this->openTill($cashier, 2000);
        $this->cashSale($cashier, 1500, null, 1500);
        $this->cashSale($cashier, 4000, 'US', 40);

        // The dollars must be counted too: the till took them.
        $this->submitCount($cashier, $till, 3500)->assertStatus(422)->assertJsonValidationErrors('counts');
        $this->assertSame('open', $till->fresh()->status);

        $this->submitCount($cashier, $till, 3500, [['currency_code' => 'USD', 'counted_cash' => 35]])->assertOk();

        $kes = $this->row($till, 'KES');
        $usd = $this->row($till, 'USD');
        $this->assertEquals(3500, $kes->expected_cash_at_count);
        $this->assertEquals(0,    $kes->variance);
        $this->assertSame('balanced', $kes->variance_class);
        $this->assertEquals(40,   $usd->expected_cash_at_count);
        $this->assertEquals(35,   $usd->actual_cash);
        $this->assertEquals(-5,   $usd->variance);
        $this->assertSame('short', $usd->variance_class);
        $till->refresh();
        $this->assertEquals(0, $till->variance, 'the register column is the shilling row');

        // The shillings balance, but the till is short: the reason is required.
        $this->as($verifier);
        $this->postJson("/api/v1/admin/pos/tills/{$till->id}/finalize")->assertStatus(422)->assertJsonValidationErrors('variance_reason');

        $res = $this->postJson("/api/v1/admin/pos/tills/{$till->id}/finalize", ['variance_reason' => 'Five dollars unaccounted for'])->assertOk();

        // One discrepancy, in dollars: USD 5 = KES 640 at the reporting rate, above the 100 band.
        $this->assertSame(1, DB::table('till_discrepancies')->where('cash_register_id', $till->id)->count());
        $d = DB::table('till_discrepancies')->where('cash_register_id', $till->id)->first();
        $this->assertSame('USD', $d->currency_code);
        $this->assertEquals(-5, $d->amount);
        $this->assertEquals(40, $d->expected_cash);
        $this->assertEquals(35, $d->counted_cash);
        $this->assertSame('awaiting_approval', $d->status);
        $this->assertNull($res->json('till.discrepancy'), 'no shilling discrepancy');
        $this->assertSame('USD', $res->json('till.discrepancies.0.currency_code'));
        $this->assertEquals(-640, $res->json('till.discrepancies.0.amount_kes'));
        $this->assertStringContainsString('held for approval', $res->json('message'));

        // Locked with the till.
        $this->assertNotNull($till->fresh()->finalized_at);
        $this->expectException(\Throwable::class);
        DB::table('cash_register_balances')->where('id', $usd->id)->update(['actual_cash' => 40]);
    }

    public function test_a_small_dollar_variance_is_banded_in_shillings(): void
    {
        $cashier  = $this->person('outlet_manager');
        $verifier = $this->person('outlet_manager');
        $till     = $this->openTill($cashier, 2000);
        $this->cashSale($cashier, 4000, 'US', 40);

        // USD 0.50 short = KES 64 at 128: logged, not held.
        $this->submitCount($cashier, $till, 2000, [['currency_code' => 'USD', 'counted_cash' => 39.5]])->assertOk();
        $this->as($verifier);
        $this->postJson("/api/v1/admin/pos/tills/{$till->id}/finalize", ['variance_reason' => 'Coin short'])->assertOk()
            ->assertJsonPath('till.discrepancies.0.status', 'logged')
            ->assertJsonPath('till.discrepancies.0.amount_kes', -64);
    }

    public function test_the_operator_counts_blind_in_every_currency_and_the_verifier_sees_each_plus_a_shilling_summary(): void
    {
        $cashier  = $this->person('outlet_manager');
        $verifier = $this->person('outlet_manager');
        $till     = $this->openTill($cashier, 2000);
        $this->cashSale($cashier, 1500, null, 1500);
        $this->cashSale($cashier, 4000, 'US', 40);
        $this->submitCount($cashier, $till, 3500, [['currency_code' => 'USD', 'counted_cash' => 40]])->assertOk();

        // Blind: her counts per currency, nothing expected.
        $this->as($cashier);
        $mine = $this->getJson("/api/v1/admin/pos/tills/{$till->id}")->assertOk()->json('till');
        $this->assertTrue($mine['blind']);
        $this->assertSame(['KES', 'USD'], array_column($mine['balances'], 'currency_code'));
        $this->assertEquals(40, $mine['balances'][1]['counted_cash']);
        foreach ($mine['balances'] as $b) {
            $this->assertArrayNotHasKey('expected_cash', $b);
            $this->assertArrayNotHasKey('variance', $b);
            $this->assertArrayNotHasKey('total_cash_sales', $b);
        }
        $this->assertArrayNotHasKey('kes_summary', $mine);

        // The verifier: each currency, and the whole till in shillings at the reporting rate.
        $this->as($verifier);
        $full = $this->getJson("/api/v1/admin/pos/tills/{$till->id}")->assertOk()->json('till');
        $this->assertFalse($full['blind']);
        $this->assertSame('KES', $full['currency_code']);
        $this->assertEquals(3500, $full['balances'][0]['expected_cash_at_count']);
        $this->assertEquals(40,   $full['balances'][1]['expected_cash_at_count']);
        $this->assertEquals(40,   $full['balances'][1]['breakdown']['cash_sales']);
        $this->assertEquals(0,    $full['balances'][1]['variance']);
        // 3,500 + 40 × 128 = 8,620
        $this->assertSame('reporting rate', $full['kes_summary']['basis']);
        $this->assertEquals(8620, $full['kes_summary']['expected']);
        $this->assertEquals(8620, $full['kes_summary']['counted']);
        $this->assertEquals(0,    $full['kes_summary']['variance']);
        $this->assertSame([], $full['kes_summary']['unconverted']);
    }

    // ── reconcile and correct, per currency ──────────────────────────────────

    public function test_the_accountant_reconciles_each_currency_against_payments_in_that_currency(): void
    {
        $cashier    = $this->person('outlet_manager');
        $verifier   = $this->person('outlet_manager');
        $accountant = $this->person('accountant', false);
        $finance    = $this->person('finance_manager', false);
        $till       = $this->openTill($cashier, 2000);
        $this->cashSale($cashier, 1500, null, 1500);
        $this->cashSale($cashier, 4000, 'US', 40);
        $this->submitCount($cashier, $till, 3500, [['currency_code' => 'USD', 'counted_cash' => 35]])->assertOk();
        $this->as($verifier);
        $this->postJson("/api/v1/admin/pos/tills/{$till->id}/finalize", ['variance_reason' => 'Short five dollars'])->assertOk();

        $this->as($accountant);
        $res = $this->postJson("/api/v1/admin/pos/tills/{$till->id}/reconcile")->assertCreated();
        $recs = collect($res->json('reconciliations'))->keyBy('currency_code');
        $this->assertSame(['KES', 'USD'], $recs->keys()->sort()->values()->all());
        $this->assertSame('matched', $recs['KES']['status']);
        $this->assertEquals(1500, $recs['KES']['till_cash_sales']);
        $this->assertEquals(1500, $recs['KES']['payments_cash'], 'the USD 40 payment is never set against shillings');
        $this->assertSame('matched', $recs['USD']['status']);
        $this->assertEquals(40, $recs['USD']['till_cash_sales']);
        $this->assertEquals(40, $recs['USD']['payments_cash']);
        $this->assertSame('KES', $res->json('reconciliation.currency_code'), 'the home row keeps its old place');
        $this->assertSame(2, DB::table('till_reconciliations')->where('cash_register_id', $till->id)->count());

        // Finance corrects the dollar row only; the shilling row and the till are untouched.
        $this->as($finance);
        $c = $this->postJson("/api/v1/admin/pos/tills/{$till->id}/corrections", [
            'reason' => 'Five dollars found in the safe drawer', 'currency_code' => 'USD', 'corrected_actual_cash' => 40,
        ])->assertCreated()->json('correction');
        $this->assertSame('USD', $c['currency_code']);
        $this->assertEquals(35, $c['original_actual_cash']);
        $this->assertEquals(-5, $c['original_variance']);
        $this->assertEquals(0,  $c['corrected_variance']);
        $this->assertEquals(35, $this->row($till, 'USD')->actual_cash, 'the original row is never rewritten');
        $this->assertEquals(0,  $till->fresh()->variance);

        $this->postJson("/api/v1/admin/pos/tills/{$till->id}/corrections", [
            'reason' => 'Not a currency this till holds', 'currency_code' => 'GBP',
        ])->assertStatus(422)->assertJsonValidationErrors('currency_code');
    }

    // ── reversals ────────────────────────────────────────────────────────────

    public function test_a_dollar_refund_comes_out_of_the_dollar_drawer_and_the_shillings_beside_it_do_not_count(): void
    {
        $cashier  = $this->person('outlet_manager');
        $approver = $this->person('outlet_manager');
        app(TerminalPin::class)->set($approver, self::PIN_OM);
        $till = $this->openTill($cashier, 2000);
        // KES 3,000 at 0.01 = USD 30 — KES 3,840 at the reporting rate, inside
        // the outlet manager's refund band (≤ 5,000), so one PIN signs it.
        $sale = $this->cashSale($cashier, 3000, 'US', 30);

        // The dollars have been banked; KES 2,000 sits in the drawer. A USD refund cannot come out of it.
        DB::table('cash_register_balances')->where('id', $this->row($till, 'USD')->id)->update(['expected_cash' => 5]);
        $this->as($cashier);
        $ask = fn () => $this->postJson('/api/v1/admin/pos/returns', [
            'original_order_id' => $sale->id, 'reason' => 'Wrong size', 'refund_method' => 'cash',
            'items' => [['variant_id' => null, 'quantity' => 1]],
        ]);
        $ask()->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_CASH');
        $this->assertEquals(2000, $till->fresh()->expected_cash, 'the shilling drawer is untouched');

        // With the dollars in the drawer it goes through, signed by the outlet manager's PIN.
        DB::table('cash_register_balances')->where('id', $this->row($till, 'USD')->id)->update(['expected_cash' => 30]);
        $approvalId = $ask()->assertStatus(202)->json('approval.id');
        $this->postJson("/api/v1/admin/pos/approvals/{$approvalId}/pin-sign", ['approver_id' => $approver->id, 'pin' => self::PIN_OM])
            ->assertOk()->assertJsonPath('request.status', 'approved');

        $this->assertEquals(0,  $this->row($till, 'USD')->expected_cash);
        $this->assertEquals(30, $this->row($till, 'USD')->total_refunds);
        $this->assertEquals(2000, $till->fresh()->expected_cash);
        $this->assertEquals(0,    $till->fresh()->total_refunds, 'the register columns are the shilling row');
        $this->assertDatabaseHas('cash_register_transactions', ['order_id' => $sale->id, 'transaction_type' => 'refund', 'currency_code' => 'USD', 'amount' => 30]);
    }

    public function test_voiding_a_dollar_sale_reverses_the_dollar_row_only(): void
    {
        $cashier  = $this->person('outlet_manager');
        $approver = $this->person('outlet_manager');
        app(TerminalPin::class)->set($approver, self::PIN_OM);
        $till = $this->openTill($cashier, 2000);
        $this->cashSale($cashier, 1500, null, 1500);
        $sale = $this->cashSale($cashier, 4000, 'US', 40);

        $this->as($cashier);
        $approvalId = $this->postJson("/api/v1/admin/pos/sales/{$sale->id}/void", ['reason' => 'Rung twice'])->assertStatus(202)->json('approval.id');
        $this->postJson("/api/v1/admin/pos/approvals/{$approvalId}/pin-sign", ['approver_id' => $approver->id, 'pin' => self::PIN_OM])
            ->assertOk()->assertJsonPath('request.status', 'approved');

        $usd = $this->row($till, 'USD');
        $this->assertEquals(0, $usd->expected_cash);
        $this->assertEquals(0, $usd->total_sales);
        $this->assertSame(0, (int) $usd->transaction_count);
        $till->refresh();
        $this->assertEquals(3500, $till->expected_cash);
        $this->assertEquals(1500, $till->total_sales);
        $this->assertSame(1, (int) $till->transaction_count);
        $this->assertDatabaseHas('cash_register_transactions', ['order_id' => $sale->id, 'transaction_type' => 'void', 'currency_code' => 'USD', 'amount' => 40]);
    }

    // ── a shilling-only till is exactly as before ────────────────────────────

    public function test_a_shilling_only_till_counts_finalizes_and_reads_exactly_as_before(): void
    {
        $cashier  = $this->person('outlet_manager');
        $verifier = $this->person('outlet_manager');
        $till     = $this->openTill($cashier, 2000);
        $this->cashSale($cashier, 1500, null, 1500);

        $this->submitCount($cashier, $till, 3440)->assertOk();          // short 60: logged
        $this->as($verifier);
        $res = $this->postJson("/api/v1/admin/pos/tills/{$till->id}/finalize", ['variance_reason' => 'Change given twice'])->assertOk();
        $res->assertJsonPath('till.discrepancy.status', 'logged')
            ->assertJsonPath('till.discrepancy.currency_code', 'KES')
            ->assertJsonPath('till.variance', -60)
            ->assertJsonPath('till.kes_summary.variance', -60);
        $this->assertCount(1, $res->json('till.balances'));
        $this->assertSame(1, DB::table('till_discrepancies')->where('cash_register_id', $till->id)->count());
    }
}
