<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReplenishmentPing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Automated WhatsApp reorder pings (replenishment:send-pings).
 *
 * The daily job turns the Replenishment Radar's due list into business-
 * initiated 'reorder_reminder' template sends over the shared WhatsApp
 * number (replies land in Neema's webhook — the Hub only sends). These
 * tests fake the Graph API and pin the safety rails: correct template
 * params, per-cycle cooldown, per-customer opt-out, dry-run inertness,
 * the per-run send cap, and the disabled-by-default schedule gate.
 */
class ReplenishmentPingTest extends TestCase
{
    use RefreshDatabase;

    private function configureWhatsApp(): void
    {
        config([
            'services.whatsapp.token'           => 'test-token',
            'services.whatsapp.phone_number_id' => '123456789',
            'services.whatsapp.api_version'     => 'v19.0',
        ]);
    }

    private function fakeGraphOk(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test.1']]], 200),
        ]);
    }

    /** A named product with an English translation, like the real catalogue. */
    private function product(string $name): Product
    {
        $product = Product::factory()->create();
        DB::table('product_translations')->insert([
            'product_id' => $product->id, 'language_code' => 'en', 'name' => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $product;
    }

    /** One sale of one product line, $daysAgo days ago. */
    private function sell(string $phone, string $name, Product $product, float $lineTotal, int $daysAgo): void
    {
        $when = now()->subDays($daysAgo);
        $order = Order::factory()->create([
            'order_type' => 'pos', 'status' => 'confirmed', 'payment_status' => 'paid',
            'total_amount' => $lineTotal, 'currency_code' => 'KES',
            'customer_phone' => $phone, 'customer_first_name' => $name,
            'created_at' => $when,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $order->id, 'product_id' => $product->id,
            'sku' => "SKU-{$product->id}", 'product_name' => 'line snapshot',
            'quantity' => 1, 'unit_price' => $lineTotal, 'total_price' => $lineTotal,
            'created_at' => $when, 'updated_at' => $when,
        ]);
    }

    /**
     * Four purchases 30 days apart, the last 35 days ago: cycle 30,
     * ~5 days over — squarely in the due window, exactly like the
     * rhythmic buyer in ReplenishmentRadarTest.
     */
    private function seedDueBuyer(string $phone, string $name, string $productName, float $value = 4000): Product
    {
        $product = $this->product($productName);
        foreach ([125, 95, 65, 35] as $daysAgo) {
            $this->sell($phone, $name, $product, $value, $daysAgo);
        }

        return $product;
    }

    public function test_due_customer_gets_template_ping_with_correct_params(): void
    {
        $this->configureWhatsApp();
        $this->fakeGraphOk();
        $bread = $this->seedDueBuyer('0722000111', 'Grace', 'Holy Communion Bread');

        $this->artisan('replenishment:send-pings')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function ($req) {
            $data = $req->data();

            return str_contains($req->url(), 'graph.facebook.com/v19.0/123456789/messages')
                && $data['to'] === '254722000111'                       // canonical digits, no '+'
                && $data['type'] === 'template'
                && $data['template']['name'] === 'reorder_reminder'
                && $data['template']['language']['code'] === 'en'
                && $data['template']['components'][0]['type'] === 'body'
                && $data['template']['components'][0]['parameters'][0]['text'] === 'Grace'
                && $data['template']['components'][0]['parameters'][1]['text'] === '35'
                && $data['template']['components'][0]['parameters'][2]['text'] === 'Holy Communion Bread'
                && $data['template']['components'][1]['sub_type'] === 'quick_reply';
        });

        $this->assertDatabaseHas('replenishment_pings', [
            'phone'        => '254722000111',
            'product_id'   => $bread->id,
            'product_name' => 'Holy Communion Bread',
            'status'       => 'sent',
            'wamid'        => 'wamid.test.1',
        ]);
    }

    public function test_cooldown_blocks_a_second_ping_within_the_cycle(): void
    {
        $this->configureWhatsApp();
        $this->fakeGraphOk();
        $this->seedDueBuyer('0722000111', 'Grace', 'Holy Communion Bread');

        $this->artisan('replenishment:send-pings')->assertSuccessful();
        $this->artisan('replenishment:send-pings')->assertSuccessful();

        Http::assertSentCount(1); // second run must not send again

        $statuses = ReplenishmentPing::orderBy('id')->pluck('status')->all();
        $this->assertSame(['sent', 'skipped_cooldown'], $statuses);
    }

    public function test_provisional_due_rows_are_never_pinged(): void
    {
        $this->configureWhatsApp();
        Http::fake();
        // Two purchases 30 days apart, the last 35 days ago: on the radar as
        // tier 'provisional' (due now), but automation only acts on
        // ESTABLISHED rhythms — a provisional-only due list sends nothing
        // and leaves no ping trail at all.
        $wine = $this->product('Altar Wine');
        foreach ([65, 35] as $daysAgo) {
            $this->sell('0755000444', 'Miriam', $wine, 6000, $daysAgo);
        }

        $this->artisan('replenishment:send-pings')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, ReplenishmentPing::count());
    }

    public function test_opted_out_customer_is_never_pinged(): void
    {
        $this->configureWhatsApp();
        Http::fake();
        $this->seedDueBuyer('0722000111', 'Grace', 'Holy Communion Bread');

        $customer = Customer::create([
            'first_name'           => 'Grace',
            'phone'                => '0722000111',
            'wa_marketing_opt_out' => true,
        ]);

        $this->artisan('replenishment:send-pings')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertDatabaseHas('replenishment_pings', [
            'phone'       => '254722000111',
            'customer_id' => $customer->id,
            'status'      => 'skipped_optout',
        ]);
    }

    public function test_dry_run_sends_nothing_and_records_nothing(): void
    {
        $this->configureWhatsApp();
        Http::fake();
        $this->seedDueBuyer('0722000111', 'Grace', 'Holy Communion Bread');

        $this->artisan('replenishment:send-pings --dry-run')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('would send 1')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, ReplenishmentPing::count());
    }

    public function test_per_run_send_cap_is_respected(): void
    {
        $this->configureWhatsApp();
        $this->fakeGraphOk();
        $this->seedDueBuyer('0722000111', 'Grace', 'Holy Communion Bread');
        $this->seedDueBuyer('0733000222', 'Ivan', 'Altar Wine', 6000);

        $this->artisan('replenishment:send-pings --limit=1')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(1, ReplenishmentPing::where('status', 'sent')->count());
        $this->assertSame(1, ReplenishmentPing::count()); // the capped pair leaves no row
    }

    public function test_pings_are_disabled_by_default(): void
    {
        // The daily schedule closure (routes/console.php) checks this config
        // and no-ops when false — sending marketing messages is a conscious
        // opt-in via REPLENISHMENT_PINGS_ENABLED=true.
        $this->assertFalse((bool) config('services.replenishment.pings_enabled'));
    }

    public function test_radar_report_carries_last_ping_and_30d_count(): void
    {
        $this->configureWhatsApp();
        $this->fakeGraphOk();
        $this->seedDueBuyer('0722000111', 'Grace', 'Holy Communion Bread');
        $this->artisan('replenishment:send-pings')->assertSuccessful();

        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/v1/admin/reports/replenishment')->assertOk();

        $this->assertSame(1, $res->json('summary.pings_30d'));
        $this->assertSame('sent', $res->json('due.0.last_ping_status'));
        $this->assertNotNull($res->json('due.0.last_pinged_at'));
    }
}
