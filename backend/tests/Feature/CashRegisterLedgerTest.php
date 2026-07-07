<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the cash-drawer accounting primitives: sales credit only the cash
 * portion, non-cash is still recorded, voids reverse EXACTLY what was collected
 * (not the order total), refunds debit correctly, and every movement journals a
 * cash_register_transactions row with a running balance_after.
 */
class CashRegisterLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function openRegister(float $opening = 1000): CashRegister
    {
        return CashRegister::create([
            'outlet_id'       => Outlet::factory()->create()->id,
            'register_name'   => 'Test Till',
            'status'          => 'open',
            'opening_balance' => $opening,
            'expected_cash'   => $opening,
            'opened_by'       => User::factory()->create()->id,
            'opened_at'       => now(),
        ]);
    }

    public function test_full_cash_sale_credits_the_drawer_and_journals_it(): void
    {
        $r = $this->openRegister(1000);

        $r->postSale(1000, 0, 0, 1000, null);
        $r->refresh();

        $this->assertEquals(2000, $r->expected_cash);
        $this->assertEquals(1000, $r->total_cash_sales);
        $this->assertEquals(1000, $r->total_sales);
        $this->assertEquals(1, $r->transaction_count);
        $this->assertDatabaseHas('cash_register_transactions', [
            'cash_register_id' => $r->id,
            'transaction_type' => 'sale',
            'balance_after'    => 2000,
        ]);
    }

    public function test_split_sale_credits_only_the_cash_portion(): void
    {
        $r = $this->openRegister(1000);

        $r->postSale(600, 0, 400, 1000, null); // 600 cash + 400 mpesa
        $r->refresh();

        $this->assertEquals(1600, $r->expected_cash);   // cash only
        $this->assertEquals(600, $r->total_cash_sales);
        $this->assertEquals(400, $r->total_mpesa_sales);
        $this->assertEquals(1000, $r->total_sales);
    }

    public function test_non_cash_sale_is_recorded_without_moving_the_drawer(): void
    {
        $r = $this->openRegister(1000);

        $r->postSale(0, 0, 500, 500, null); // pure mpesa
        $r->refresh();

        $this->assertEquals(1000, $r->expected_cash);   // untouched
        $this->assertEquals(500, $r->total_mpesa_sales);
        $this->assertEquals(1, $r->transaction_count);  // still counted (was dropped before)
    }

    public function test_void_reverses_exactly_the_cash_a_deposit_sale_added(): void
    {
        $r = $this->openRegister(1000);
        $r->postSale(300, 0, 0, 300, null);             // 300 cash deposit on a 1000 order
        $r->refresh();
        $this->assertEquals(1300, $r->expected_cash);

        $r->postVoid(300, 0, 0, 300, null);             // reverses 300, NOT the 1000 order total
        $r->refresh();

        $this->assertEquals(1000, $r->expected_cash);   // back to opening — no drift
        $this->assertEquals(0, $r->total_cash_sales);
        $this->assertEquals(0, $r->transaction_count);
        $this->assertDatabaseHas('cash_register_transactions', [
            'cash_register_id' => $r->id,
            'transaction_type' => 'void',
            'balance_after'    => 1000,
        ]);
    }

    public function test_cash_refund_debits_the_drawer_and_journals_it(): void
    {
        $r = $this->openRegister(1000);

        $r->recordRefund(200, 'cash', null);
        $r->refresh();

        $this->assertEquals(800, $r->expected_cash);
        $this->assertEquals(200, $r->total_refunds);
        $this->assertDatabaseHas('cash_register_transactions', [
            'cash_register_id' => $r->id,
            'transaction_type' => 'refund',
            'balance_after'    => 800,
        ]);
    }
}
