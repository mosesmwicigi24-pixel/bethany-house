<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * POS customer search must match the customer's OWN name — most customers store
 * their name on the customers row with no linked user account, so a user-only
 * name search finds nobody. Also matches phone/email, and ranks prefix hits first.
 */
class PosCustomerSearchTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('pos.access', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    private function search(string $q): array
    {
        return $this->getJson('/api/v1/admin/pos/customers/search?q=' . urlencode($q))
            ->assertOk()->json('data');
    }

    public function test_a_customer_named_on_its_own_row_is_found_by_name(): void
    {
        $this->actor();
        Customer::create(['first_name' => 'Moses', 'last_name' => 'Mwicigi', 'phone' => '+254722000001']);

        $names = collect($this->search('Moses'))->pluck('name');
        $this->assertTrue($names->contains('Moses Mwicigi'), 'Expected to find "Moses Mwicigi" by first name');

        // Last name and full name work too.
        $this->assertNotEmpty($this->search('Mwicigi'));
        $this->assertNotEmpty($this->search('Moses Mwicigi'));
    }

    public function test_search_also_matches_phone(): void
    {
        $this->actor();
        Customer::create(['first_name' => 'Alice', 'last_name' => 'Phillip', 'phone' => '+254733123456']);

        $hit = collect($this->search('733123'))->first();
        $this->assertNotNull($hit);
        $this->assertSame('Alice Phillip', $hit['name']);
    }

    public function test_prefix_matches_rank_first(): void
    {
        $this->actor();
        Customer::create(['first_name' => 'Zonar', 'last_name' => 'Moses', 'phone' => '+254700000009']); // "Moses" as last name
        Customer::create(['first_name' => 'Moses', 'last_name' => 'Kamau', 'phone' => '+254700000010']); // starts with "Moses"

        $names = collect($this->search('Moses'))->pluck('name');
        // Both match, but the one whose name STARTS with "Moses" comes first.
        $this->assertSame('Moses Kamau', $names->first());
    }
}
