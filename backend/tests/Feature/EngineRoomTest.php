<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Engine Room — GET /reports/engine-room feeds the Overview's always-on
 * revenue strip: the six engine summaries (collections, stockout, win-back,
 * attach, replenishment, seasonal) in ONE lightweight call.
 *
 * Pinned promises:
 *  - All six keys are always present, each carrying that engine's own
 *    summary shape (the SPA renders one card per key).
 *  - The numbers agree with the engines themselves — collections here IS
 *    collectionsFunnel()['summary'], not a reimplementation.
 *  - reports.view gates the endpoint like every other report route.
 */
class EngineRoomTest extends TestCase
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

    public function test_returns_all_six_engine_summaries(): void
    {
        $this->viewer();

        // Minimal seed touching the collections engine: one open quote and
        // one deposit order with money down and gone quiet.
        DB::table('quotations')->insert([
            'quote_number'    => 'QUO-000777',
            'status'          => 'sent',
            'source'          => 'admin',
            'currency_code'   => 'KES',
            'subtotal'        => 8000,
            'discount_amount' => 0,
            'tax_amount'      => 0,
            'shipping_amount' => 0,
            'total_amount'    => 8000,
            'created_at'      => now()->subDays(3),
            'updated_at'      => now()->subDays(3),
        ]);

        $deposit = Order::factory()->create([
            'order_type'     => 'pos',
            'status'         => 'confirmed',
            'currency_code'  => 'KES',
            'total_amount'   => 12000,
            'payment_status' => 'deposit',
        ]);
        Payment::create([
            'order_id'       => $deposit->id,
            'amount'         => 3000,
            'currency_code'  => 'KES',
            'payment_method' => 'cash',
            'status'         => 'paid',
            'paid_at'        => now()->subDays(9),
        ]);

        $res = $this->getJson('/api/v1/admin/reports/engine-room')->assertOk();

        $res->assertJsonStructure([
            'collections' => [
                'money_on_table',
                'open_quotes'      => ['count', 'value', 'avg_age_days'],
                'stalled_deposits' => ['count', 'deposit_held', 'balance_due'],
                'unpaid_balances'  => ['count', 'value'],
            ],
            'stockout' => [
                'products_currently_out',
                'est_daily_loss_now',
                'est_lost_revenue_window',
                'low_confidence_count',
            ],
            'winback' => [
                'customers_at_risk',
                'annual_value_at_risk',
                'contacted_30d',
                'won_back_90d',
                'recovered_revenue_90d',
                'win_back_rate_pct',
            ],
            'attach' => [
                'total_baskets',
                'multi_item_pct',
                'avg_basket_items',
                'missed_revenue_estimate_total',
            ],
            'replenishment' => [
                'due_customers',
                'due_pairs',
                'provisional_pairs',
                'maturing_pairs',
                'expected_revenue',
                'pings_30d',
            ],
            'seasonal' => [
                'total_gap_value',
                'urgent_orders',
                'history_depth_days',
            ],
        ]);

        // The strip's headline agrees with the collections engine itself:
        // 8,000 open quote + 9,000 outstanding balance.
        $this->assertEqualsWithDelta(17000.0, $res->json('collections.money_on_table'), 0.01);
        $this->assertSame(1, $res->json('collections.open_quotes.count'));
        $this->assertSame(1, $res->json('collections.stalled_deposits.count'));
    }

    public function test_empty_database_still_answers_with_all_keys(): void
    {
        $this->viewer();

        $res = $this->getJson('/api/v1/admin/reports/engine-room')->assertOk();

        foreach (['collections', 'stockout', 'winback', 'attach', 'replenishment', 'seasonal'] as $key) {
            $this->assertArrayHasKey($key, $res->json(), "engine-room must always carry '{$key}'");
        }
        $this->assertEqualsWithDelta(0.0, $res->json('collections.money_on_table'), 0.01);
        $this->assertSame(0, $res->json('replenishment.due_pairs'));
        $this->assertSame(0, $res->json('attach.total_baskets'));
    }

    public function test_requires_reports_view(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('tailor', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/reports/engine-room')->assertStatus(403);
    }
}
