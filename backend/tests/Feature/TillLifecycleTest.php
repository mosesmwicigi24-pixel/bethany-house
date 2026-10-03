<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Role hardening, Phase 4B part 1 — the till lifecycle (plan §17, phase4_spec 4B).
 *
 *   open (float counted blind, one open till per person, own outlet only)
 *   → counted (the operator's BLIND count: the expected figure never reaches her)
 *   → finalized (an outlet manager of that outlet, never the operator, verifies)
 *   → reconciled (the accountant, next day, against the payments ledger)
 *   → corrected only by a linked correction record (finance); never reopened.
 *
 * Every role here is the real one from permission:sync, cast on a real
 * Postgres, so a grant drifting in SyncPermissions shows up as a failure here.
 */
class TillLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        $this->outlet = Outlet::factory()->create();
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

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

    /** A till opened through the API by $clerk, with the float she typed. */
    private function openTill(User $clerk, float $float = 1000, ?Outlet $outlet = null): CashRegister
    {
        $this->as($clerk);
        $this->postJson('/api/v1/admin/pos/register/open', [
            'outlet_id' => ($outlet ?? $this->outlet)->id, 'opening_cash' => $float,
        ])->assertCreated();

        return CashRegister::where('opened_by', $clerk->id)->latest('id')->firstOrFail();
    }

    /**
     * One movement in the drawer, written exactly as PosController writes it:
     * the running columns move and a ledger row records the movement.
     */
    private function move(CashRegister $r, string $type, float $amount, ?int $orderId = null): void
    {
        $sign = in_array($type, ['sale', 'cash_in'], true) ? 1 : -1;
        DB::table('cash_registers')->where('id', $r->id)->update([
            'expected_cash'    => DB::raw('expected_cash + ' . ($sign * $amount)),
            'total_cash_sales' => DB::raw('total_cash_sales + ' . ($type === 'sale' ? $amount : 0)),
            'total_sales'      => DB::raw('total_sales + ' . ($type === 'sale' ? $amount : 0)),
            'total_refunds'    => DB::raw('total_refunds + ' . ($type === 'refund' ? $amount : 0)),
        ]);
        CashRegisterTransaction::create([
            'cash_register_id' => $r->id,
            'transaction_type' => $type,
            'payment_method'   => in_array($type, ['cash_in', 'cash_out'], true) ? null : 'cash',
            // recordCashLedger stores positive amounts; the model's helpers store
            // refunds / paid-outs negative. Both shapes exist in production.
            'amount'           => $type === 'cash_out' ? -$amount : $amount,
            'balance_after'    => 0,
            'order_id'         => $orderId,
            'created_by'       => $r->opened_by,
        ]);
    }

    private function submitEod(CashRegister $r): void
    {
        DB::table('cash_register_eod_reports')->insert([
            'register_id' => $r->id, 'user_id' => $r->opened_by, 'outlet_id' => $r->outlet_id,
            'report_date' => today(), 'submitted_at' => now(),
            'created_at'  => now(), 'updated_at' => now(),
        ]);
    }

    private function submitCount(User $clerk, CashRegister $r, float $counted)
    {
        $this->submitEod($r);
        $this->as($clerk);

        return $this->postJson('/api/v1/admin/pos/register/close', [
            'outlet_id' => $r->outlet_id, 'closing_cash' => $counted,
        ]);
    }

    private function finalize(User $manager, CashRegister $r, array $body = [])
    {
        $this->as($manager);

        return $this->postJson("/api/v1/admin/pos/tills/{$r->id}/finalize", $body);
    }

    /** Every key anywhere in a JSON payload. */
    private function keysOf(array $payload): array
    {
        $keys = [];
        $walk = function ($node) use (&$walk, &$keys) {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $k => $v) {
                if (is_string($k)) {
                    $keys[] = $k;
                }
                $walk($v);
            }
        };
        $walk($payload);

        return array_values(array_unique($keys));
    }

    private function assertNoExpectedFigure(array $payload, string $where): void
    {
        $leaks = array_intersect($this->keysOf($payload), [
            'expected_cash', 'expected_cash_at_count', 'expected_cash_running_at_count',
            'variance', 'variance_class', 'cash_difference', 'total_cash_sales', 'total_sales',
            'total_card_sales', 'total_mpesa_sales', 'total_refunds', 'float_vs_previous_close',
            'discrepancy', 'expected',
        ]);
        $this->assertSame([], array_values($leaks), "{$where} told the operator: " . implode(', ', $leaks));
    }

    // ── 1. Open ─────────────────────────────────────────────────────────────

    public function test_a_clerk_opens_her_own_till_at_her_outlet(): void
    {
        $clerk = $this->person('pos_clerk', $this->outlet);
        $r     = $this->openTill($clerk, 1500);

        $this->assertSame('open', $r->status);
        $this->assertSame(1, (int) $r->lifecycle_version);
        $this->assertSame($clerk->id, (int) $r->opened_by);
        $this->assertEquals(1500, $r->opening_balance);
    }

    public function test_a_clerk_cannot_open_a_till_at_an_outlet_she_is_not_assigned_to(): void
    {
        $clerk = $this->as($this->person('pos_clerk', $this->outlet));
        $other = Outlet::factory()->create();

        $this->postJson('/api/v1/admin/pos/register/open', ['outlet_id' => $other->id, 'opening_cash' => 100])
            ->assertForbidden();
        $this->assertSame(0, CashRegister::where('opened_by', $clerk->id)->count());
    }

    public function test_one_open_till_per_person_across_every_outlet(): void
    {
        $second = Outlet::factory()->create();
        $clerk  = $this->person('pos_clerk', $this->outlet);
        $clerk->outlets()->attach($second->id);
        $this->openTill($clerk);

        $this->as($clerk->fresh());
        $this->postJson('/api/v1/admin/pos/register/open', ['outlet_id' => $second->id, 'opening_cash' => 100])
            ->assertStatus(422);
        $this->assertSame(1, CashRegister::where('opened_by', $clerk->id)->count());
    }

    public function test_the_float_is_typed_blind_and_its_difference_from_the_last_count_is_logged_not_edited(): void
    {
        $clerk    = $this->person('pos_clerk', $this->outlet);
        $previous = CashRegister::create([
            'outlet_id' => $this->outlet->id, 'register_name' => 'Yesterday', 'status' => 'closed',
            'currency_code' => 'KES', 'opening_balance' => 1000, 'expected_cash' => 2000,
            'actual_cash' => 2000, 'closing_balance' => 2000,
            'opened_at' => now()->subDay(), 'closed_at' => now()->subDay()->addHours(8),
        ]);

        $this->as($clerk);
        $res = $this->postJson('/api/v1/admin/pos/register/open', [
            'outlet_id' => $this->outlet->id, 'opening_cash' => 1500,
        ])->assertCreated();

        // Blind: the opener is never told what the last count was.
        $this->assertNoExpectedFigure($res->json(), 'open');
        $this->assertStringNotContainsString('2000', json_encode($res->json()));

        $r = CashRegister::where('opened_by', $clerk->id)->firstOrFail();
        $this->assertEquals(1500, $r->opening_balance);            // what she typed stands
        $this->assertSame($previous->id, (int) $r->previous_register_id);
        $this->assertEquals(-500, $r->float_vs_previous_close);    // logged…
        $this->assertEquals(2000, $previous->fresh()->actual_cash); // …and nothing edited
        $this->assertDatabaseHas('activity_log', ['event' => 'till_float_difference', 'causer_id' => $clerk->id]);
    }

    // ── 2. Blind count ──────────────────────────────────────────────────────

    public function test_the_count_is_blind_the_operator_never_receives_the_expected_figure(): void
    {
        $clerk = $this->person('pos_clerk', $this->outlet);
        $r     = $this->openTill($clerk, 1000);
        $this->move($r, 'sale', 500);

        // Before submission: status, history, the till itself.
        $this->as($clerk);
        $this->assertNoExpectedFigure(
            $this->getJson("/api/v1/admin/pos/register/status?outlet_id={$this->outlet->id}")->assertOk()->json(),
            'register/status (open)',
        );
        $this->assertNoExpectedFigure(
            $this->getJson("/api/v1/admin/pos/register/history?outlet_id={$this->outlet->id}")->assertOk()->json(),
            'register/history (open)',
        );
        $this->assertNoExpectedFigure(
            $this->getJson("/api/v1/admin/pos/tills/{$r->id}")->assertOk()->json(),
            'tills/{id} (open)',
        );

        // At submission.
        $res = $this->submitCount($clerk, $r, 1450)->assertOk();
        $this->assertNoExpectedFigure($res->json(), 'register/close');
        $this->assertSame('counted', $res->json('register.status'));

        // After submission, still not finalized: still blind.
        $this->assertNoExpectedFigure(
            $this->getJson("/api/v1/admin/pos/tills/{$r->id}")->assertOk()->json(),
            'tills/{id} (counted)',
        );
        $this->assertNoExpectedFigure(
            $this->getJson('/api/v1/admin/pos/tills')->assertOk()->json(),
            'tills index (counted)',
        );

        // The server did compute it, and froze it.
        $r->refresh();
        $this->assertSame('counted', $r->status);
        $this->assertEquals(1450, $r->actual_cash);
        $this->assertEquals(1500, $r->expected_cash_at_count);
        $this->assertEquals(-50, $r->variance);
        $this->assertSame('short', $r->variance_class);
    }

    public function test_a_counted_till_takes_no_more_sales(): void
    {
        $clerk = $this->person('pos_clerk', $this->outlet);
        $r     = $this->openTill($clerk);
        $this->submitCount($clerk, $r, 1000)->assertOk();

        $this->assertSame(0, CashRegister::where('opened_by', $clerk->id)->open()->count());
        // and she may open the next shift while the count awaits verification
        $this->openTill($clerk->fresh(), 800);
        $this->assertSame(1, CashRegister::where('opened_by', $clerk->id)->open()->count());
    }

    // ── 3. Expected cash and variance ───────────────────────────────────────

    public function test_expected_cash_is_float_plus_cash_sales_minus_refunds_voids_and_paid_outs_from_the_ledger(): void
    {
        $clerk   = $this->person('pos_clerk', $this->outlet);
        $manager = $this->person('outlet_manager', $this->outlet);
        $r       = $this->openTill($clerk, 1000);
        $this->move($r, 'sale', 500);
        $this->move($r, 'sale', 300);
        $this->move($r, 'refund', 100);
        $this->move($r, 'void', 50);
        $this->move($r, 'cash_out', 200);
        // 1000 + 800 − 100 − 50 − 200 = 1450

        $this->submitCount($clerk, $r, 1450)->assertOk();
        $r->refresh();
        $this->assertEquals(1450, $r->expected_cash_at_count);
        $this->assertEquals(0, $r->variance);
        $this->assertSame('balanced', $r->variance_class);

        // The verifier sees all of it.
        $this->as($manager);
        $detail = $this->getJson("/api/v1/admin/pos/tills/{$r->id}")->assertOk();
        $this->assertEquals(1450, $detail->json('till.expected_cash_at_count'));
        $this->assertEquals(800, $detail->json('till.breakdown.cash_sales'));
        $this->assertEquals(200, $detail->json('till.breakdown.paid_outs'));
        $this->assertEquals(150, $detail->json('till.breakdown.refunds') + $detail->json('till.breakdown.voids'));
    }

    public function test_a_variance_needs_a_reason_and_is_logged_at_or_under_100(): void
    {
        $clerk   = $this->person('pos_clerk', $this->outlet);
        $manager = $this->person('outlet_manager', $this->outlet);
        $r       = $this->openTill($clerk, 1000);
        $this->submitCount($clerk, $r, 940)->assertOk();     // short 60

        $this->finalize($manager, $r)->assertStatus(422)->assertJsonValidationErrors('variance_reason');
        $this->assertNull($r->fresh()->finalized_at);

        $this->finalize($manager, $r, ['variance_reason' => 'Change given twice on one sale'])->assertOk();

        $this->assertDatabaseHas('till_discrepancies', [
            'cash_register_id' => $r->id, 'status' => 'logged', 'direction' => 'short',
            'operator_id' => $clerk->id, 'raised_by' => $manager->id,
        ]);
        $this->assertEquals(-60, DB::table('till_discrepancies')->where('cash_register_id', $r->id)->value('amount'));
    }

    public function test_a_variance_over_100_becomes_a_discrepancy_awaiting_approval(): void
    {
        $clerk   = $this->person('pos_clerk', $this->outlet);
        $manager = $this->person('outlet_manager', $this->outlet);
        $r       = $this->openTill($clerk, 1000);
        $this->move($r, 'sale', 2000);
        $this->submitCount($clerk, $r, 2500)->assertOk();    // short 500

        $res = $this->finalize($manager, $r, ['variance_reason' => 'Unknown — investigating'])->assertOk();
        $res->assertJsonPath('till.discrepancy.status', 'awaiting_approval');

        $d = DB::table('till_discrepancies')->where('cash_register_id', $r->id)->first();
        $this->assertSame('awaiting_approval', $d->status);
        $this->assertEquals(-500, $d->amount);
        $this->assertEquals(3000, $d->expected_cash);
        $this->assertEquals(2500, $d->counted_cash);
        $this->assertNull($d->approval_request_id);    // part 2 hands it to the engine
        // Finalized all the same: the batch is locked, the discrepancy is not absorbed.
        $this->assertNotNull($r->fresh()->finalized_at);
    }

    public function test_a_balanced_till_finalizes_without_a_reason_or_a_discrepancy(): void
    {
        $clerk   = $this->person('pos_clerk', $this->outlet);
        $manager = $this->person('outlet_manager', $this->outlet);
        $r       = $this->openTill($clerk, 1000);
        $this->submitCount($clerk, $r, 1000)->assertOk();

        $this->finalize($manager, $r)->assertOk()->assertJsonPath('till.stage', 'finalized');
        $this->assertSame('closed', $r->fresh()->status);
        $this->assertSame($manager->id, (int) $r->fresh()->verified_by);
        $this->assertSame(0, DB::table('till_discrepancies')->count());
    }

    // ── 2b. Verification: who ───────────────────────────────────────────────

    public function test_the_operator_cannot_verify_her_own_count(): void
    {
        // An outlet manager working her own till holds pos.till_verify — and
        // still may not verify the count she made.
        $manager = $this->person('outlet_manager', $this->outlet);
        $r       = $this->openTill($manager, 1000);
        $this->submitCount($manager, $r, 1000)->assertOk();

        $this->finalize($manager, $r)
            ->assertForbidden()
            ->assertJsonPath('code', 'SELF_APPROVAL');
        $this->assertNull($r->fresh()->finalized_at);
        $this->assertDatabaseHas('activity_log', ['event' => 'self_approval_blocked', 'causer_id' => $manager->id]);
    }

    public function test_a_clerk_cannot_verify_anyone(): void
    {
        $clerk = $this->person('pos_clerk', $this->outlet);
        $other = $this->person('pos_clerk', $this->outlet);
        $r     = $this->openTill($clerk);
        $this->submitCount($clerk, $r, 1000)->assertOk();

        $this->finalize($other, $r)->assertForbidden();
        $this->assertNull($r->fresh()->finalized_at);
    }

    public function test_a_manager_of_another_outlet_cannot_verify(): void
    {
        $clerk    = $this->person('pos_clerk', $this->outlet);
        $stranger = $this->person('outlet_manager', Outlet::factory()->create());
        $r        = $this->openTill($clerk);
        $this->submitCount($clerk, $r, 1000)->assertOk();

        // Another outlet's tills are not hers to see, let alone verify.
        $this->finalize($stranger, $r)->assertNotFound();
        $this->assertNull($r->fresh()->finalized_at);
    }

    public function test_an_open_till_cannot_be_finalized_before_it_is_counted(): void
    {
        $clerk   = $this->person('pos_clerk', $this->outlet);
        $manager = $this->person('outlet_manager', $this->outlet);
        $r       = $this->openTill($clerk);

        $this->finalize($manager, $r)->assertStatus(409);
        $this->assertSame('open', $r->fresh()->status);
    }

    // ── 2c. The lock (plan §17: PATCH finalized till → 409) ─────────────────

    public function test_patching_a_finalized_tills_money_is_refused_with_409(): void
    {
        $clerk   = $this->person('pos_clerk', $this->outlet);
        $manager = $this->person('outlet_manager', $this->outlet);
        $finance = $this->person('finance_manager');
        $r       = $this->openTill($clerk, 1000);
        $this->submitCount($clerk, $r, 1000)->assertOk();
        $this->finalize($manager, $r)->assertOk();
        $before = $r->fresh()->only(['opening_balance', 'actual_cash', 'expected_cash', 'closing_balance', 'variance']);

        foreach ([$clerk, $manager, $finance] as $who) {
            $this->as($who);
            $this->patchJson("/api/v1/admin/pos/tills/{$r->id}", ['actual_cash' => 9999, 'opening_balance' => 1])
                ->assertStatus(409)
                ->assertJsonPath('code', 'TILL_FINALIZED');
        }
        // Finalizing again, or "closing" it again, is not a way round it either.
        $this->finalize($this->person('outlet_manager', $this->outlet), $r)->assertStatus(409);

        $this->assertSame($before, $r->fresh()->only(array_keys($before)));
    }

    public function test_the_model_and_the_database_both_refuse_to_move_a_finalized_tills_money(): void
    {
        $clerk   = $this->person('pos_clerk', $this->outlet);
        $manager = $this->person('outlet_manager', $this->outlet);
        $r       = $this->openTill($clerk, 1000);
        $this->submitCount($clerk, $r, 1000)->assertOk();
        $this->finalize($manager, $r)->assertOk();

        try {
            $r->fresh()->update(['actual_cash' => 5]);
            $this->fail('Eloquent moved a finalized till');
        } catch (\App\Exceptions\TillFinalizedException) {
            $this->assertTrue(true);
        }

        // Underneath Eloquent: a raw write to its money is refused by the
        // trigger, while a column that carries no money (notes) still moves.
        DB::table('cash_registers')->where('id', $r->id)->update(['verification_notes' => 'Seen by the owner', 'updated_at' => now()]);
        $this->assertSame('Seen by the owner', $r->fresh()->verification_notes);

        try {
            // In a savepoint, so the refused statement does not poison the test's transaction.
            DB::transaction(fn () => DB::table('cash_registers')->where('id', $r->id)->update(['expected_cash' => 1]));
            $this->fail('the database moved a finalized till');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('is finalized; its money fields cannot change', $e->getMessage());
        }
        $this->assertEquals(1000, $r->fresh()->expected_cash);
    }

    public function test_a_discrepancys_facts_are_fixed_and_only_its_workflow_moves(): void
    {
        $clerk   = $this->person('pos_clerk', $this->outlet);
        $manager = $this->person('outlet_manager', $this->outlet);
        $r       = $this->openTill($clerk, 1000);
        $this->submitCount($clerk, $r, 700)->assertOk();
        $this->finalize($manager, $r, ['variance_reason' => 'Short 300'])->assertOk();
        $id = DB::table('till_discrepancies')->where('cash_register_id', $r->id)->value('id');

        // Part 2's engine moves the workflow columns.
        DB::table('till_discrepancies')->where('id', $id)->update(['approval_request_id' => 42, 'status' => 'approved']);
        $this->assertSame(42, (int) DB::table('till_discrepancies')->where('id', $id)->value('approval_request_id'));

        foreach ([
            fn () => DB::table('till_discrepancies')->where('id', $id)->update(['amount' => 0]),
            fn () => DB::table('till_discrepancies')->where('id', $id)->delete(),
        ] as $attempt) {
            try {
                DB::transaction($attempt);   // savepoint: the refusal must not poison the test's transaction
                $this->fail('a discrepancy\'s facts moved');
            } catch (\Illuminate\Database\QueryException) {
                $this->assertEquals(-300, DB::table('till_discrepancies')->where('id', $id)->value('amount'));
            }
        }
    }

    public function test_notes_on_an_unfinalized_till_can_still_be_amended_by_its_operator(): void
    {
        $clerk = $this->person('pos_clerk', $this->outlet);
        $r     = $this->openTill($clerk);
        $this->submitCount($clerk, $r, 1000)->assertOk();

        $this->as($clerk);
        $this->patchJson("/api/v1/admin/pos/tills/{$r->id}", ['notes' => 'Two 50s were torn'])->assertOk();
        $this->assertSame('Two 50s were torn', $r->fresh()->closing_notes);
        // Money is never patchable, finalized or not.
        $this->patchJson("/api/v1/admin/pos/tills/{$r->id}", ['actual_cash' => 2000])->assertStatus(422);
    }

    // ── Legacy registers (opened before the lifecycle, still open today) ────

    public function test_a_legacy_open_register_closes_through_the_new_flow(): void
    {
        $clerk   = $this->person('pos_clerk', $this->outlet);
        $manager = $this->person('outlet_manager', $this->outlet);
        // Opened in August under the old code, never closed. Its running
        // expected_cash grew with sales the July ledger never saw.
        $legacy = CashRegister::create([
            'outlet_id' => $this->outlet->id, 'register_name' => 'Legacy', 'status' => 'open',
            'currency_code' => 'KES', 'opening_balance' => 1000, 'expected_cash' => 5000,
            'total_cash_sales' => 4000, 'total_sales' => 4000,
            'opened_by' => $clerk->id, 'opened_at' => now()->subDays(60),
        ]);
        $this->assertNull($legacy->lifecycle_version);

        $this->submitCount($clerk, $legacy, 5000)->assertOk()->assertJsonPath('register.status', 'counted');
        $legacy->refresh();
        $this->assertEquals(1000, $legacy->expected_cash_at_count);          // the ledger basis
        $this->assertEquals(5000, $legacy->expected_cash_running_at_count);  // the running column, kept beside it
        $this->assertNull($legacy->lifecycle_version);                       // still reads as legacy

        $this->as($manager);
        $detail = $this->getJson("/api/v1/admin/pos/tills/{$legacy->id}")
            ->assertOk()
            ->assertJsonPath('till.legacy', true);
        $this->assertEquals(-4000, $detail->json('till.expected_basis_mismatch'));

        $this->finalize($manager, $legacy, ['variance_reason' => 'Opened before the ledger; sales from August'])
            ->assertOk();
        $d = DB::table('till_discrepancies')->where('cash_register_id', $legacy->id)->first();
        $this->assertEquals(-4000, $d->expected_basis_mismatch);
        $this->assertSame('awaiting_approval', $d->status);
    }

    public function test_legacy_closed_registers_are_left_exactly_as_they_were(): void
    {
        $closed = CashRegister::create([
            'outlet_id' => $this->outlet->id, 'register_name' => 'Old', 'status' => 'closed',
            'currency_code' => 'KES', 'opening_balance' => 1000, 'expected_cash' => 3000,
            'actual_cash' => 2900, 'closing_balance' => 2900,
            'opened_at' => now()->subDays(3), 'closed_at' => now()->subDays(3)->addHours(9),
        ]);

        $this->as($this->person('finance_manager'));
        $this->getJson("/api/v1/admin/pos/tills/{$closed->id}")
            ->assertOk()
            ->assertJsonPath('till.stage', 'closed_unverified');
        $this->patchJson("/api/v1/admin/pos/tills/{$closed->id}", ['actual_cash' => 3000])->assertStatus(409);
        $this->assertEquals(2900, $closed->fresh()->actual_cash);
    }

    // ── 4. Accountant reconciliation ────────────────────────────────────────

    /**
     * A finalized till with one 500 cash sale on $order. $duringShift writes
     * the payments ledger while the till is open, as the real sale would.
     */
    private function finalizedTillWithSale(User $clerk, User $manager, Order $order, callable $duringShift): CashRegister
    {
        $r = $this->openTill($clerk, 1000);
        $this->move($r, 'sale', 500, $order->id);
        $duringShift();
        $this->submitCount($clerk, $r, 1500)->assertOk();
        $this->finalize($manager, $r)->assertOk();

        return $r->fresh();
    }

    private function posOrder(User $clerk): Order
    {
        return Order::factory()->create([
            'order_type' => 'pos', 'outlet_id' => $this->outlet->id, 'created_by' => $clerk->id,
            'total_amount' => 500, 'status' => 'completed',
        ]);
    }

    public function test_the_accountant_reconciles_a_finalized_till_against_payments(): void
    {
        $clerk      = $this->person('pos_clerk', $this->outlet);
        $manager    = $this->person('outlet_manager', $this->outlet);
        $accountant = $this->person('accountant');
        $order      = $this->posOrder($clerk);
        $r = $this->finalizedTillWithSale($clerk, $manager, $order, fn () => Payment::factory()->create([
            'order_id' => $order->id, 'amount' => 500, 'payment_method' => 'cash',
        ]));

        $this->as($accountant);
        $res = $this->postJson("/api/v1/admin/pos/tills/{$r->id}/reconcile", ['notes' => 'Checked against the bank slip'])
            ->assertCreated();
        $res->assertJsonPath('reconciliation.status', 'matched');
        $res->assertJsonPath('reconciliation.flagged_for_finance', false);

        $this->assertDatabaseHas('till_reconciliations', [
            'cash_register_id' => $r->id, 'reconciled_by' => $accountant->id, 'status' => 'matched',
        ]);
    }

    public function test_a_reconciliation_mismatch_is_flagged_for_finance(): void
    {
        $clerk      = $this->person('pos_clerk', $this->outlet);
        $manager    = $this->person('outlet_manager', $this->outlet);
        $accountant = $this->person('accountant');
        $order      = $this->posOrder($clerk);
        $stray      = $this->posOrder($clerk);
        $r = $this->finalizedTillWithSale($clerk, $manager, $order, function () use ($order, $stray) {
            // The till says it took 500 cash; the payments ledger has 300.
            Payment::factory()->create(['order_id' => $order->id, 'amount' => 300, 'payment_method' => 'cash']);
            // And a cash payment the clerk took that never reached any till.
            Payment::factory()->create(['order_id' => $stray->id, 'amount' => 700, 'payment_method' => 'cash']);
        });

        $this->as($accountant);
        $res = $this->postJson("/api/v1/admin/pos/tills/{$r->id}/reconcile")->assertCreated();
        $res->assertJsonPath('reconciliation.status', 'mismatch');
        $res->assertJsonPath('reconciliation.flagged_for_finance', true);
        $this->assertEquals(200, $res->json('reconciliation.difference'));
        $this->assertSame(1, $res->json('reconciliation.missing_from_till_count'));
        $this->assertEquals(700, $res->json('reconciliation.missing_from_till_amount'));

        // Finance finds it in the list.
        $this->as($this->person('finance_manager'));
        $ids = collect($this->getJson('/api/v1/admin/pos/tills?reconciliation=mismatch')->assertOk()->json('data'))
            ->pluck('id')->all();
        $this->assertSame([$r->id], $ids);
    }

    public function test_only_a_finalized_till_can_be_reconciled_and_only_by_an_independent_person(): void
    {
        $clerk   = $this->person('pos_clerk', $this->outlet);
        $manager = $this->person('outlet_manager', $this->outlet);
        $r       = $this->openTill($clerk);
        $this->submitCount($clerk, $r, 1000)->assertOk();

        $this->as($this->person('accountant'));
        $this->postJson("/api/v1/admin/pos/tills/{$r->id}/reconcile")->assertStatus(409);

        // A clerk, or the manager, holds no pos.reconcile.
        $this->finalize($manager, $r)->assertOk();
        foreach ([$clerk, $manager] as $who) {
            $this->as($who);
            $this->postJson("/api/v1/admin/pos/tills/{$r->id}/reconcile")->assertForbidden();
        }

        // Finance holds it — but not over a till they verified (maker ≠ checker).
        $financeVerifier = $this->person('finance_manager', $this->outlet);
        $financeVerifier->givePermissionTo('pos.till_verify');
        $r2 = $this->openTill($this->person('pos_clerk', $this->outlet));
        $this->submitCount(User::find($r2->opened_by), $r2, 1000)->assertOk();
        $this->finalize($financeVerifier->fresh(), $r2)->assertOk();
        $this->as($financeVerifier->fresh());
        $this->postJson("/api/v1/admin/pos/tills/{$r2->id}/reconcile")
            ->assertForbidden()->assertJsonPath('code', 'SELF_APPROVAL');
    }

    // ── 5. No reopen: a linked correction ───────────────────────────────────

    public function test_finance_opens_a_linked_correction_and_the_original_is_unchanged(): void
    {
        $clerk   = $this->person('pos_clerk', $this->outlet);
        $manager = $this->person('outlet_manager', $this->outlet);
        $finance = $this->person('finance_manager');
        $r       = $this->openTill($clerk, 1000);
        $this->submitCount($clerk, $r, 900)->assertOk();
        $this->finalize($manager, $r, ['variance_reason' => 'Short 100'])->assertOk();
        $before = $r->fresh()->toArray();

        $this->as($finance);
        $this->postJson("/api/v1/admin/pos/tills/{$r->id}/corrections", ['corrected_actual_cash' => 1000])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $res = $this->postJson("/api/v1/admin/pos/tills/{$r->id}/corrections", [
            'reason' => 'A 100 note was found in the safe bag the next morning',
            'corrected_actual_cash' => 1000,
        ])->assertCreated();
        $res->assertJsonPath('correction.cash_register_id', $r->id);
        $this->assertEquals(-100, $res->json('correction.original_variance'));
        $this->assertEquals(0, $res->json('correction.corrected_variance'));

        $after = $r->fresh()->toArray();
        unset($before['updated_at'], $after['updated_at']);
        $this->assertSame($before, $after, 'the original till moved');
        $this->assertSame(1, DB::table('till_corrections')->where('cash_register_id', $r->id)->count());
    }

    public function test_only_finance_corrects_and_only_a_finalized_till(): void
    {
        $clerk   = $this->person('pos_clerk', $this->outlet);
        $manager = $this->person('outlet_manager', $this->outlet);
        $r       = $this->openTill($clerk, 1000);
        $this->submitCount($clerk, $r, 1000)->assertOk();

        $this->as($this->person('finance_manager'));
        $this->postJson("/api/v1/admin/pos/tills/{$r->id}/corrections", ['reason' => 'Not yet finalized — refuse'])
            ->assertStatus(409);

        $this->finalize($manager, $r)->assertOk();
        foreach ([$this->person('accountant'), $manager, $clerk] as $who) {
            $this->as($who);
            $this->postJson("/api/v1/admin/pos/tills/{$r->id}/corrections", ['reason' => 'Should not be allowed here'])
                ->assertForbidden();
        }
        $this->assertSame(0, DB::table('till_corrections')->count());
    }

    public function test_there_is_no_way_to_reopen_a_finalized_till(): void
    {
        $clerk   = $this->person('pos_clerk', $this->outlet);
        $manager = $this->person('outlet_manager', $this->outlet);
        $r       = $this->openTill($clerk, 1000);
        $this->submitCount($clerk, $r, 1000)->assertOk();
        $this->finalize($manager, $r)->assertOk();

        $this->as($this->person('finance_manager'));
        $this->patchJson("/api/v1/admin/pos/tills/{$r->id}", ['status' => 'open'])->assertStatus(409);
        $this->assertSame('closed', $r->fresh()->status);
        $this->assertNotNull($r->fresh()->finalized_at);
    }

    // ── 6. Visibility ───────────────────────────────────────────────────────

    public function test_who_sees_which_tills(): void
    {
        $otherOutlet = Outlet::factory()->create();
        $clerk       = $this->person('pos_clerk', $this->outlet);
        $colleague   = $this->person('pos_clerk', $this->outlet);
        $faraway     = $this->person('pos_clerk', $otherOutlet);

        $mine       = $this->openTill($clerk);
        $mineOld    = CashRegister::create([
            'outlet_id' => $this->outlet->id, 'register_name' => 'Old', 'status' => 'closed', 'currency_code' => 'KES',
            'opened_by' => $clerk->id, 'opened_at' => now()->subDays(10), 'closed_at' => now()->subDays(10),
        ]);
        $mineOldOpen = CashRegister::create([   // legacy, never closed: she must still see it to close it
            'outlet_id' => $this->outlet->id, 'register_name' => 'Old open', 'status' => 'counted', 'currency_code' => 'KES',
            'opened_by' => $clerk->id, 'opened_at' => now()->subDays(40),
        ]);
        $hers  = $this->openTill($colleague);
        $there = $this->openTill($faraway, 100, $otherOutlet);

        $ids = fn () => collect($this->getJson('/api/v1/admin/pos/tills')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $sorted = fn (array $a) => collect($a)->sort()->values()->all();

        $this->as($clerk);
        $this->assertSame($sorted([$mine->id, $mineOldOpen->id]), $ids());
        $this->getJson("/api/v1/admin/pos/tills/{$hers->id}")->assertNotFound();
        $this->getJson("/api/v1/admin/pos/tills/{$mineOld->id}")->assertNotFound();

        $this->as($this->person('outlet_manager', $this->outlet));
        $this->assertSame($sorted([$mine->id, $mineOld->id, $mineOldOpen->id, $hers->id]), $ids());
        $this->getJson("/api/v1/admin/pos/tills/{$there->id}")->assertNotFound();

        foreach (['accountant', 'finance_manager', 'admin'] as $global) {
            $this->as($this->person($global));
            $this->assertSame($sorted([$mine->id, $mineOld->id, $mineOldOpen->id, $hers->id, $there->id]), $ids(), $global);
        }
    }

    public function test_register_history_follows_the_same_visibility(): void
    {
        $clerk     = $this->person('pos_clerk', $this->outlet);
        $colleague = $this->person('pos_clerk', $this->outlet);
        $mine      = $this->openTill($clerk);
        $this->openTill($colleague);

        $this->as($clerk);
        $ids = collect($this->getJson("/api/v1/admin/pos/register/history?outlet_id={$this->outlet->id}")->assertOk()->json('data'))
            ->pluck('id')->all();
        $this->assertSame([$mine->id], $ids);
    }
}
