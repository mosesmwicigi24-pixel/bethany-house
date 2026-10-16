<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\User;
use App\Services\CurrencyPricing;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A foreign-currency expense is stated in KES at the REPORTING rate
 * (currencies.reporting_rate_to_kes — 128 KES per USD), never the customer
 * pricing rate (100), and never at a guess. Before this, the lookup read a
 * column that does not exist and every USD/EUR/GBP expense failed with 500.
 * CLAUDE.md §6.
 */
class ExpenseCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        ReportingCurrency::forget();
        CurrencyPricing::forget();

        // Pricing 100 KES/USD (exchange_rate 0.01, base-relative); reporting 128.
        DB::table('currencies')->updateOrInsert(['code' => 'KES'], ['name' => 'Kenyan Shilling', 'symbol' => 'KES',
            'exchange_rate' => 1, 'reporting_rate_to_kes' => 1, 'is_base' => true, 'is_active' => true]);
        DB::table('currencies')->updateOrInsert(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$',
            'exchange_rate' => 0.01, 'reporting_rate_to_kes' => 128, 'is_base' => false, 'is_active' => true]);
        // A currency with no reporting rate set.
        DB::table('currencies')->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€',
            'exchange_rate' => 0.0085, 'reporting_rate_to_kes' => null, 'is_base' => false, 'is_active' => true]);
        ReportingCurrency::forget();

        $this->categoryId = DB::table('expense_categories')->insertGetId([
            'name' => 'FX test travel', 'code' => 'FXTRAV', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole(Role::findByName('super_admin', 'sanctum'));
        Sanctum::actingAs($u->fresh());
    }

    private function create(array $over = [])
    {
        return $this->postJson('/api/v1/admin/expenses', array_merge([
            'title' => 'Conference fee', 'category_id' => $this->categoryId, 'expense_date' => now()->toDateString(),
            'amount' => 250, 'currency_code' => 'USD', 'payment_method' => 'card',
        ], $over));
    }

    public function test_a_usd_expense_is_stated_in_kes_at_the_reporting_rate(): void
    {
        $id = $this->create()->assertCreated()->json('expense.id');

        // Independent arithmetic: 250 × 128 = 32,000 (the pricing rate would say 25,000).
        $e = Expense::findOrFail($id);
        $this->assertSame('32000.00', (string) $e->amount_kes);
        $this->assertSame(128.0, (float) $e->exchange_rate);
        $this->assertSame('250.00', (string) $e->amount, 'the native amount is kept as entered');
    }

    public function test_a_currency_without_a_reporting_rate_is_refused_not_guessed(): void
    {
        $this->create(['currency_code' => 'EUR'])
            ->assertStatus(422)
            ->assertJsonPath('errors.currency_code.0', 'There is no reporting rate for EUR. Ask finance to set one, or record the expense in KES.');
        $this->assertSame(0, Expense::count(), 'nothing is saved');
    }

    public function test_a_rate_given_with_the_expense_is_kept_and_kes_is_always_one(): void
    {
        $id = $this->create(['exchange_rate' => 131.5, 'amount' => 10])->assertCreated()->json('expense.id');
        $this->assertSame('1315.00', (string) Expense::findOrFail($id)->amount_kes);

        $kes = $this->create(['currency_code' => 'KES', 'amount' => 4_200, 'exchange_rate' => 128])->assertCreated()->json('expense.id');
        $e = Expense::findOrFail($kes);
        $this->assertSame('4200.00', (string) $e->amount_kes, 'a shilling is a shilling');
        $this->assertSame(1.0, (float) $e->exchange_rate);
    }

    public function test_an_edit_keeps_the_booked_rate_until_the_currency_changes(): void
    {
        $id = $this->create(['amount' => 100])->assertCreated()->json('expense.id');

        // The reporting rate moves later: the booked expense keeps its snapshot.
        DB::table('currencies')->where('code', 'USD')->update(['reporting_rate_to_kes' => 140]);
        ReportingCurrency::forget();
        $this->putJson("/api/v1/admin/expenses/{$id}", ['amount' => 200])->assertOk();
        $this->assertSame('25600.00', (string) Expense::findOrFail($id)->amount_kes);   // 200 × 128

        // Switching to KES re-states it at 1, not at the dollar rate.
        $this->putJson("/api/v1/admin/expenses/{$id}", ['currency_code' => 'KES', 'amount' => 9_000])->assertOk();
        $e = Expense::findOrFail($id);
        $this->assertSame('9000.00', (string) $e->amount_kes);
        $this->assertSame(1.0, (float) $e->exchange_rate);

        // Back to USD with no rate given: today's reporting rate (140).
        $this->putJson("/api/v1/admin/expenses/{$id}", ['currency_code' => 'USD', 'amount' => 50])->assertOk();
        $this->assertSame('7000.00', (string) Expense::findOrFail($id)->amount_kes);

        // A new rate alone re-states the KES figure too.
        $this->putJson("/api/v1/admin/expenses/{$id}", ['exchange_rate' => 130])->assertOk();
        $this->assertSame('6500.00', (string) Expense::findOrFail($id)->amount_kes);

        // And to a currency with no reporting rate: refused, the row unchanged.
        $this->putJson("/api/v1/admin/expenses/{$id}", ['currency_code' => 'EUR'])->assertStatus(422);
        $this->assertSame('6500.00', (string) Expense::findOrFail($id)->amount_kes);
    }
}
