<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /pay/{token} is UNAUTHENTICATED and the link is designed to be sent to a
 * customer over WhatsApp — which means it can be forwarded on. It should not
 * carry a complete, dialable phone number when it is.
 *
 * The masking is server-side on purpose. Doing it in the React component would
 * be cosmetic: the endpoint would still return the full number and anyone with
 * the link could read it from the network tab. These tests assert on the API
 * PAYLOAD, not on rendered markup, so they fail if someone later "simplifies"
 * the controller and moves the masking to the client.
 */
class PayPagePhoneMaskingTest extends TestCase
{
    use RefreshDatabase;

    private function orderWithPhone(string $phone): Order
    {
        return Order::factory()->create([
            'customer_phone'            => $phone,
            'payment_token'             => 'tok-'.bin2hex(random_bytes(8)),
            'payment_token_expires_at'  => now()->addDays(7),
        ]);
    }

    public function test_a_local_number_keeps_only_its_first_four_and_last_three(): void
    {
        $order = $this->orderWithPhone('0798238300');

        $this->getJson("/api/v1/pay/{$order->payment_token}")
            ->assertOk()
            ->assertJsonPath('customer_phone', '0798***300');
    }

    public function test_an_international_number_masks_more_of_its_middle(): void
    {
        $order = $this->orderWithPhone('+254798238300');

        $this->getJson("/api/v1/pay/{$order->payment_token}")
            ->assertOk()
            ->assertJsonPath('customer_phone', '+254******300');
    }

    public function test_the_full_number_never_appears_anywhere_in_the_payload(): void
    {
        // The real point of the feature: not "is the field masked" but "is the
        // number absent". A future field echoing it elsewhere must fail here.
        $order = $this->orderWithPhone('0798238300');

        $body = $this->getJson("/api/v1/pay/{$order->payment_token}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('0798238300', $body);
    }

    public function test_a_number_too_short_to_mask_safely_is_hidden_entirely(): void
    {
        // Keeping 4 + 3 of a 7-character value would reveal all of it, so short
        // or malformed input is masked completely rather than passed through.
        $order = $this->orderWithPhone('0798238');

        $this->getJson("/api/v1/pay/{$order->payment_token}")
            ->assertOk()
            ->assertJsonPath('customer_phone', '*******');
    }

    public function test_a_missing_number_stays_null_rather_than_becoming_asterisks(): void
    {
        $order = $this->orderWithPhone('');

        $this->getJson("/api/v1/pay/{$order->payment_token}")
            ->assertOk()
            ->assertJsonPath('customer_phone', null);
    }
}
