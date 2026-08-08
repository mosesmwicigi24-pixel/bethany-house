<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The /pay/{token} payload names the customer and shows their phone IN FULL.
 *
 * Masking was implemented here and then removed at the owner's explicit
 * direction (2026-08-08): the page is the customer's own receipt and they
 * should see their own number on it. The trade-off is known and accepted —
 * a forwarded payment link carries a complete number with it.
 *
 * These tests exist to pin that decision rather than to defend it. Re-masking
 * would fail them, which forces the change to be a conscious one instead of a
 * quiet "security tidy-up" by whoever next reads this controller.
 */
class PayPageCustomerContactTest extends TestCase
{
    use RefreshDatabase;

    private function orderWith(array $attrs): Order
    {
        return Order::factory()->create($attrs + [
            'payment_token'            => 'tok-'.bin2hex(random_bytes(8)),
            'payment_token_expires_at' => now()->addDays(7),
        ]);
    }

    public function test_the_phone_is_returned_in_full_and_not_masked(): void
    {
        $order = $this->orderWith(['customer_phone' => '0798238300']);

        $this->getJson("/api/v1/pay/{$order->payment_token}")
            ->assertOk()
            ->assertJsonPath('customer_phone', '0798238300');
    }

    public function test_an_international_number_is_returned_in_full_too(): void
    {
        $order = $this->orderWith(['customer_phone' => '+254798238300']);

        $this->getJson("/api/v1/pay/{$order->payment_token}")
            ->assertOk()
            ->assertJsonPath('customer_phone', '+254798238300');
    }

    public function test_the_full_name_is_assembled_from_first_and_last(): void
    {
        $order = $this->orderWith([
            'customer_first_name' => 'Michael',
            'customer_last_name'  => 'Nzioki',
        ]);

        $this->getJson("/api/v1/pay/{$order->payment_token}")
            ->assertOk()
            ->assertJsonPath('customer_name', 'Michael Nzioki');
    }

    public function test_a_name_with_only_a_first_part_does_not_carry_a_trailing_space(): void
    {
        // implode + array_filter + trim: a missing surname must not produce
        // "Michael " with a dangling space, which would render visibly.
        $order = $this->orderWith([
            'customer_first_name' => 'Michael',
            'customer_last_name'  => null,
        ]);

        $this->getJson("/api/v1/pay/{$order->payment_token}")
            ->assertOk()
            ->assertJsonPath('customer_name', 'Michael');
    }

    public function test_no_name_at_all_is_null_rather_than_an_empty_string(): void
    {
        // The client renders on truthiness, so "" would print an empty line
        // where the name should be.
        $order = $this->orderWith([
            'customer_first_name' => null,
            'customer_last_name'  => null,
            'user_id'             => null,
        ]);

        $this->getJson("/api/v1/pay/{$order->payment_token}")
            ->assertOk()
            ->assertJsonPath('customer_name', null);
    }
}
