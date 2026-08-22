<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\CurrencyPricing;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The pending-queue worklist contract: it must show exactly the orders the
 * recognition rule keeps OUT of the reports (unconfirmed AND unpaid), price
 * them by the same reporting rates the reports use, rank value x age, and
 * flag one customer holding two pending orders — the double-quoted sale.
 */
class PendingQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('currencies')->updateOrInsert(
            ['code' => 'KES'],
            ['name' => 'Kenyan Shilling', 'symbol' => 'KES', 'exchange_rate' => 1,
             'reporting_rate_to_kes' => 1, 'is_base' => true, 'is_active' => true],
        );
        DB::table('currencies')->updateOrInsert(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'exchange_rate' => 0.01,
             'reporting_rate_to_kes' => 128, 'is_base' => false, 'is_active' => true],
        );
        // A currency with NO reporting rate: must be listed, never summed.
        DB::table('currencies')->updateOrInsert(
            ['code' => 'XTS'],
            ['name' => 'Test Currency', 'symbol' => 'XTS', 'exchange_rate' => 1,
             'reporting_rate_to_kes' => null, 'is_base' => false, 'is_active' => true],
        );
        ReportingCurrency::forget();
        CurrencyPricing::forget();
    }

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('orders.view', 'sanctum'));

        return $user;
    }

    private function queue(): array
    {
        return $this->actingAs($this->viewer(), 'sanctum')
            ->getJson('/api/v1/admin/orders/pending-queue')
            ->assertOk()->json();
    }

    public function test_queue_holds_exactly_the_unrecognised_orders(): void
    {
        // IN: pending + unpaid.
        Order::factory()->create(['status' => 'pending', 'payment_status' => 'pending',
            'currency_code' => 'KES', 'total_amount' => 10000]);
        // OUT: money arrived — recognised even though still 'pending'.
        Order::factory()->create(['status' => 'pending', 'payment_status' => 'deposit',
            'currency_code' => 'KES', 'total_amount' => 20000]);
        // OUT: a human confirmed it.
        Order::factory()->create(['status' => 'confirmed', 'payment_status' => 'pending',
            'currency_code' => 'KES', 'total_amount' => 30000]);
        // OUT: dead.
        Order::factory()->create(['status' => 'cancelled', 'payment_status' => 'pending',
            'currency_code' => 'KES', 'total_amount' => 40000]);

        $q = $this->queue();

        $this->assertSame(1, $q['summary']['total_count']);
        $this->assertSame(10000.0, (float) $q['summary']['total_kes']);
        $this->assertCount(1, $q['rows']);
        $this->assertSame(10000.0, (float) $q['rows'][0]['kes_value']);
    }

    public function test_values_use_reporting_rates_and_age_outranks_size(): void
    {
        // KES 50,000 placed today vs USD 100 (KES 12,800) forty days stale.
        // Priority = kes x sqrt(days+1): 50,000 x 1 < 12,800 x sqrt(41) ≈ 81,960
        // — the smaller order going cold must sit on top.
        Order::factory()->create(['status' => 'pending', 'payment_status' => 'pending',
            'currency_code' => 'KES', 'total_amount' => 50000, 'created_at' => now()]);
        Order::factory()->create(['status' => 'pending', 'payment_status' => 'pending',
            'currency_code' => 'USD', 'total_amount' => 100, 'created_at' => now()->subDays(40)]);

        $q = $this->queue();

        $this->assertSame(62800.0, (float) $q['summary']['total_kes'], '50,000 + (100 x 128)');
        $this->assertSame('USD', strtoupper($q['rows'][0]['currency_code']));
        $this->assertSame(12800.0, (float) $q['rows'][0]['kes_value']);
        $this->assertGreaterThanOrEqual(40, $q['rows'][0]['days_pending']);
    }

    public function test_rateless_currency_is_listed_never_summed(): void
    {
        Order::factory()->create(['status' => 'pending', 'payment_status' => 'pending',
            'currency_code' => 'KES', 'total_amount' => 1000]);
        Order::factory()->create(['status' => 'pending', 'payment_status' => 'pending',
            'currency_code' => 'XTS', 'total_amount' => 999999]);

        $q = $this->queue();

        $this->assertSame(1000.0, (float) $q['summary']['total_kes'],
            'a rate-less currency must never leak into the KES total');
        $this->assertCount(1, $q['summary']['excluded_currencies']);
        $this->assertSame('XTS', $q['summary']['excluded_currencies'][0]['code']);
        // It still appears as a row — listed — with no KES value, ranked last.
        $this->assertCount(2, $q['rows']);
        $this->assertNull($q['rows'][1]['kes_value']);
        $this->assertSame('XTS', strtoupper($q['rows'][1]['currency_code']));
    }

    public function test_two_pending_orders_on_one_phone_are_flagged_as_duplicates(): void
    {
        // Same human, formatted two ways — normalize_phone must still match them.
        Order::factory()->create(['status' => 'pending', 'payment_status' => 'pending',
            'currency_code' => 'KES', 'total_amount' => 1000, 'customer_phone' => '0743942757']);
        Order::factory()->create(['status' => 'pending', 'payment_status' => 'pending',
            'currency_code' => 'KES', 'total_amount' => 2000, 'customer_phone' => '+254 743 942 757']);
        Order::factory()->create(['status' => 'pending', 'payment_status' => 'pending',
            'currency_code' => 'KES', 'total_amount' => 3000, 'customer_phone' => '0700000001']);
        // Two phoneless orders are NOT each other's duplicates.
        Order::factory()->create(['status' => 'pending', 'payment_status' => 'pending',
            'currency_code' => 'KES', 'total_amount' => 4000, 'customer_phone' => null]);
        Order::factory()->create(['status' => 'pending', 'payment_status' => 'pending',
            'currency_code' => 'KES', 'total_amount' => 5000, 'customer_phone' => null]);

        // A junk phone normalizes to NULL; two junk phones must NOT flag
        // each other (PARTITION BY lumps NULLs into one group).
        Order::factory()->create(['status' => 'pending', 'payment_status' => 'pending',
            'currency_code' => 'KES', 'total_amount' => 6000, 'customer_phone' => 'n/a']);
        Order::factory()->create(['status' => 'pending', 'payment_status' => 'pending',
            'currency_code' => 'KES', 'total_amount' => 7000, 'customer_phone' => 'ask Mercy']);

        $flags = collect($this->queue()['rows'])
            ->mapWithKeys(fn ($r) => [(int) round((float) $r['total_amount']) => $r['possible_duplicate']]);

        $this->assertTrue($flags[1000], 'first order on the shared number');
        $this->assertTrue($flags[2000], 'same human, formatted differently');
        $this->assertFalse($flags[3000], 'unique number');
        $this->assertFalse($flags[4000], 'no phone at all');
        $this->assertFalse($flags[5000], 'no phone at all');
        $this->assertFalse($flags[6000], 'junk phone');
        $this->assertFalse($flags[7000], 'junk phone');
    }

    public function test_queue_requires_orders_view(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('walkin_customer', 'sanctum'));

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/orders/pending-queue')
            ->assertForbidden();
    }
}
