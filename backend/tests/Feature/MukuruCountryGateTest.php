<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Mukuru only takes senders in specific markets. The payment page must not
 * offer it to a customer whose phone places them outside those markets —
 * and the initiate endpoint must refuse it even if someone posts the code
 * directly. Every other method stays ungated (no sender_countries key).
 */
class MukuruCountryGateTest extends TestCase
{
    use RefreshDatabase;

    private function orderWith(?string $phone): Order
    {
        return Order::factory()->create([
            'customer_phone'           => $phone,
            'status'                   => 'confirmed',
            'payment_status'           => 'pending',
            'currency_code'            => 'KES',
            'total_amount'             => 5000,
            'payment_token'            => 'tok-' . bin2hex(random_bytes(8)),
            'payment_token_expires_at' => now()->addDays(7),
        ]);
    }

    private function methodCodes(Order $order): array
    {
        return collect($this->getJson("/api/v1/pay/{$order->payment_token}")
            ->assertOk()->json('available_methods'))->pluck('code')->all();
    }

    public function test_a_south_african_customer_sees_mukuru(): void
    {
        $this->assertContains('mukuru', $this->methodCodes($this->orderWith('+27 78 227 5061')));
    }

    public function test_a_kenyan_customer_does_not_see_mukuru(): void
    {
        // Owner's rule (2026-08-22): Kenyans pay by M-Pesa — Mukuru at home
        // is noise. The domestic rails stay on offer.
        $codes = $this->methodCodes($this->orderWith('0743942757'));
        $this->assertNotContains('mukuru', $codes);
        $this->assertContains('mpesa_manual', $codes);
    }

    public function test_an_ethiopian_customer_does_not_see_mukuru(): void
    {
        $codes = $this->methodCodes($this->orderWith('+251 972 763 678'));
        $this->assertNotContains('mukuru', $codes);
        // The gate is per-rail: the other manual rails stay on offer.
        $this->assertContains('wu_moneygram', $codes);
    }

    public function test_a_us_customer_does_not_see_mukuru(): void
    {
        $this->assertNotContains('mukuru', $this->methodCodes($this->orderWith('+1 415 555 0100')));
    }

    public function test_a_uk_customer_written_with_00_prefix_sees_mukuru(): void
    {
        // 0044… must read as +44 (a Mukuru send market), not as a Kenyan
        // 0-led number — the same rule the DB's normalize_phone applies.
        $this->assertContains('mukuru', $this->methodCodes($this->orderWith('0044 7401 182700')));
    }

    public function test_no_phone_means_no_gate(): void
    {
        // "We cannot place you" is not "it will not work there".
        $this->assertContains('mukuru', $this->methodCodes($this->orderWith(null)));
    }

    public function test_initiate_refuses_a_gated_method_posted_directly(): void
    {
        $order = $this->orderWith('+251 972 763 678');

        $this->postJson("/api/v1/pay/{$order->payment_token}/initiate", ['method' => 'mukuru'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This payment method is not available in your country.');
    }

    public function test_initiate_accepts_mukuru_from_a_send_market(): void
    {
        $order = $this->orderWith('+27 78 227 5061');

        $this->postJson("/api/v1/pay/{$order->payment_token}/initiate", ['method' => 'mukuru'])
            ->assertOk();
    }

    public function test_ungated_methods_ignore_the_country_entirely(): void
    {
        // wu_moneygram has no sender_countries key — an Ethiopian customer
        // can still initiate it.
        $order = $this->orderWith('+251 972 763 678');

        $this->postJson("/api/v1/pay/{$order->payment_token}/initiate", ['method' => 'wu_moneygram'])
            ->assertOk();
    }

    public function test_the_seed_actually_gated_the_mukuru_row(): void
    {
        $config = json_decode(
            DB::table('payment_methods')->where('code', 'mukuru')->value('configuration') ?? '{}',
            true,
        );

        $this->assertIsArray($config['sender_countries'] ?? null,
            'the migration must stamp sender_countries onto the mukuru row');
        $this->assertContains('+27', $config['sender_countries']);
        $this->assertNotContains('+251', $config['sender_countries']);
        $this->assertNotContains('+254', $config['sender_countries'], 'Kenya is deliberately absent — owner rule');
    }
}
