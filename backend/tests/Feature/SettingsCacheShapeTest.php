<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\SettingController;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The settings cache holds one key in two shapes, and it cost customers money.
 *
 * SettingController::index() caches a Collection under 'app_settings' (and
 * deliberately busts any array it finds); getAll() caches an array. They
 * overwrite each other, so whichever ran last decides what the next reader
 * gets — and getAll() used to array_merge() whatever it found.
 *
 * The result was a customer-facing 500: every time a staff member opened the
 * Settings screen, the next 300 seconds of /pay/{token} requests died with
 * "array_merge(): Argument #2 must be of type array, Collection given". The
 * production log carried 221 of them, in bursts, going back to 17 June.
 */
class SettingsCacheShapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_all_survives_a_collection_in_the_cache(): void
    {
        // Exactly what index() leaves behind.
        Cache::put('app_settings', collect(['app_name' => 'Bethany House']), 300);

        $settings = SettingController::getAll();

        $this->assertIsArray($settings);
        $this->assertSame('Bethany House', $settings['app_name']);
    }

    public function test_get_all_survives_an_array_in_the_cache(): void
    {
        Cache::put('app_settings', ['app_name' => 'Bethany House'], 300);

        $this->assertSame('Bethany House', SettingController::getAll()['app_name']);
    }

    public function test_defaults_still_fill_the_gaps(): void
    {
        Cache::put('app_settings', collect([]), 300);

        // A key the DB does not set must still come back from DEFAULTS.
        $this->assertArrayHasKey('app_name', SettingController::getAll());
    }

    public function test_the_customer_payment_page_survives_a_poisoned_cache(): void
    {
        // The actual outage: staff open Settings, then a customer opens their
        // payment link and gets a 500.
        $order = Order::factory()->create([
            'status' => 'confirmed', 'payment_status' => 'pending',
            'currency_code' => 'KES', 'total_amount' => 5000,
            'payment_token' => bin2hex(random_bytes(16)),
            'payment_token_expires_at' => now()->addHours(72),
        ]);
        Cache::put('app_settings', collect(['app_name' => 'Bethany House']), 300);

        $this->getJson("/api/v1/pay/{$order->payment_token}")->assertOk();
        $this->getJson("/api/v1/order/{$order->public_token}")->assertOk();
    }
}
