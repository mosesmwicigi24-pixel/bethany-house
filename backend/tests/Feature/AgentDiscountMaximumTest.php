<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Neema, the sales agent, under the owner's 5% rule.
 *
 * She used to carry any campaign she was told about up to 70%. Now she may give
 * at most 5% — unless the discount she applies is backed by a promotion the
 * owner set in the hub that is running and covers the item, in which case she
 * may go as far as that promotion and no further.
 *
 * The hub cannot see who typed a campaign into Neema's own dashboard. It can
 * see its own promotions, and since 2026-10-03 only a super_admin can create,
 * raise or switch on one worth more than 5% — so a running promotion is the
 * owner's word, and that is what lifts her limit.
 */
class AgentDiscountMaximumTest extends TestCase
{
    use RefreshDatabase;

    private const SENTENCE = 'The most anyone can give is 5%. Larger discounts are set by the owner.';

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pos.discount_cap_percent' => 5.0]);
        \App\Services\CurrencyPricing::forget();
        DB::table('currencies')->updateOrInsert(
            ['code' => 'KES'],
            ['name' => 'KES', 'symbol' => 'KES', 'exchange_rate' => 1,
             'is_base' => true, 'is_active' => true,
             'created_at' => now(), 'updated_at' => now()],
        );
        \App\Services\CurrencyPricing::forget();
        $this->outlet = Outlet::factory()->create(['country_code' => 'KE']);
    }

    private function neema(): User
    {
        $user = User::factory()->create();
        foreach (['pos.access', 'pos.discount', 'pos.discount_campaign'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        $user->outlets()->sync([$this->outlet->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    /** A 20,000 stole with no sale price of its own. */
    private function stole(float $regular = 20000): Product
    {
        $product = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        ProductPrice::create([
            'product_id' => $product->id, 'product_variant_id' => null,
            'currency_code' => 'KES', 'regular_price' => $regular, 'sale_price' => null,
        ]);
        InventoryItem::create([
            'product_id' => $product->id, 'product_variant_id' => null,
            'outlet_id' => $this->outlet->id, 'quantity_on_hand' => 20,
            'quantity_reserved' => 0, 'reorder_point' => 0,
        ]);

        return $product;
    }

    private function promotion(array $attrs = []): Promotion
    {
        return Promotion::create(array_merge([
            'name' => 'Blessed Friday', 'discount_type' => 'percentage', 'discount_value' => 10,
            'is_active' => true, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(3),
            'priority' => 1,
        ], $attrs));
    }

    private function push(Product $product, string $type, float $value, array $extra = [])
    {
        return $this->postJson('/api/v1/admin/pos/pending-order', array_merge([
            'outlet_id'         => $this->outlet->id,
            'channel'           => 'whatsapp',
            'client_request_id' => 'req-' . bin2hex(random_bytes(6)),
            'items'             => [[
                'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20000,
                'discount_type' => $type, 'discount_value' => $value,
            ]],
        ], $extra));
    }

    private function assertRefused($response, string $field = 'items.0.discount_value'): void
    {
        // Field names carry dots (items.0.discount_value): read as keys, not a path.
        $response->assertStatus(422);
        $this->assertSame([self::SENTENCE], $response->json('errors')[$field] ?? null, $response->getContent());
    }

    // ── No promotion: 5% ─────────────────────────────────────────────────────

    public function test_without_a_promotion_neema_is_held_to_5_percent(): void
    {
        $this->neema();
        $stole = $this->stole();

        $this->assertRefused($this->push($stole, 'percent', 6));
        $this->assertSame(0, Order::count());

        $res = $this->push($stole, 'percent', 5)->assertStatus(201);
        $this->assertSame(19000.0, (float) Order::find($res->json('order_id'))->total_amount);
    }

    public function test_the_old_70_percent_campaign_ceiling_is_gone(): void
    {
        $this->neema();

        $this->assertRefused($this->push($this->stole(), 'percent', 10));
    }

    // ── A running promotion the owner set: up to its value ───────────────────

    public function test_a_running_promotion_lets_her_go_to_its_value_and_no_further(): void
    {
        $this->neema();
        $stole = $this->stole();
        $this->promotion(['discount_value' => 10]);

        $this->push($stole, 'percent', 10)->assertStatus(201);
        $this->assertRefused($this->push($stole, 'percent', 11));
    }

    public function test_a_fixed_amount_promotion_is_measured_in_money(): void
    {
        $this->neema();
        $stole = $this->stole();
        $this->promotion(['discount_type' => 'fixed', 'discount_value' => 1500]);   // 7.5% of 20,000

        $this->push($stole, 'flat', 1500)->assertStatus(201);
        $this->assertRefused($this->push($stole, 'flat', 1501));
    }

    public function test_a_promotion_on_other_items_lifts_nothing(): void
    {
        $this->neema();
        $stole = $this->stole();
        $other = $this->stole();
        $this->promotion(['discount_value' => 10, 'conditions' => ['product_ids' => [$other->id]]]);

        $this->assertRefused($this->push($stole, 'percent', 6));
    }

    public function test_a_promotion_that_has_ended_or_is_switched_off_lifts_nothing(): void
    {
        $this->neema();
        $stole = $this->stole();
        $this->promotion(['discount_value' => 10, 'ends_at' => now()->subHour()]);
        $this->promotion(['discount_value' => 10, 'is_active' => false]);

        $this->assertRefused($this->push($stole, 'percent', 6));
    }

    public function test_the_cart_discount_stays_at_5_percent_even_with_a_promotion(): void
    {
        $this->neema();
        $stole = $this->stole();
        $this->promotion(['discount_value' => 10]);

        $this->assertRefused(
            $this->push($stole, 'none', 0, ['cart_discount_type' => 'percent', 'cart_discount_value' => 6]),
            'cart_discount_value',
        );
    }

    /** The lift is the agent's, by capability — not anyone's who says "whatsapp". */
    public function test_a_clerk_posting_a_chat_channel_gets_no_promotion_lift(): void
    {
        $clerk = User::factory()->create();
        foreach (['pos.access', 'pos.discount', 'pos.discount_override'] as $p) {
            $clerk->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        $clerk->outlets()->sync([$this->outlet->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($clerk);

        $stole = $this->stole();
        $this->promotion(['discount_value' => 10]);

        $this->assertRefused($this->push($stole, 'percent', 6));
    }
}
