<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Chat is two channels, and Reports now says which.
 *
 * `sales_bucket` has four values and `chat` covers both apps the business
 * sells in. Measured on production 2026-09-30: Messenger 49 orders for
 * 638,890 against WhatsApp 41 for 261,990 — the smaller count carried the
 * larger money, and one "Chat Orders" line hid both facts. Which app an order
 * came from is already recorded in `source_channel`, so this is a reporting
 * axis (Order::REPORTING_CHANNELS), not a new bucket: no writer changed and
 * nothing was backfilled.
 *
 * The invariant that must survive the split: the channel lines still add up to
 * total revenue. A breakdown that stops footing is worse than a coarse one.
 */
class ReportChannelSplitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $staff = User::factory()->create();
        foreach (['reports.view', 'reports.financial'] as $p) {
            $staff->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($staff);
    }

    private function order(string $bucket, ?string $source, float $total): Order
    {
        return Order::create([
            'order_number'   => strtoupper(substr($bucket, 0, 2)) . '-' . bin2hex(random_bytes(4)),
            'status'         => 'completed',
            'payment_status' => 'paid',
            'currency_code'  => 'KES',
            'subtotal'       => $total,
            'total_amount'   => $total,
            'sales_bucket'   => $bucket,
            'source_channel' => $source,
        ]);
    }

    private function summary(): array
    {
        return $this->getJson('/api/v1/admin/reports/sales/summary?start_date=2020-01-01&end_date=2030-12-31')
            ->assertOk()->json('summary');
    }

    /** The shape of the real order book, in miniature. */
    private function seedTheBusiness(): void
    {
        $this->order('till', 'walk_in', 100);
        $this->order('chat', 'whatsapp', 40);
        $this->order('chat', 'messenger', 60);
        $this->order('web', 'website', 20);
        $this->order('quoted', null, 700);
    }

    public function test_whatsapp_and_messenger_are_reported_separately(): void
    {
        $this->seedTheBusiness();

        $s = $this->summary();

        $this->assertSame(40.0, (float) $s['whatsapp_revenue']);
        $this->assertSame(60.0, (float) $s['messenger_revenue'], 'the bigger of the two');
        $this->assertSame(1, (int) $s['whatsapp_count']);
        $this->assertSame(1, (int) $s['messenger_count']);
    }

    public function test_the_two_apps_add_up_to_the_chat_figure_that_was_there_before(): void
    {
        $this->seedTheBusiness();

        $s = $this->summary();

        $this->assertSame(100.0, (float) $s['chat_revenue'], 'unchanged meaning');
        $this->assertSame(
            round((float) $s['chat_revenue'], 2),
            round((float) $s['whatsapp_revenue'] + (float) $s['messenger_revenue'] + (float) $s['other_chat_revenue'], 2),
            'the split must account for every chat shilling',
        );
    }

    public function test_the_channel_lines_still_sum_to_total_revenue(): void
    {
        $this->seedTheBusiness();

        $s = $this->summary();

        $this->assertSame(920.0, (float) $s['total_revenue']);
        $this->assertSame(920.0, round(
            (float) $s['till_revenue'] + (float) $s['web_revenue']
            + (float) $s['whatsapp_revenue'] + (float) $s['messenger_revenue']
            + (float) $s['other_chat_revenue'] + (float) $s['quoted_revenue'], 2,
        ), 'every order lands in exactly one channel');
    }

    public function test_a_chat_order_naming_no_app_is_still_counted(): void
    {
        // A writer that forgets source_channel must not make money disappear
        // from the breakdown — it lands in "other chat" and stays visible.
        $this->order('chat', null, 500);

        $s = $this->summary();

        $this->assertSame(0.0, (float) $s['whatsapp_revenue']);
        $this->assertSame(500.0, (float) $s['other_chat_revenue']);
        $this->assertSame(500.0, (float) $s['chat_revenue']);
        $this->assertSame(500.0, (float) $s['total_revenue'], 'nothing lost');
    }

    public function test_the_pipeline_breakdown_names_the_two_apps(): void
    {
        $this->seedTheBusiness();

        $channels = collect(
            $this->getJson('/api/v1/admin/reports/order-pipeline')->assertOk()->json('by_channel')
            ?? $this->getJson('/api/v1/admin/reports/order-pipeline')->assertOk()->json('channels')
        )->keyBy('channel');

        $this->assertTrue($channels->has('whatsapp'), 'WhatsApp is its own line');
        $this->assertTrue($channels->has('messenger'), 'Messenger is its own line');
        $this->assertSame('WhatsApp Orders', $channels['whatsapp']['label']);
        $this->assertSame('Messenger Orders', $channels['messenger']['label']);
        $this->assertFalse($channels->has('chat'),
            'with every chat order labelled, the catch-all line stays off the page');
    }

    /**
     * The scope and the SQL expression are two spellings of one rule, and the
     * model's comment promises they agree. If they drift, a breakdown and its
     * own drill-down disagree — the exact failure this work exists to end.
     */
    public function test_the_scope_and_the_sql_expression_agree_channel_for_channel(): void
    {
        $this->seedTheBusiness();
        $this->order('chat', null, 500);

        $bucketExpr = "COALESCE(orders.sales_bucket, 'till')";
        $viaSql = collect(
            Order::query()
                ->selectRaw(Order::reportingChannelSql($bucketExpr) . ' AS channel, COUNT(*) AS n')
                ->groupBy(\Illuminate\Support\Facades\DB::raw(Order::reportingChannelSql($bucketExpr)))
                ->get()
        )->pluck('n', 'channel')->map(fn ($n) => (int) $n);

        foreach (Order::REPORTING_CHANNELS as $channel) {
            $viaScope = Order::query()->reportingChannel($channel)->count();
            $this->assertSame(
                $viaScope, (int) ($viaSql[$channel] ?? 0),
                "channel '{$channel}': the scope and the SQL must count the same orders",
            );
        }
    }
}
