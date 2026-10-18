<?php

namespace Tests\Feature;

use App\Console\Commands\HistoryRepairBudgets;
use App\Console\Commands\HistoryRepairTills;
use App\Models\AuditLog;
use App\Models\CashRegister;
use App\Models\CashRegisterBalance;
use App\Models\ExpenseBudget;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\TillCorrection;
use App\Models\User;
use App\Services\CurrencyPricing;
use App\Services\History\HistoryAudit;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Currency plan Step 6 (owner, 2026-10-18): the history audit finds every
 * class of wrong entry, and the mechanical repairs (tills, budgets) are
 * dry-run first, idempotent, logged, and never rewrite a finalized till.
 * Figures by hand: USD pricing rate 0.01 (100 KES/USD), reporting 128;
 * GBP has no reporting rate.
 */
class HistoryRepairTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;
    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outlet  = Outlet::factory()->create(['country_code' => 'KE']);
        $this->finance = User::factory()->create(['status' => 'active', 'email' => 'finance@example.test']);

        DB::table('currencies')->updateOrInsert(['code' => 'KES'], ['name' => 'Kenyan Shilling', 'symbol' => 'KES',
            'exchange_rate' => 1, 'reporting_rate_to_kes' => 1, 'is_base' => true, 'is_active' => true]);
        DB::table('currencies')->updateOrInsert(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$',
            'exchange_rate' => 0.01, 'reporting_rate_to_kes' => 128, 'is_base' => false, 'is_active' => true]);
        DB::table('currencies')->updateOrInsert(['code' => 'GBP'], ['name' => 'Pound', 'symbol' => '£',
            'exchange_rate' => 0.0065, 'reporting_rate_to_kes' => null, 'is_base' => false, 'is_active' => true]);
        foreach ([['KE', 'Kenya', 'KES'], ['US', 'United States', 'USD'], ['GB', 'United Kingdom', 'GBP']] as [$code, $name, $cur]) {
            DB::table('countries')->updateOrInsert(['code' => $code], ['name' => $name, 'default_currency_code' => $cur,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        ReportingCurrency::forget();
        CurrencyPricing::forget();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /**
     * A KES till as the old code left it: float 2,000, a KES 1,500 cash sale
     * and a USD 40 cash sale, the USD added to the shilling expected as if
     * it were shillings (3,540). Counted 3,500 → recorded short by 40.
     */
    private function tillWithForeignCash(bool $finalized): CashRegister
    {
        $r = CashRegister::create([
            'outlet_id' => $this->outlet->id, 'register_name' => $finalized ? 'Till F' : 'Till O',
            'status' => $finalized ? 'closed' : 'open', 'currency_code' => 'KES',
            'opening_balance' => 2000, 'expected_cash' => 3540, 'opened_by' => $this->finance->id, 'opened_at' => now()->subHours(8),
        ] + ($finalized ? [
            'actual_cash' => 3500, 'expected_cash_at_count' => 3540, 'expected_cash_running_at_count' => 3540,
            'variance' => -40, 'variance_class' => 'short', 'closed_by' => $this->finance->id, 'closed_at' => now(), 'finalized_at' => now(),
        ] : []));

        foreach ([['KES', 1500, 3500], ['USD', 40, 3540]] as [$cur, $amt, $after]) {
            DB::table('cash_register_transactions')->insert([
                'cash_register_id' => $r->id, 'transaction_type' => 'sale', 'payment_method' => 'cash',
                'amount' => $amt, 'currency_code' => $cur, 'balance_after' => $after, 'created_by' => $this->finance->id, 'created_at' => now(),
            ]);
        }
        // The home row as Step 2's migration mirrored it from the register.
        CashRegisterBalance::create([
            'cash_register_id' => $r->id, 'currency_code' => 'KES', 'is_home' => true, 'opening_balance' => 2000, 'expected_cash' => 3540,
        ] + ($finalized ? ['actual_cash' => 3500, 'expected_cash_at_count' => 3540, 'variance' => -40, 'variance_class' => 'short'] : []));

        return $r->fresh();
    }

    private function usdOrder(array $attrs = []): Order
    {
        return Order::factory()->create($attrs + [
            'outlet_id' => $this->outlet->id, 'currency_code' => 'USD', 'status' => 'confirmed', 'payment_status' => 'pending',
            'subtotal' => 1000, 'total_amount' => 1000, 'created_by' => $this->finance->id,
        ]);
    }

    // ── 1. tills ─────────────────────────────────────────────────────────────

    public function test_the_audit_lists_a_till_whose_ledger_holds_foreign_cash(): void
    {
        $till = $this->tillWithForeignCash(true);
        CashRegister::create(['outlet_id' => $this->outlet->id, 'register_name' => 'KES only', 'status' => 'open', 'currency_code' => 'KES',
            'opening_balance' => 500, 'expected_cash' => 500, 'opened_by' => $this->finance->id, 'opened_at' => now()]);

        $rows = app(HistoryAudit::class)->tills();

        $this->assertCount(1, $rows, 'the KES-only till is not listed');
        $t = $rows[0];
        $this->assertSame($till->id, $t['register_id']);
        $this->assertSame('KES', $t['home']);
        $this->assertEquals(3540.0, $t['stored_expected']);
        $this->assertEquals(3500.0, $t['ledger_expected'], '2,000 float + 1,500 KES sale; the USD 40 is not shillings');
        $this->assertEquals(40.0, $t['difference']);
        $this->assertEquals(-40.0, $t['stored_variance']);
        $this->assertEquals(0.0, $t['restated_variance'], 'counted 3,500 against 3,500 expected: balanced');
        $this->assertSame([['currency' => 'USD', 'net' => 40.0, 'has_balance_row' => false]],
            array_map(fn ($c) => ['currency' => $c['currency'], 'net' => $c['net'], 'has_balance_row' => $c['has_balance_row']], $t['currencies']));
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $till = $this->tillWithForeignCash(true);

        $this->assertSame(0, Artisan::call('history:repair-tills'));
        $out = Artisan::output();

        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertStringContainsString('3,540.00 → ledger 3,500.00', $out);
        $this->assertSame(0, CashRegisterBalance::where('cash_register_id', $till->id)->where('currency_code', 'USD')->count());
        $this->assertSame(0, TillCorrection::count());
        $this->assertSame(1, Artisan::call('history:repair-tills', ['--apply' => true]), 'no actor: refused, nothing written');
        $this->assertSame(0, TillCorrection::count());
    }

    public function test_applying_the_repair_restates_a_finalized_till_through_a_correction_and_a_usd_row(): void
    {
        $till = $this->tillWithForeignCash(true);

        $this->assertSame(0, Artisan::call('history:repair-tills', ['--apply' => true, '--actor' => 'finance@example.test']));

        $usd = CashRegisterBalance::where('cash_register_id', $till->id)->where('currency_code', 'USD')->first();
        $this->assertNotNull($usd);
        $this->assertFalse($usd->is_home);
        $this->assertEquals(40.0, $usd->expected_cash);
        $this->assertNull($usd->actual_cash, 'history was never counted per currency');
        $this->assertEquals(40.0, $usd->total_cash_sales);

        $c = TillCorrection::where('cash_register_id', $till->id)->first();
        $this->assertNotNull($c);
        $this->assertSame('KES', $c->currency_code);
        $this->assertSame($this->finance->id, $c->opened_by);
        $this->assertEquals(3540.0, $c->original_expected_cash);
        $this->assertEquals(3500.0, $c->corrected_expected_cash);
        $this->assertEquals(3500.0, $c->original_actual_cash);
        $this->assertEquals(-40.0, $c->original_variance);
        $this->assertEquals(0.0, $c->corrected_variance);
        $this->assertStringContainsString('USD 40.00', $c->reason);
        $this->assertStringStartsWith(HistoryRepairTills::REASON_PREFIX, $c->reason);

        // The finalized register itself is untouched: the correction is the restatement.
        $fresh = $till->fresh();
        $this->assertEquals(3540.0, $fresh->expected_cash_at_count);
        $this->assertEquals(-40.0, $fresh->variance);

        // Again: nothing doubled.
        $this->assertSame(0, Artisan::call('history:repair-tills', ['--apply' => true, '--actor' => (string) $this->finance->id]));
        $this->assertSame(1, CashRegisterBalance::where('cash_register_id', $till->id)->where('currency_code', 'USD')->count());
        $this->assertSame(1, TillCorrection::count());
    }

    public function test_an_open_till_is_put_right_in_place_as_well(): void
    {
        $till = $this->tillWithForeignCash(false);

        $this->assertSame(0, Artisan::call('history:repair-tills', ['--apply' => true, '--actor' => 'finance@example.test']));

        $this->assertEquals(3500.0, $till->fresh()->expected_cash);
        $this->assertEquals(3500.0, CashRegisterBalance::where('cash_register_id', $till->id)->where('currency_code', 'KES')->value('expected_cash'));
        $this->assertEquals(40.0, CashRegisterBalance::where('cash_register_id', $till->id)->where('currency_code', 'USD')->value('expected_cash'));
        $this->assertSame(1, TillCorrection::where('cash_register_id', $till->id)->count());
    }

    // ── 2–5. lines, shipping, payments, M-Pesa ───────────────────────────────

    public function test_the_audit_lists_borrowed_prices_kes_shipping_and_payments_in_the_wrong_currency(): void
    {
        $product = Product::factory()->create();
        foreach (['KES' => 1000, 'USD' => 10] as $cur => $price) {
            DB::table('product_prices')->insert(['product_id' => $product->id, 'product_variant_id' => null, 'currency_code' => $cur,
                'regular_price' => $price, 'created_at' => now(), 'updated_at' => now()]);
        }
        $zone = DB::table('shipping_zones')->insertGetId(['name' => 'Nairobi', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shipping_methods')->insert(['shipping_zone_id' => $zone, 'name' => 'Courier', 'cost_type' => 'flat_rate', 'flat_rate' => 500,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $wrong = $this->usdOrder(['shipping_amount' => 500, 'shipping_method' => 'Courier']);
        DB::table('order_items')->insert(['order_id' => $wrong->id, 'product_id' => $product->id, 'sku' => 'P-1', 'product_name' => 'Cassock',
            'quantity' => 1, 'unit_price' => 1000, 'total_price' => 1000, 'created_at' => now(), 'updated_at' => now()]);
        $right = $this->usdOrder(['shipping_amount' => 5]);
        DB::table('order_items')->insert(['order_id' => $right->id, 'product_id' => $product->id, 'sku' => 'P-1', 'product_name' => 'Cassock',
            'quantity' => 1, 'unit_price' => 10, 'total_price' => 10, 'created_at' => now(), 'updated_at' => now()]);

        Payment::factory()->create(['order_id' => $wrong->id, 'amount' => 1000, 'currency_code' => 'KES', 'payment_method' => 'cash', 'status' => 'paid']);
        Payment::factory()->create(['order_id' => $wrong->id, 'amount' => 1280, 'currency_code' => 'USD', 'payment_method' => 'mpesa', 'status' => 'paid',
            'provider_transaction_id' => 'QAB12']);
        Payment::factory()->create(['order_id' => $right->id, 'amount' => 10, 'currency_code' => 'USD', 'payment_method' => 'card', 'status' => 'paid']);

        $audit = app(HistoryAudit::class);

        $lines = $audit->linesPricedInAnotherCurrency();
        $this->assertCount(1, $lines);
        $this->assertSame($wrong->order_number, $lines[0]['order_number']);
        $this->assertSame('KES', $lines[0]['priced_in']);
        $this->assertEquals(1000.0, $lines[0]['unit_price']);
        $this->assertEquals(10.0, $lines[0]['expected'], 'KES 1,000 at the 100 KES/USD pricing rate');

        $ship = $audit->kesShippingOnForeignOrders();
        $this->assertCount(1, $ship);
        $this->assertSame($wrong->order_number, $ship[0]['order_number']);
        $this->assertSame('Courier', $ship[0]['method']);
        $this->assertEquals(5.0, $ship[0]['expected'], 'KES 500 courier → USD 5.00');

        $pay = $audit->paymentsInAnotherCurrency();
        $this->assertCount(1, $pay);
        $this->assertSame(['KES', 'USD', 1000.0], [$pay[0]['payment_currency'], $pay[0]['order_currency'], $pay[0]['amount']]);

        $mp = $audit->mpesaOnForeignOrders();
        $this->assertCount(1, $mp);
        $this->assertSame('QAB12', $mp[0]['mpesa_receipt']);
        $this->assertEquals(1280.0, $mp[0]['amount_received']);
    }

    // ── 6. no rate ───────────────────────────────────────────────────────────

    public function test_records_in_a_currency_without_a_reporting_rate_are_listed(): void
    {
        Quotation::create(['quote_number' => 'Q-GBP', 'currency_code' => 'GBP', 'status' => 'draft', 'subtotal' => 100, 'total_amount' => 100, 'customer_first_name' => 'A']);
        Quotation::create(['quote_number' => 'Q-USD', 'currency_code' => 'USD', 'status' => 'draft', 'subtotal' => 100, 'total_amount' => 100, 'customer_first_name' => 'B']);
        $this->usdOrder(['currency_code' => 'GBP']);

        $nr = app(HistoryAudit::class)->recordsWithoutRate();

        $this->assertSame(['GBP'], $nr['currencies']);
        $this->assertSame(1, $nr['tables']['quotations']['count']);
        $this->assertSame(['Q-GBP (GBP)'], $nr['tables']['quotations']['references']);
        $this->assertSame(1, $nr['tables']['orders']['count']);
        $this->assertSame(0, $nr['tables']['payments']['count']);
    }

    // ── 7. budgets ───────────────────────────────────────────────────────────

    public function test_budgets_in_dollars_are_converted_at_the_reporting_rate_with_the_original_kept(): void
    {
        $cat = DB::table('expense_categories')->insertGetId(['name' => 'Fuel', 'code' => 'FUEL', 'created_at' => now(), 'updated_at' => now()]);
        $usd = ExpenseBudget::create(['category_id' => $cat, 'period_type' => 'monthly', 'period_year' => 2026, 'period_number' => 9,
            'budgeted_amount' => 1000, 'currency_code' => 'USD', 'created_by' => $this->finance->id, 'notes' => 'Q3 fuel']);
        $gbp = ExpenseBudget::create(['category_id' => $cat, 'period_type' => 'monthly', 'period_year' => 2026, 'period_number' => 10,
            'budgeted_amount' => 50, 'currency_code' => 'GBP', 'created_by' => $this->finance->id]);
        $kes = ExpenseBudget::create(['category_id' => $cat, 'period_type' => 'monthly', 'period_year' => 2026, 'period_number' => 11,
            'budgeted_amount' => 7000, 'currency_code' => 'KES', 'created_by' => $this->finance->id]);

        $listed = app(HistoryAudit::class)->foreignBudgets();
        $this->assertCount(2, $listed);
        $this->assertEquals(128000.0, $listed->firstWhere('budget_id', $usd->id)['amount_kes'], 'USD 1,000 × 128');
        $this->assertNull($listed->firstWhere('budget_id', $gbp->id)['amount_kes'], 'no rate, no guess');

        $this->assertSame(0, Artisan::call('history:repair-budgets'));
        $this->assertSame('USD', $usd->fresh()->currency_code, 'dry run');

        $this->assertSame(0, Artisan::call('history:repair-budgets', ['--apply' => true, '--actor' => 'finance@example.test']));
        $usd->refresh();
        $this->assertSame('KES', $usd->currency_code);
        $this->assertEquals(128000.0, $usd->budgeted_amount);
        $this->assertStringContainsString('Q3 fuel', $usd->notes);
        $this->assertStringContainsString('Converted from USD 1,000.00 at 128 KES/USD', $usd->notes);
        $this->assertSame('GBP', $gbp->fresh()->currency_code, 'left alone: no rate');
        $this->assertEquals(7000.0, $kes->fresh()->budgeted_amount);

        $log = AuditLog::where('action', HistoryRepairBudgets::ACTION)->get();
        $this->assertCount(1, $log);
        $this->assertSame($this->finance->id, $log[0]->user_id);
        $this->assertSame($usd->id, (int) $log[0]->entity_id);
        $this->assertEquals(['budgeted_amount' => 1000, 'currency_code' => 'USD'], $log[0]->old_values);
        $this->assertEquals(['budgeted_amount' => 128000, 'currency_code' => 'KES', 'rate' => 128], $log[0]->new_values);

        // Again: nothing converted twice, the note not repeated.
        $this->assertSame(0, Artisan::call('history:repair-budgets', ['--apply' => true, '--actor' => 'finance@example.test']));
        $this->assertSame(1, AuditLog::where('action', HistoryRepairBudgets::ACTION)->count());
        $this->assertSame(1, substr_count((string) $usd->fresh()->notes, 'Converted from'));
    }

    // ── 8. outlets, and the command as a whole ───────────────────────────────

    public function test_the_audit_command_runs_read_only_and_lists_a_uk_outlet(): void
    {
        Outlet::factory()->create(['country_code' => 'GB', 'name' => 'London']);
        $till = $this->tillWithForeignCash(true);

        $this->assertSame(0, Artisan::call('history:audit'));
        $out = Artisan::output();

        $this->assertStringContainsString('1. Foreign cash rung through a till: 1 tills', $out);
        $this->assertStringContainsString('8. Outlets whose country prices in another currency: 1 outlets', $out);
        $this->assertStringContainsString('London', $out);
        $this->assertStringContainsString('GBP', $out);
        $this->assertSame(0, TillCorrection::count());
        $this->assertSame(0, CashRegisterBalance::where('cash_register_id', $till->id)->where('currency_code', 'USD')->count(), 'the audit writes nothing');
    }
}
