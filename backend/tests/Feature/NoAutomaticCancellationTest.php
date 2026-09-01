<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The system does not cancel receipts. Only a person does.
 *
 * An hourly job cancelled unpaid POS orders older than 24 hours. Between 10
 * July and 30 August it cancelled 12 real orders worth KES 170,400 — including
 * a KES 49,500 sale — each one a customer a human had served at the counter.
 * A day's silence is not an abandonment.
 *
 * Owner's rule, 2026-09-01. These tests exist so nobody restores the automation
 * by habit.
 */
class NoAutomaticCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_nothing_schedules_the_reaper(): void
    {
        $schedule = File::get(base_path('routes/console.php'));

        $this->assertStringNotContainsString('ReapAbandonedOrders::class', $schedule,
            'the abandoned-order reaper must never be scheduled again — only a person cancels an order');
    }

    public function test_no_schedule_mentions_cancelling(): void
    {
        // Any future auto-cancel would land here too.
        $schedule = File::get(base_path('routes/console.php'));

        foreach (['CancelAbandoned', 'reap-abandoned'] as $needle) {
            $this->assertStringNotContainsString("Schedule::command(\\App\\Console\\Commands\\{$needle}", $schedule);
        }
    }

    public function test_the_command_reports_and_cancels_nothing_without_force(): void
    {
        $stale = Order::factory()->create([
            'order_type'     => 'pos',
            'status'         => 'pending',
            'payment_status' => 'pending',
            'currency_code'  => 'KES',
            'total_amount'   => 12000,
            'created_at'     => now()->subDays(5),
        ]);

        $this->artisan('pos:reap-abandoned-orders')
            ->expectsOutputToContain('NOTHING has been cancelled')
            ->assertSuccessful();

        $this->assertSame('pending', $stale->fresh()->status,
            'a report must never change an order');
    }

    public function test_force_is_what_cancels_and_that_is_a_persons_choice(): void
    {
        $stale = Order::factory()->create([
            'order_type'     => 'pos',
            'status'         => 'pending',
            'payment_status' => 'pending',
            'currency_code'  => 'KES',
            'total_amount'   => 12000,
            'created_at'     => now()->subDays(5),
        ]);

        $this->artisan('pos:reap-abandoned-orders --force')->assertSuccessful();

        $this->assertSame('cancelled', $stale->fresh()->status);
    }

    public function test_an_order_with_money_is_never_reaped_even_with_force(): void
    {
        $paid = Order::factory()->create([
            'order_type'     => 'pos',
            'status'         => 'pending',
            'payment_status' => 'pending',
            'currency_code'  => 'KES',
            'total_amount'   => 12000,
            'created_at'     => now()->subDays(5),
        ]);
        \App\Models\Payment::factory()->create([
            'order_id' => $paid->id, 'status' => 'paid', 'amount' => 5000,
        ]);

        $this->artisan('pos:reap-abandoned-orders --force')->assertSuccessful();

        $this->assertSame('pending', $paid->fresh()->status,
            'money on an order has always been sacred — keep it that way');
    }
}
