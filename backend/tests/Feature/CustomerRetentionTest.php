<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Retention must be able to see a customer come back.
 *
 * It could not. Cohorts were built from `customers.user_id`, and of 718
 * customers on production exactly ONE has a login — so every cohort was empty
 * and the report returned cohort sizes with zero retention for all of them.
 * A reader saw that none of the 692 customers acquired in Q3 2026 ever
 * returned, while 37 people bought repeatedly in Q3 alone.
 *
 * That is worse than the other seven instances of this defect class. The blank
 * pages (Sales by Customer, Lifetime Value) were obviously empty. This one was
 * full of plausible zeros, which read as a finding about the business rather
 * than a bug in the report — and it had no test.
 *
 * The negative cases matter as much as the positive: a cohort that genuinely
 * never returns must still show zero, or the fix would just be optimism.
 */
class CustomerRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $staff = User::factory()->create();
        foreach ([...\Tests\ReportAccess::PAGES, 'customers.view', 'customers.insights'] as $p) {
            $staff->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($staff);
    }

    /** A walk-in customer acquired in a given month — 717 of 718 look like this. */
    private function walkIn(string $name, string $acquiredAt): Customer
    {
        // customer_number is given explicitly because the model derives it from
        // a COUNT of today's customers (`count() + 1`), and backdating one frees
        // its number for the next — the same read-then-write race that makes two
        // concurrent walk-in registrations collide in production. Flagged
        // separately; not this test's subject.
        $customer = Customer::create([
            'customer_number' => 'CUST-TEST-' . bin2hex(random_bytes(4)),
            'first_name' => $name, 'last_name' => 'R',
            'email' => strtolower($name) . '@example.test',
            'phone' => '07' . random_int(10000000, 99999999),
        ]);
        DB::table('customers')->where('id', $customer->id)->update(['created_at' => $acquiredAt]);

        return $customer->refresh();
    }

    private function order(Customer $c, string $at, string $status = 'completed', string $pay = 'paid'): void
    {
        $order = Order::create([
            'order_number'   => 'RT-' . bin2hex(random_bytes(4)),
            'customer_id'    => $c->id,
            'status'         => $status,
            'payment_status' => $pay,
            'currency_code'  => 'KES',
            'subtotal'       => 1_000,
            'total_amount'   => 1_000,
        ]);
        $order->forceFill(['created_at' => $at])->saveQuietly();
    }

    private function retention(): array
    {
        return $this->getJson('/api/v1/admin/reports/customers/retention'
            . '?start_date=2026-01-01&end_date=2026-12-31')->assertOk()->json();
    }

    private function cohort(string $month): ?array
    {
        $body = $this->retention();
        $rows = $body['retention'] ?? $body['cohorts'] ?? $body;

        return collect($rows)->firstWhere('cohort', $month);
    }

    public function test_a_walk_in_who_comes_back_is_counted_as_retained(): void
    {
        $returner = $this->walkIn('Returner', '2026-03-05 10:00:00');
        $this->order($returner, '2026-03-06 10:00:00');   // acquisition month
        $this->order($returner, '2026-04-08 10:00:00');   // came back

        $cohort = $this->cohort('2026-03');

        $this->assertNotNull($cohort, 'the March cohort exists');
        $this->assertSame(1, (int) $cohort['size']);
        $this->assertNotEmpty($cohort['months'], 'the cohort had no months at all before this fix');

        $byMonth = $cohort['months'];
        $this->assertSame(1, (int) ($byMonth['2026-03'] ?? 0));
        $this->assertSame(1, (int) ($byMonth['2026-04'] ?? 0), 'she came back in April');
    }

    public function test_a_cohort_that_never_returns_still_shows_zero(): void
    {
        // The negative case. Without it, a fix could simply count everybody
        // forever and look like an improvement.
        $once = $this->walkIn('Oncer', '2026-05-04 10:00:00');
        $this->order($once, '2026-05-05 10:00:00');

        $byMonth = $this->cohort('2026-05')['months'];

        $this->assertSame(1, (int) ($byMonth['2026-05'] ?? 0));
        $this->assertArrayNotHasKey('2026-06', $byMonth,
            'a month she bought nothing in is not a month she was retained');
    }

    public function test_a_deposit_order_counts_as_coming_back(): void
    {
        // Recognised, not paid-only: a customer part-way through paying for a
        // vestment has returned, whatever the payment status says.
        $customer = $this->walkIn('Depositor', '2026-06-02 10:00:00');
        $this->order($customer, '2026-06-03 10:00:00');
        $this->order($customer, '2026-07-04 10:00:00', 'confirmed', 'deposit');

        $byMonth = $this->cohort('2026-06')['months'];

        $this->assertSame(1, (int) ($byMonth['2026-07'] ?? 0),
            'a deposit order is a return visit');
    }

    public function test_a_cancelled_order_is_not_a_return_visit(): void
    {
        $customer = $this->walkIn('Canceller', '2026-08-02 10:00:00');
        $this->order($customer, '2026-08-03 10:00:00');
        $this->order($customer, '2026-09-04 10:00:00', 'cancelled', 'pending');

        $byMonth = $this->cohort('2026-08')['months'];

        $this->assertArrayNotHasKey('2026-09', $byMonth,
            'a cancelled order is not a purchase');
    }

    public function test_two_customers_in_a_cohort_are_counted_separately(): void
    {
        $a = $this->walkIn('Aisha', '2026-02-03 10:00:00');
        $b = $this->walkIn('Brian', '2026-02-04 10:00:00');
        $this->order($a, '2026-02-05 10:00:00');
        $this->order($b, '2026-02-06 10:00:00');
        $this->order($a, '2026-03-07 10:00:00');   // only Aisha returns

        $cohort  = $this->cohort('2026-02');
        $byMonth = $cohort['months'];

        $this->assertSame(2, (int) $cohort['size']);
        $this->assertSame(2, (int) ($byMonth['2026-02'] ?? 0));
        $this->assertSame(1, (int) ($byMonth['2026-03'] ?? 0), 'one of the two came back');
    }
}
