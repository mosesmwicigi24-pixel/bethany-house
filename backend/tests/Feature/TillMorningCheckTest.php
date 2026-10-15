<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\Tills\TillPresenter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The morning check (owner, 2026-10-15): "The point of sale should restart
 * every morning and should ask you for a report for the previous evening.
 * If she did not close it, she gets a pop-up that requires her to write the
 * report for yesterday so that today she can sell." — every sale, whatever
 * the tender (owner's answer).
 *
 * Days are Nairobi calendar days (app.timezone). Every role is the real one
 * from permission:sync, on a real Postgres.
 */
class TillMorningCheckTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        $this->outlet = Outlet::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(string $role, ?Outlet $outlet = null): User
    {
        $u = User::factory()->create(['status' => 'active']);
        $u->assignRole(Role::findByName($role, 'sanctum'));
        if ($outlet) {
            $u->outlets()->attach($outlet->id);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function as(User $u): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u);

        return $u;
    }

    /** A till of hers, opened at $openedAt (Nairobi wall clock). */
    private function till(User $clerk, Carbon $openedAt, string $status = 'open'): CashRegister
    {
        return CashRegister::create([
            'outlet_id' => $this->outlet->id, 'register_name' => 'Till', 'status' => $status,
            'currency_code' => 'KES', 'opening_balance' => 1000, 'expected_cash' => 1000,
            'opened_by' => $clerk->id, 'user_id' => $clerk->id, 'opened_at' => $openedAt,
            'lifecycle_version' => CashRegister::LIFECYCLE_VERSION,
        ]);
    }

    /** Her own pending POS order (a clerk sees only her own: own data scope). */
    private function pendingOrder(float $total = 2000): Order
    {
        return Order::factory()->create([
            'created_by' => auth()->id(),
            'order_type' => 'pos', 'outlet_id' => $this->outlet->id,
            'payment_status' => 'pending', 'status' => 'pending',
            'total_amount' => $total, 'currency_code' => 'KES',
        ]);
    }

    private function pay(Order $order, string $method = 'mpesa', ?float $amount = null)
    {
        return $this->postJson("/api/v1/admin/pos/pending-order/{$order->id}/pay", [
            'method' => $method, 'amount' => $amount ?? (float) $order->total_amount, 'reference' => 'QX' . uniqid(),
        ]);
    }

    /**
     * Past the gate: an overpayment is refused by the LATER balance check
     * (a completed sale cannot run under RefreshDatabase; see
     * PosPaymentReplayGuardTest), so 422 with no gate code means it got by.
     */
    private function assertPassesGate(Order $order): void
    {
        $res = $this->pay($order, 'mpesa', 999999);
        $this->assertSame(422, $res->status(), (string) $res->getContent());
        $this->assertNull($res->json('code'), 'The morning check refused it: ' . $res->getContent());
    }

    // ── The gate ─────────────────────────────────────────────────────────────

    public function test_a_till_left_open_overnight_refuses_every_tender_until_closed(): void
    {
        $clerk = $this->as($this->person('pos_clerk', $this->outlet));
        $stale = $this->till($clerk, now()->subDay()->setTime(9, 0));
        $order = $this->pendingOrder();

        foreach (['mpesa', 'card', 'cash'] as $method) {
            $this->pay($order, $method)
                ->assertStatus(409)
                ->assertJsonPath('code', 'TILL_FROM_EARLIER_DAY')
                ->assertJsonPath('till.id', $stale->id)
                ->assertJsonPath('till.trading_day', now()->subDay()->toDateString());
        }

        // Nothing was taken, and yesterday's drawer absorbed nothing.
        $this->assertSame(0, Payment::where('order_id', $order->id)->count());
        $this->assertEquals(0, (float) $stale->fresh()->total_sales);

        // A new sale is refused the same way.
        $product = Product::factory()->create();
        $this->postJson('/api/v1/admin/pos/sales', [
            'outlet_id' => $this->outlet->id, 'payment_method' => 'mpesa',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 500]],
        ])->assertStatus(409)->assertJsonPath('code', 'TILL_FROM_EARLIER_DAY');
    }

    public function test_with_no_till_open_even_mpesa_is_refused(): void
    {
        // Before: only a cash tender needed a till; M-Pesa and card sold with none.
        $this->as($this->person('pos_clerk', $this->outlet));
        $order = $this->pendingOrder();

        $this->pay($order, 'mpesa')->assertStatus(422)->assertJsonPath('code', 'TILL_NOT_OPEN');
        $this->assertSame(0, Payment::where('order_id', $order->id)->count());
    }

    public function test_todays_open_till_passes_the_gate(): void
    {
        $clerk = $this->as($this->person('pos_clerk', $this->outlet));
        $this->till($clerk, now()->startOfDay()->addMinute());
        $this->assertPassesGate($this->pendingOrder(2000));
    }

    public function test_the_day_boundary_is_midnight_in_nairobi(): void
    {
        $clerk = $this->as($this->person('pos_clerk', $this->outlet));
        Carbon::setTestNow(Carbon::parse('2026-10-15 00:01:00', 'Africa/Nairobi'));

        // 23:59 last night is yesterday — two minutes ago, but another day.
        $late = $this->till($clerk, Carbon::parse('2026-10-14 23:59:00', 'Africa/Nairobi'));
        $this->pay($this->pendingOrder())->assertStatus(409)->assertJsonPath('till.trading_day', '2026-10-14');

        // 00:00:30 this morning is today.
        $late->update(['status' => 'counted', 'closed_at' => now()]);
        $this->till($clerk, Carbon::parse('2026-10-15 00:00:30', 'Africa/Nairobi'));
        $this->assertPassesGate($this->pendingOrder());
    }

    // ── The morning flow, end to end ─────────────────────────────────────────

    public function test_the_morning_flow_report_then_count_then_open_today(): void
    {
        $clerk = $this->as($this->person('pos_clerk', $this->outlet));
        $yesterday = now()->subDay()->toDateString();
        $stale = $this->till($clerk, now()->subDay()->setTime(8, 30));

        // The POS learns of it from register status — whichever outlet it asks about.
        $status = $this->getJson("/api/v1/admin/pos/register/status?outlet_id={$this->outlet->id}")->assertOk();
        $status->assertJsonPath('stale_till.id', $stale->id)
            ->assertJsonPath('stale_till.trading_day', $yesterday)
            ->assertJsonPath('stale_till.eod_submitted', false)
            ->assertJsonPath('same_day_till_required', true);

        // Step 2 before step 1 is refused: no report for its day yet.
        $this->postJson('/api/v1/admin/pos/register/close', ['outlet_id' => $this->outlet->id, 'closing_cash' => 1000])
            ->assertStatus(422)->assertJsonPath('requires_eod', true);

        // A report must be for a day this till traded.
        $eod = fn (string $date) => $this->postJson('/api/v1/admin/pos/reports/user-eod', [
            'outlet_id' => $this->outlet->id, 'register_id' => $stale->id, 'date' => $date,
            'sentiments' => 'Busy afternoon; forgot to close.',
        ]);
        $eod(now()->addDay()->toDateString())->assertStatus(422);
        $eod(now()->subDays(2)->toDateString())->assertStatus(422);

        // Step 1: yesterday's report.
        $eod($yesterday)->assertOk();
        $this->getJson("/api/v1/admin/pos/register/status?outlet_id={$this->outlet->id}")
            ->assertJsonPath('stale_till.eod_submitted', true);

        // Step 2: the blind count. The till moves to counted, never to closed by itself.
        $this->postJson('/api/v1/admin/pos/register/close', ['outlet_id' => $this->outlet->id, 'closing_cash' => 1000])
            ->assertOk();
        $this->assertSame('counted', $stale->fresh()->status);
        $this->getJson("/api/v1/admin/pos/register/status?outlet_id={$this->outlet->id}")
            ->assertJsonPath('stale_till', null);

        // Its verifier is told it sat open overnight.
        $presented = app(TillPresenter::class)->present($stale->fresh(), null);
        $this->assertTrue($presented['left_open_overnight']);

        // Step 3: today's till, and selling works again.
        $this->postJson('/api/v1/admin/pos/register/open', ['outlet_id' => $this->outlet->id, 'opening_cash' => 1000])
            ->assertCreated();
        $this->assertPassesGate($this->pendingOrder());
    }

    public function test_a_same_day_close_still_takes_todays_report(): void
    {
        $clerk = $this->as($this->person('pos_clerk', $this->outlet));
        $this->postJson('/api/v1/admin/pos/register/open', ['outlet_id' => $this->outlet->id, 'opening_cash' => 500])
            ->assertCreated();
        $till = CashRegister::where('opened_by', $clerk->id)->firstOrFail();

        $this->postJson('/api/v1/admin/pos/reports/user-eod', [
            'outlet_id' => $this->outlet->id, 'register_id' => $till->id, 'date' => today()->toDateString(),
        ])->assertOk();
        $this->postJson('/api/v1/admin/pos/register/close', ['outlet_id' => $this->outlet->id, 'closing_cash' => 500])
            ->assertOk();

        $this->assertFalse(app(TillPresenter::class)->present($till->fresh(), null)['left_open_overnight']);
    }

    // ── Who and when the gate does not apply ─────────────────────────────────

    public function test_the_off_switch_lifts_the_gate_and_the_popup(): void
    {
        config(['pos.same_day_till_required' => false]);
        $clerk = $this->as($this->person('pos_clerk', $this->outlet));
        $this->till($clerk, now()->subDays(3));

        $this->getJson("/api/v1/admin/pos/register/status?outlet_id={$this->outlet->id}")
            ->assertJsonPath('stale_till', null)
            ->assertJsonPath('same_day_till_required', false);
        $this->assertPassesGate($this->pendingOrder());
    }

    public function test_the_sales_agent_works_no_drawer_and_is_not_gated(): void
    {
        $agent = User::factory()->create(['status' => 'active', 'email' => config('pos.agent_user_email')]);
        $agent->givePermissionTo(Permission::findOrCreate('pos.access', 'sanctum'));
        $agent->outlets()->attach($this->outlet->id);
        $this->as($agent);

        $this->assertPassesGate($this->pendingOrder());
    }

    // ── Reminders ────────────────────────────────────────────────────────────

    public function test_evening_nudge_and_morning_list_reach_the_right_people(): void
    {
        $owner   = $this->person('super_admin');
        $manager = $this->person('outlet_manager', $this->outlet);
        $elsewhereManager = $this->person('outlet_manager', Outlet::factory()->create());
        $tonight = $this->person('pos_clerk', $this->outlet);
        $forgot  = $this->person('pos_clerk', $this->outlet);
        $closed  = $this->person('pos_clerk', $this->outlet);

        $this->till($tonight, now()->startOfDay()->addHours(8));
        $this->till($forgot, now()->subDay()->setTime(8, 0));
        $this->till($closed, now()->subDay()->setTime(8, 0), 'counted');

        $count = fn (User $u) => DB::table('notifications')->where('notifiable_id', $u->id)->count();

        Artisan::call('tills:remind-open', ['--evening' => true]);
        $this->assertSame(1, $count($tonight), 'The cashier with a till open tonight is nudged.');
        $this->assertSame(0, $count($closed));

        Artisan::call('tills:remind-open', ['--morning' => true]);
        $this->assertSame(1, $count($owner));
        $this->assertSame(1, $count($manager));
        $this->assertSame(0, $count($elsewhereManager), 'Another outlet\'s manager is not told.');
        $body = json_decode(DB::table('notifications')->where('notifiable_id', $manager->id)->value('data'), true);
        $this->assertStringContainsString($forgot->first_name, $body['body']);
        $this->assertStringNotContainsString($closed->first_name . ' ' . $closed->last_name, $body['body']);

        // Off switch: nothing.
        config(['pos.same_day_till_required' => false]);
        Artisan::call('tills:remind-open', ['--morning' => true, '--evening' => true]);
        $this->assertSame(1, $count($manager));
    }
}
