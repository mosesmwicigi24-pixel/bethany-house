<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Reporting\MetricEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Collections funnel — quote/deposit/balance: money promised but not
 * collected, staged, with per-row follow-up lists.
 *
 * The load-bearing promises pinned here:
 *  - money_on_table = open-quote value + total outstanding balance, and the
 *    balance side reuses openBalances()/outstandingAging(), so the funnel can
 *    never disagree with the executive Overview's numbers.
 *  - A "stalled deposit" means money already landed and then went quiet
 *    (no settled payment for 7+ days) — recent payment movement excludes.
 *  - Conversion is quotes raised in 90 days, share converted to orders.
 *  - Stalling quotes surface on the attention feed and deep-link to the tab.
 */
class CollectionsFunnelTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    /** Insert a quotation row directly (no factory exists for quotations). */
    private function quote(array $attrs = []): int
    {
        return (int) DB::table('quotations')->insertGetId(array_merge([
            'status'          => 'sent',
            'source'          => 'admin',
            'currency_code'   => 'KES',
            'subtotal'        => 0,
            'discount_amount' => 0,
            'tax_amount'      => 0,
            'shipping_amount' => 0,
            'total_amount'    => 0,
            'created_at'      => now(),
            'updated_at'      => now(),
        ], $attrs));
    }

    private function order(array $attrs = []): Order
    {
        return Order::factory()->create(array_merge([
            'order_type'     => 'pos',
            'status'         => 'confirmed',
            'currency_code'  => 'KES',
        ], $attrs));
    }

    private function paidPayment(Order $order, float $amount, int $paidDaysAgo): Payment
    {
        return Payment::create([
            'order_id'       => $order->id,
            'amount'         => $amount,
            'currency_code'  => 'KES',
            'payment_method' => 'cash',
            'status'         => 'paid',
            'paid_at'        => now()->subDays($paidDaysAgo),
        ]);
    }

    public function test_funnel_summary_and_action_lists(): void
    {
        // Stage 1 — an unconverted quote, issued 10 days ago, KES 20,000.
        $this->quote([
            'quote_number'        => 'QUO-000001',
            'status'              => 'sent',
            'total_amount'        => 20000,
            'customer_first_name' => 'Grace',
            'customer_phone'      => '0722000111',
            'valid_until'         => now()->addDays(20)->format('Y-m-d'),
            'issued_at'           => now()->subDays(10),
            'created_at'          => now()->subDays(10),
        ]);

        // Stage 2 — a deposit order: KES 4,000 down 10 days ago, then silence.
        $stalled = $this->order([
            'total_amount' => 10000, 'payment_status' => 'deposit',
            'customer_first_name' => 'Peter', 'customer_phone' => '0733000222',
            'created_at' => now()->subDays(10),
        ]);
        $this->paidPayment($stalled, 4000, 10);

        // Stage 3 — a partial order, 35 days old, but its last payment was
        // 2 days ago: unpaid (owing 7,000) yet NOT stalled — money is moving.
        $partial = $this->order([
            'total_amount' => 9000, 'payment_status' => 'partial',
            'customer_first_name' => 'Lydia', 'customer_phone' => '0744000333',
            'created_at' => now()->subDays(35),
        ]);
        $this->paidPayment($partial, 2000, 2);

        $funnel = MetricEngine::for(User::factory()->create())->collectionsFunnel();
        $s = $funnel['summary'];

        // Open quotes.
        $this->assertSame(1, $s['open_quotes']['count']);
        $this->assertEqualsWithDelta(20000.0, $s['open_quotes']['value'], 0.01);
        $this->assertEqualsWithDelta(10, $s['open_quotes']['avg_age_days'], 0.5);

        // Stalled deposits: only the quiet one; the moving partial stays out.
        $this->assertSame(1, $s['stalled_deposits']['count']);
        $this->assertEqualsWithDelta(4000.0, $s['stalled_deposits']['deposit_held'], 0.01);
        $this->assertEqualsWithDelta(6000.0, $s['stalled_deposits']['balance_due'], 0.01);

        // Unpaid balances: 6,000 + 7,000 across both open orders.
        $this->assertSame(2, $s['unpaid_balances']['count']);
        $this->assertEqualsWithDelta(13000.0, $s['unpaid_balances']['value'], 0.01);

        // The headline: quotes + all outstanding balances.
        $this->assertEqualsWithDelta(33000.0, $s['money_on_table'], 0.01);

        // Aging agrees with outstandingAging(): 6,000 fresh, 7,000 in 31–60.
        $buckets = collect($s['aging']['buckets'])->keyBy('key');
        $this->assertEqualsWithDelta(6000.0, $buckets['0_30']['amount'], 0.01);
        $this->assertSame(1, $buckets['0_30']['orders']);
        $this->assertEqualsWithDelta(7000.0, $buckets['31_60']['amount'], 0.01);
        $this->assertSame(1, $buckets['31_60']['orders']);

        // Action list 1: the quote row, follow-up-ready.
        $q = $funnel['open_quotes'][0];
        $this->assertSame('QUO-000001', $q['number']);
        $this->assertSame('Grace', $q['customer']);
        $this->assertSame('0722000111', $q['phone']);
        $this->assertEqualsWithDelta(20000.0, $q['value'], 0.01);
        $this->assertSame(10, $q['age_days']);
        $this->assertSame('sent', $q['status']);
        $this->assertNotNull($q['expires_at']);

        // Action list 2: the stalled deposit row.
        $this->assertCount(1, $funnel['stalled_deposits']);
        $d = $funnel['stalled_deposits'][0];
        $this->assertSame($stalled->id, $d['id']);
        $this->assertSame($stalled->order_number, $d['number']);
        $this->assertSame('Peter', $d['customer']);
        $this->assertEqualsWithDelta(10000.0, $d['total'], 0.01);
        $this->assertEqualsWithDelta(4000.0, $d['deposit_paid'], 0.01);
        $this->assertEqualsWithDelta(6000.0, $d['balance_due'], 0.01);
        $this->assertSame(10, $d['days_since_last_payment']);

        // Action list 3: every open balance, biggest owed first, bucketed.
        $this->assertCount(2, $funnel['unpaid_balances']);
        $u = $funnel['unpaid_balances'][0];
        $this->assertSame($partial->id, $u['id']);
        $this->assertEqualsWithDelta(7000.0, $u['balance'], 0.01);
        $this->assertSame(35, $u['days_outstanding']);
        $this->assertSame('31_60', $u['bucket']);
        $this->assertSame('0_30', $funnel['unpaid_balances'][1]['bucket']);
    }

    public function test_deposit_with_recent_payment_is_not_stalled(): void
    {
        $moving = $this->order(['total_amount' => 10000, 'payment_status' => 'deposit']);
        $this->paidPayment($moving, 4000, 2); // money moved 2 days ago

        $funnel = MetricEngine::for(User::factory()->create())->collectionsFunnel();

        $this->assertSame(0, $funnel['summary']['stalled_deposits']['count']);
        $this->assertSame([], $funnel['stalled_deposits']);
        // Still an unpaid balance, though.
        $this->assertSame(1, $funnel['summary']['unpaid_balances']['count']);
    }

    public function test_conversion_rate_and_stage_speeds(): void
    {
        // One converted quote (order placed 3 days after the quote) ...
        $converted = $this->order([
            'total_amount' => 15000, 'payment_status' => 'paid',
            'created_at' => now()->subDays(7),
        ]);
        $this->quote([
            'status'             => 'converted',
            'total_amount'       => 15000,
            'converted_order_id' => $converted->id,
            'created_at'         => now()->subDays(10),
        ]);
        // ... and one still open: 1 of 2 → 50%.
        $this->quote(['status' => 'sent', 'total_amount' => 5000, 'created_at' => now()->subDays(5)]);

        // Deposit-to-paid: first payment 15 days ago, settled 5 days ago.
        $settled = $this->order(['total_amount' => 10000, 'payment_status' => 'paid']);
        $this->paidPayment($settled, 5000, 15);
        $this->paidPayment($settled, 5000, 5);

        $conv = MetricEngine::for(User::factory()->create())->collectionsFunnel()['summary']['conversion'];

        $this->assertEqualsWithDelta(50.0, $conv['quotes_converted_rate_90d'], 0.01);
        $this->assertEqualsWithDelta(3.0, $conv['avg_days_quote_to_order'], 0.01);
        $this->assertEqualsWithDelta(10.0, $conv['avg_days_deposit_to_paid'], 0.01);
    }

    public function test_attention_feed_advertises_stalling_quotes(): void
    {
        $this->viewer();

        // A big quote sitting 10 days: 60,000 >= 50,000 → severity high.
        $this->quote([
            'quote_number'        => 'QUO-000009',
            'total_amount'        => 60000,
            'customer_first_name' => 'Naomi',
            'issued_at'           => now()->subDays(10),
            'created_at'          => now()->subDays(10),
        ]);
        // Small (< 5,000) and fresh (< 7 days) quotes never trigger the item.
        $this->quote(['total_amount' => 2000, 'created_at' => now()->subDays(30)]);
        $this->quote(['total_amount' => 90000, 'created_at' => now()->subDays(2)]);

        $res = $this->getJson('/api/v1/admin/reports/executive')->assertOk();
        $item = collect($res->json('attention'))->firstWhere('key', 'quotes_stalling');

        $this->assertNotNull($item, 'stalling quotes must surface on the attention feed');
        $this->assertSame('high', $item['severity']);
        $this->assertSame(1, $item['count']);
        $this->assertSame('/reports/sales?tab=collections', $item['link']);

        $entity = $item['entities'][0];
        $this->assertSame('QUO-000009', $entity['number']);
        $this->assertSame('Naomi', $entity['customer']);
        $this->assertEqualsWithDelta(60000.0, $entity['value'], 0.01);
        $this->assertSame(10, $entity['age_days']);

        $nav = collect($item['actions'])->firstWhere('type', 'navigate');
        $this->assertSame('/reports/sales?tab=collections', $nav['to']);
    }

    public function test_attention_severity_is_medium_below_fifty_thousand(): void
    {
        $this->viewer();
        $this->quote(['total_amount' => 10000, 'created_at' => now()->subDays(10)]);

        $res = $this->getJson('/api/v1/admin/reports/executive')->assertOk();
        $item = collect($res->json('attention'))->firstWhere('key', 'quotes_stalling');

        $this->assertNotNull($item);
        $this->assertSame('medium', $item['severity']);
    }

    public function test_endpoint_returns_funnel_shape_for_report_viewers(): void
    {
        $this->viewer();

        $this->quote(['total_amount' => 8000, 'created_at' => now()->subDays(3)]);
        $deposit = $this->order(['total_amount' => 12000, 'payment_status' => 'deposit']);
        $this->paidPayment($deposit, 3000, 9);

        $res = $this->getJson('/api/v1/admin/reports/collections')->assertOk();

        $res->assertJsonStructure([
            'summary' => [
                'money_on_table',
                'open_quotes'      => ['count', 'value', 'avg_age_days'],
                'stalled_deposits' => ['count', 'deposit_held', 'balance_due'],
                'unpaid_balances'  => ['count', 'value'],
                'aging'            => ['buckets', 'deposits_held'],
                'conversion'       => ['quotes_converted_rate_90d', 'avg_days_quote_to_order', 'avg_days_deposit_to_paid'],
            ],
            'open_quotes'      => [['id', 'number', 'customer', 'phone', 'value', 'age_days', 'status', 'expires_at']],
            'stalled_deposits' => [['id', 'number', 'customer', 'phone', 'total', 'deposit_paid', 'balance_due', 'days_since_last_payment']],
            'unpaid_balances'  => [['id', 'number', 'customer', 'phone', 'total', 'paid', 'balance', 'days_outstanding', 'bucket']],
        ]);
        $this->assertEqualsWithDelta(17000.0, $res->json('summary.money_on_table'), 0.01);
    }

    public function test_endpoint_requires_reports_view(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('tailor', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/reports/collections')->assertStatus(403);
    }
}
