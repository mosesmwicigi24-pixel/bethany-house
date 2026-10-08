<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 4A anti-scraping (plan §9) on customer search and the till's
 * autocomplete:
 *   - at least 3 characters, never wildcard-only (a "%" or "_" is a letter
 *     to search for, not a pattern);
 *   - at most 20 results;
 *   - 30 searches a minute per user (the `customer-search` rate limiter);
 *   - the autocomplete returns id + name + MASKED phone, nothing else;
 *   - scoped BEFORE matching: a search never reaches past the caller's
 *     customers. The one exception is the whole phone number of the person
 *     standing at the till — knowing it is the point, and they get a masked
 *     echo of what they typed.
 */
class CustomerSearchAntiScrapingTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $a;
    private Outlet $b;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        $this->a = Outlet::factory()->create();
        $this->b = Outlet::factory()->create();
    }

    private function as(string $role, ?Outlet $outlet = null): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName($role, 'sanctum'));
        if ($outlet) {
            $user->outlets()->attach($outlet->id);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user = $user->fresh());

        return $user;
    }

    private function customerAt(Outlet $outlet, string $first, string $phone): Customer
    {
        $c = Customer::create(['first_name' => $first, 'last_name' => 'Test', 'phone' => $phone, 'email' => strtolower($first) . '@example.test']);
        Order::factory()->create(['outlet_id' => $outlet->id, 'customer_id' => $c->id, 'order_type' => 'pos', 'status' => 'completed']);

        return $c;
    }

    private function posSearch(string $q, array $extra = [])
    {
        return $this->getJson('/api/v1/admin/pos/customers/search?' . http_build_query(['q' => $q] + $extra));
    }

    public function test_short_and_wildcard_only_queries_are_refused(): void
    {
        $this->as('pos_clerk', $this->a);

        $this->posSearch('mo')->assertStatus(422);
        $this->posSearch('%%%')->assertStatus(422);
        $this->posSearch('_ _ _')->assertStatus(422);
        $this->posSearch('a%b')->assertStatus(422);   // two real characters around a wildcard are still two
    }

    public function test_a_wildcard_is_searched_for_literally(): void
    {
        $this->as('pos_clerk', $this->a);
        $this->customerAt($this->a, 'Moses', '0711000001');

        $this->assertSame([], $this->posSearch('mo%s')->assertOk()->json('data'), '% is not a pattern');
    }

    public function test_the_autocomplete_is_id_name_and_a_masked_phone_only(): void
    {
        $this->as('outlet_manager', $this->a);
        $c = $this->customerAt($this->a, 'Moses', '0711000001');

        $row = $this->posSearch('Mos')->assertOk()->json('data.0');
        $this->assertSame(['id', 'name', 'phone'], array_keys($row));
        $this->assertSame($c->id, $row['id']);
        $this->assertSame('07••••0001', $row['phone'], 'masked even for a manager who may see it in full elsewhere');
    }

    public function test_at_most_twenty_results(): void
    {
        $this->as('outlet_manager', $this->a);
        for ($i = 0; $i < 25; $i++) {
            $this->customerAt($this->a, "Moses{$i}", '07110001' . str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $this->posSearch('Moses', ['per_page' => 50])->assertStatus(422);
        $this->assertCount(20, $this->posSearch('Moses', ['per_page' => 20])->assertOk()->json('data'));

        $this->assertLessThanOrEqual(20, count($this->getJson('/api/v1/admin/customers?search=Moses&per_page=100')->assertOk()->json('data')));
    }

    public function test_the_search_is_scoped_before_matching_except_the_whole_number(): void
    {
        $this->as('pos_clerk', $this->a);
        $here  = $this->customerAt($this->a, 'Herenow', '0711000001');
        $there = $this->customerAt($this->b, 'Thereonly', '0722000002');

        $this->assertSame([$here->id], collect($this->posSearch('Herenow')->json('data'))->pluck('id')->all());
        $this->assertSame([], $this->posSearch('Thereonly')->assertOk()->json('data'), 'another outlet\'s customer is not found by name');
        $this->assertSame([], $this->posSearch('0722')->assertOk()->json('data'), 'nor by a fragment of their number');

        // The whole number of the person at the counter finds them — masked.
        $hit = $this->posSearch('0722 000 002')->assertOk()->json('data');
        $this->assertSame([$there->id], collect($hit)->pluck('id')->all());
        $this->assertSame('07••••0002', $hit[0]['phone']);
    }

    public function test_the_customers_list_refuses_a_short_search(): void
    {
        $this->as('outlet_manager', $this->a);

        $this->getJson('/api/v1/admin/customers?search=ab')->assertStatus(422);
        $this->getJson('/api/v1/admin/customers')->assertOk();
    }

    public function test_a_scripted_search_is_stopped_at_thirty_a_minute(): void
    {
        $this->as('pos_clerk', $this->a);

        for ($i = 0; $i < 30; $i++) {
            $this->posSearch('Moses')->assertOk();
        }
        $this->posSearch('Moses')->assertStatus(429);
        // One bucket for every customer-search door.
        $this->getJson('/api/v1/admin/search?q=Moses')->assertStatus(429);
    }
}
