<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ReplenishmentPing;
use App\Models\User;
use App\Models\WinBackOutreach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Outreach log — GET /reports/outreach-log merges the two audit tables
 * (replenishment_pings + win_back_outreach) into one newest-first feed with
 * per-row outcome attribution, the 4-KPI summary, and CSV export.
 *
 * Pinned promises:
 *  - Rows from BOTH tables appear, strictly newest-first, capped at 100.
 *  - Winback outcomes reuse the win-back recovery rule (first order within
 *    30 days AFTER the contact => "won back +KES X"; an order BEFORE the
 *    contact attributes nothing).
 *  - Ping outcomes read "reordered" only when the same phone bought the
 *    same product again within one cycle after the ping.
 *  - reports.view gates the endpoint like every other report route.
 */
class OutreachLogTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        $user = User::factory()->create(['first_name' => 'Amani', 'last_name' => 'Clerk']);
        $user->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    /** One KES sale $daysAgo days ago, optionally with one product line. */
    private function order(string $phone, string $name, float $amount, int $daysAgo, ?Product $product = null): Order
    {
        $when = now()->subDays($daysAgo);
        $order = Order::factory()->create([
            'order_type' => 'pos', 'status' => 'confirmed', 'payment_status' => 'paid',
            'total_amount' => $amount, 'currency_code' => 'KES',
            'customer_phone' => $phone, 'customer_first_name' => $name,
            'created_at' => $when,
        ]);
        if ($product !== null) {
            DB::table('order_items')->insert([
                'order_id' => $order->id, 'product_id' => $product->id,
                'sku' => "SKU-{$product->id}", 'product_name' => 'line snapshot',
                'quantity' => 1, 'unit_price' => $amount, 'total_price' => $amount,
                'created_at' => $when, 'updated_at' => $when,
            ]);
        }

        return $order;
    }

    /** A radar-ping audit row backdated $daysAgo days. */
    private function ping(string $canonicalPhone, Product $product, int $daysAgo, string $status = 'sent'): ReplenishmentPing
    {
        $row = ReplenishmentPing::create([
            'phone' => $canonicalPhone, 'product_id' => $product->id,
            'product_name' => "Product {$product->id}", 'cycle_days' => 30,
            'days_over' => 5, 'avg_value' => 4000, 'status' => $status,
        ]);
        DB::table('replenishment_pings')->where('id', $row->id)->update([
            'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
        ]);

        return $row->refresh();
    }

    /** A manual win-back outreach row backdated $daysAgo days. */
    private function outreach(string $canonicalPhone, string $name, int $daysAgo, string $channel = 'call', ?int $byUserId = null): WinBackOutreach
    {
        $row = WinBackOutreach::create([
            'phone' => $canonicalPhone, 'name' => $name, 'channel' => $channel,
            'contacted_by' => $byUserId,
        ]);
        DB::table('win_back_outreach')->where('id', $row->id)->update([
            'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
        ]);

        return $row->refresh();
    }

    public function test_feed_merges_both_tables_newest_first(): void
    {
        $user = $this->viewer();
        $bread = Product::factory()->create();

        $this->ping('254722000111', $bread, 5);
        $this->outreach('254733000222', 'Beryl', 2, 'whatsapp', $user->id);
        $this->ping('254744000333', $bread, 1, 'failed');

        $res = $this->getJson('/api/v1/admin/reports/outreach-log')->assertOk();

        $rows = $res->json('rows');
        $this->assertCount(3, $rows);
        $this->assertSame(['radar_ping', 'winback', 'radar_ping'], array_column($rows, 'type'));

        // Strictly newest-first across BOTH sources.
        $ats = array_column($rows, 'at');
        $sorted = $ats;
        rsort($sorted);
        $this->assertSame($sorted, $ats);

        // Ping rows: channel is always whatsapp, by is the automation, the
        // product context travels with the row.
        $this->assertSame('whatsapp', $rows[0]['channel']);
        $this->assertSame('automation', $rows[0]['by']);
        $this->assertSame('failed', $rows[0]['status']);
        $this->assertSame("Product {$bread->id}", $rows[0]['product_name']);

        // Winback rows: status is 'logged', by is the clerk who logged it.
        $this->assertSame('winback', $rows[1]['type']);
        $this->assertSame('Beryl', $rows[1]['name']);
        $this->assertSame('logged', $rows[1]['status']);
        $this->assertSame('Amani Clerk', $rows[1]['by']);
        $this->assertNull($rows[1]['product_name']);
    }

    public function test_winback_outcome_attributes_orders_after_contact_only(): void
    {
        $this->viewer();

        // Grace: contacted 20 days ago, ordered 10 days ago => won back.
        $this->outreach('254722000111', 'Grace', 20, 'whatsapp');
        $this->order('0722000111', 'Grace', 15000, 10);

        // Beryl: ordered 10 days ago, contacted 5 days ago — the order came
        // BEFORE the outreach and must not be attributed to it.
        $this->order('0733000222', 'Beryl', 50000, 10);
        $this->outreach('254733000222', 'Beryl', 5);

        $res = $this->getJson('/api/v1/admin/reports/outreach-log')->assertOk();

        $rows = collect($res->json('rows'));
        $grace = $rows->firstWhere('name', 'Grace');
        $beryl = $rows->firstWhere('name', 'Beryl');

        $this->assertSame('won back +KES 15,000', $grace['outcome']);
        $this->assertSame('—', $beryl['outcome']);

        $this->assertSame(2, $res->json('summary.outreach_30d'));
        $this->assertSame(1, $res->json('summary.won_back_30d'));
    }

    public function test_ping_outcome_reads_reordered_within_one_cycle(): void
    {
        $this->viewer();
        $bread = Product::factory()->create();
        $wine  = Product::factory()->create();

        // Pinged 20 days ago (cycle 30), bought the SAME product 10 days ago
        // — the phone-bridge join attributes the reorder.
        $this->ping('254722000111', $bread, 20);
        $this->order('0722000111', 'Grace', 4000, 10, $bread);

        // Pinged 20 days ago, but the later purchase is a DIFFERENT product
        // — no attribution.
        $this->ping('254733000222', $wine, 20);
        $this->order('0733000222', 'Beryl', 6000, 10, $bread);

        // Pinged 50 days ago (cycle 30), reorder 10 days ago is 40 days
        // after the ping — outside the cycle window, no attribution.
        $this->ping('254744000333', $bread, 50);
        $this->order('0744000333', 'Wanja', 4000, 10, $bread);

        $res = $this->getJson('/api/v1/admin/reports/outreach-log')->assertOk();

        $byPhone = collect($res->json('rows'))->keyBy('phone');
        $this->assertSame('reordered', $byPhone['254722000111']['outcome']);
        $this->assertSame('—', $byPhone['254733000222']['outcome']);
        $this->assertSame('—', $byPhone['254744000333']['outcome']);
    }

    public function test_summary_counts_pings_failures_and_outreach_in_30d(): void
    {
        $this->viewer();
        $bread = Product::factory()->create();

        $this->ping('254722000111', $bread, 5);                    // sent, in window
        $this->ping('254722000111', $bread, 45);                   // sent, OUT of window
        $this->ping('254733000222', $bread, 3, 'failed');          // failed, in window
        $this->ping('254744000333', $bread, 2, 'skipped_cooldown'); // skip rows count nowhere
        $this->outreach('254755000444', 'Naomi', 4);
        $this->outreach('254766000555', 'Ruth', 40);               // OUT of window

        $res = $this->getJson('/api/v1/admin/reports/outreach-log')->assertOk();

        $this->assertSame(1, $res->json('summary.pings_30d'));
        $this->assertSame(1, $res->json('summary.outreach_30d'));
        $this->assertSame(1, $res->json('summary.failures_30d'));
        $this->assertSame(0, $res->json('summary.won_back_30d'));

        // The feed itself shows every decision, skips included.
        $this->assertCount(6, $res->json('rows'));
    }

    public function test_csv_export_streams_the_same_feed(): void
    {
        $this->viewer();
        $bread = Product::factory()->create();
        $this->ping('254722000111', $bread, 5);
        $this->outreach('254733000222', 'Beryl', 2, 'whatsapp');

        $res = $this->get('/api/v1/admin/reports/outreach-log?export=csv');

        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('outreach_log', $res->headers->get('Content-Disposition'));

        $csv = $res->getContent();
        $this->assertStringContainsString('When,Type,Name,Phone,Channel,Product,Status,By,Outcome', $csv);
        $this->assertStringContainsString('radar_ping', $csv);
        $this->assertStringContainsString('winback', $csv);
        $this->assertStringContainsString('Beryl', $csv);
    }

    public function test_requires_reports_view(): void
    {
        Sanctum::actingAs(User::factory()->create()); // no reports.view

        $this->getJson('/api/v1/admin/reports/outreach-log')->assertForbidden();
    }
}
