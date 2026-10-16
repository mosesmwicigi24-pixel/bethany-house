<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Outlet;
use App\Models\User;
use App\Services\Expenses\ExpenseSuggestions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The New Expense form's suggestions (owner, 2026-10-16), learned from the
 * expenses the person may already see. Fixtures are written out by hand and
 * every expected figure is worked out independently below — the test does
 * not reuse the service's arithmetic.
 */
class ExpenseSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $shop;
    private Outlet $other;
    private int $utilities;
    private int $rent;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00', 'Africa/Nairobi'));
        $this->shop  = Outlet::factory()->create(['name' => 'Nairobi shop']);
        $this->other = Outlet::factory()->create(['name' => 'Mombasa shop']);
        $mk = fn ($n, $c) => DB::table('expense_categories')->insertGetId(['name' => $n, 'code' => $c, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->utilities = $mk('Sugg utilities', 'SGUTIL');
        $this->rent      = $mk('Sugg rent', 'SGRENT');
    }

    private function person(string $role, ?Outlet $outlet = null): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole(Role::findByName($role, 'sanctum'));
        $u->outlets()->attach(($outlet ?? $this->shop)->id, ['is_primary' => true]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function spent(User $by, string $date, string $title, float $amount, array $over = []): Expense
    {
        return Expense::create(array_merge([
            'title' => $title, 'category_id' => $this->utilities, 'amount' => $amount, 'currency_code' => 'KES',
            'exchange_rate' => 1, 'amount_kes' => $amount, 'expense_date' => $date, 'payment_method' => 'mpesa',
            'vendor_name' => 'Nairobi Water', 'outlet_id' => $this->shop->id, 'department' => 'Operations',
            'created_by' => $by->id, 'status' => 'approved',
        ], $over));
    }

    private function seedHistory(User $by): void
    {
        $this->spent($by, '2026-07-05', 'Water – July 2026', 1_800, ['payment_reference' => 'SG7AAA1111']);
        $this->spent($by, '2026-08-04', 'Water – August 2026', 1_850);
        $this->spent($by, '2026-09-06', 'Water – September 2026', 1_900, ['payment_reference' => 'SJ9BBB2222']);
        // Rejected: wrong, so not learned from.
        $this->spent($by, '2026-09-20', 'Water – September 2026', 99_999, ['status' => 'rejected']);
        // Rent twice (no cadence from two dates), paid by bank.
        $this->spent($by, '2026-08-01', 'Shop rent Aug', 45_000, ['category_id' => $this->rent, 'vendor_name' => 'Kamau Properties', 'payment_method' => 'bank_transfer']);
        $this->spent($by, '2026-09-01', 'Shop rent Sep', 45_000, ['category_id' => $this->rent, 'vendor_name' => 'Kamau Properties', 'payment_method' => 'bank_transfer']);
        // A one-off is not a usual.
        $this->spent($by, '2026-09-15', 'Plumber for the sink', 2_500, ['vendor_name' => 'Otieno Plumbing', 'payment_method' => 'cash']);
        // Older than 12 months: outside the window.
        $this->spent($by, '2025-09-01', 'Water – September 2025', 1_500);
    }

    public function test_titles_are_matched_without_their_dates_and_rolled_forward(): void
    {
        $oct = CarbonImmutable::parse('2026-10-16');
        $this->assertSame('water', ExpenseSuggestions::normalise('Water – September 2026'));
        $this->assertSame('water bill', ExpenseSuggestions::normalise('water bill sept 12/09'));
        $this->assertSame('shop rent', ExpenseSuggestions::normalise('Shop rent Aug'));

        $this->assertSame('Office rent – October 2026', ExpenseSuggestions::rollTitle('Office rent – June 2025', $oct));
        $this->assertSame('water bill oct', ExpenseSuggestions::rollTitle('water bill sept', $oct));
        $this->assertSame('RENT OCT 2026', ExpenseSuggestions::rollTitle('RENT JAN 2025', $oct));
        $this->assertSame('Plumber for the sink', ExpenseSuggestions::rollTitle('Plumber for the sink', $oct));
        $this->assertSame('Batch 2024 labels', ExpenseSuggestions::rollTitle('Batch 2024 labels', $oct), 'a year alone is not a date to roll');
    }

    public function test_usual_expenses_are_learned_from_history(): void
    {
        $maker = $this->person('outlet_manager');
        $this->seedHistory($maker);
        Sanctum::actingAs($maker);
        $before = Expense::count();

        $res = $this->getJson('/api/v1/admin/expenses/suggestions')->assertOk();

        // Counted by hand: 3 water + 2 rent + 1 plumber = 6 (the rejected and the year-old one are out).
        $this->assertSame(6, $res->json('based_on'));
        $usuals = collect($res->json('usuals'))->keyBy('norm');
        $this->assertSame(['water', 'shop rent'], $usuals->keys()->all(), 'due first; a one-off is not a usual');

        $water = $usuals['water'];
        $this->assertSame('Water – October 2026', $water['title_suggestion']);
        $this->assertSame($this->utilities, $water['category_id']);
        $this->assertSame('Nairobi Water', $water['vendor_name']);
        $this->assertSame('mpesa', $water['payment_method']);
        $this->assertSame($this->shop->id, $water['outlet_id']);
        $this->assertSame('Operations', $water['department']);
        $this->assertSame(3, $water['count']);
        // 1,800 / 1,850 / 1,900: median 1,850, range 1,800–1,900, last 1,900. The rejected 99,999 is not in it.
        $this->assertEquals(1_850, $water['median_amount']);
        $this->assertEquals(1_800, $water['low']);
        $this->assertEquals(1_900, $water['high']);
        $this->assertEquals(1_900, $water['last_amount']);
        // Gaps 30 and 33 days → median 31.5 → 32: monthly; 6 Sep + 32 = 8 Oct ≤ 16 Oct → due.
        $this->assertSame('monthly', $water['cadence']);
        $this->assertSame('2026-10-08', $water['next_due']);
        $this->assertSame('due', $water['due_state']);

        $rent = $usuals['shop rent'];
        $this->assertSame('Shop rent Oct', $rent['title_suggestion']);
        $this->assertSame('bank_transfer', $rent['payment_method']);
        $this->assertNull($rent['cadence'], 'two dates are not yet a pattern');

        // Category and vendor hints.
        $this->assertSame('Nairobi Water', $res->json("by_category.{$this->utilities}.vendor_name"));
        $this->assertSame('mpesa', $res->json("by_category.{$this->utilities}.payment_method"));
        $this->assertSame($this->rent, $res->json('by_vendor.kamau properties.category_id'));
        $this->assertSame('Nairobi Water', $res->json('vendors.0'));

        // Recent (45 days back from 16 Oct = from 1 Sep): rent Sep, water Sep, plumber.
        $recent = collect($res->json('recent'));
        $this->assertSame(['Shop rent Sep', 'Water – September 2026', 'Plumber for the sink'], $recent->pluck('title')->all());
        $this->assertSame('SJ9BBB2222', $recent->firstWhere('title', 'Water – September 2026')['payment_reference']);

        $this->assertSame($before, Expense::count(), 'suggestions never write');
    }

    public function test_an_expense_already_recorded_this_month_is_not_due(): void
    {
        $maker = $this->person('outlet_manager');
        $this->seedHistory($maker);
        $this->spent($maker, '2026-10-07', 'Water – October 2026', 1_880);
        Sanctum::actingAs($maker);

        $water = collect($this->getJson('/api/v1/admin/expenses/suggestions')->json('usuals'))->firstWhere('norm', 'water');
        $this->assertSame('done', $water['due_state']);
    }

    public function test_suggestions_come_only_from_expenses_the_person_may_see(): void
    {
        $owner = $this->person('outlet_manager');
        $this->seedHistory($owner);

        // Another outlet's manager learns nothing from this shop.
        Sanctum::actingAs($this->person('outlet_manager', $this->other));
        $res = $this->getJson('/api/v1/admin/expenses/suggestions')->assertOk();
        $this->assertSame(0, $res->json('based_on'));
        $this->assertSame([], $res->json('usuals'));
        $this->assertSame([], $res->json('vendors'));

        // Someone who cannot record expenses gets no suggestions at all.
        Sanctum::actingAs($this->person('pos_clerk'));
        $this->getJson('/api/v1/admin/expenses/suggestions')->assertForbidden();
    }

    public function test_an_empty_book_suggests_nothing(): void
    {
        Sanctum::actingAs($this->person('super_admin'));
        $this->getJson('/api/v1/admin/expenses/suggestions')->assertOk()
            ->assertJson(['based_on' => 0, 'usuals' => [], 'by_category' => [], 'by_vendor' => [], 'vendors' => [], 'recent' => []]);
    }
}
