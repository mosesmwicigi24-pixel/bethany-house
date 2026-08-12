<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\User;
use App\Services\QuotationService;
use App\Services\TaxCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Characterization of the persisted subtotal / tax_amount / total_amount for
 * every writer that sets them.
 *
 * These were written against the inline copies of the formula that preceded
 * App\Services\OrderTotals, and every figure below was produced by that code.
 * They are here to prove the extraction moved no money — if one of them fails,
 * the refactor changed a number, which it must never do.
 *
 * The scenario is deliberately awkward: a percentage line discount on an odd
 * base (1.5% of 2997.00 = 44.955) and a percentage cart discount on the result.
 * Both carry a third decimal place into a two-decimal column, which is where a
 * "harmless" rounding change shows up as a cent.
 *
 * Writers are split into two groups:
 *
 *   ADOPTERS  — now delegate to OrderTotals. Their figures are the formula's.
 *   ABSTAINERS — deliberately left inline (see the note at the foot of
 *                OrderTotals). Their figures are pinned here precisely BECAUSE
 *                they disagree, so the divergence is visible and cannot widen
 *                unnoticed.
 *
 * @see \App\Services\OrderTotals
 */
class OrderTotalsCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;
    private Product $productA;
    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outlet   = Outlet::factory()->create();
        $this->productA = Product::factory()->create();
        $this->productB = Product::factory()->create();

        foreach ([$this->productA, $this->productB] as $product) {
            InventoryItem::create([
                'product_id'         => $product->id,
                'product_variant_id' => null,
                'outlet_id'          => $this->outlet->id,
                'quantity_on_hand'   => 100,
                'quantity_reserved'  => 0,
                'reorder_point'      => 0,
            ]);
        }

        $this->taxRate(16.0);
    }

    // ── Fixture helpers ─────────────────────────────────────────────────────

    private function taxRate(float $percent): void
    {
        DB::table('tax_rates')->insert([
            'name'       => 'VAT',
            'code'       => 'VAT',
            'rate'       => $percent,
            'is_active'  => true,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        TaxCalculationService::invalidateGlobalCache();
    }

    private function taxInclusivePricing(bool $inclusive): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'tax_inclusive'],
            ['value' => $inclusive ? '1' : '0', 'updated_at' => now(), 'created_at' => now()],
        );
        TaxCalculationService::invalidateGlobalCache();
    }

    private function actor(array $permissions): User
    {
        $user = User::factory()->create();
        // The admin order routes sit inside an orders.view group, so every
        // per-route permission below needs it alongside.
        foreach ([...$permissions, 'orders.view'] as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    /** The awkward cart, as the POS endpoints take it. */
    private function posCart(): array
    {
        return [
            'outlet_id' => $this->outlet->id,
            'items'     => [
                [
                    'product_id'     => $this->productA->id,
                    'quantity'       => 3,
                    'unit_price'     => 999.00,
                    'discount_type'  => 'percent',
                    'discount_value' => 1.5,
                ],
                [
                    'product_id' => $this->productB->id,
                    'quantity'   => 2,
                    'unit_price' => 250.00,
                ],
            ],
            'cart_discount_type'  => 'percent',
            'cart_discount_value' => 2.5,
            'shipping_amount'     => 150,
        ];
    }

    private function assertOrderMoney(Order $order, float $subtotal, float $tax, float $total): void
    {
        $this->assertSame($subtotal, (float) $order->subtotal, 'subtotal');
        $this->assertSame($tax, (float) $order->tax_amount, 'tax_amount');
        $this->assertSame($total, (float) $order->total_amount, 'total_amount');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ADOPTERS
    // ═══════════════════════════════════════════════════════════════════════

    // ── PosController::createPendingOrder ───────────────────────────────────

    /**
     * subtotal  = (2997 − 44.955) + 500          = 3452.045 → stored 3452.05
     * tax       = 2997×16% + 500×16%             = 559.52   (on the UNdiscounted base)
     * total     = 3452.045 − 86.301125 + 559.52 + 150 = 4075.263875 → 4075.26
     *
     * The stored subtotal rounds up while the total is derived from the
     * unrounded figure — one cent apart, and the reason this cart was chosen.
     */
    public function test_create_pending_order_totals(): void
    {
        $this->taxInclusivePricing(false);
        $this->actor(['pos.access']);

        $id = $this->postJson('/api/v1/admin/pos/pending-order', $this->posCart())
            ->assertSuccessful()
            ->json('order.id');

        $this->assertOrderMoney(Order::findOrFail($id), 3452.05, 559.52, 4075.26);
    }

    public function test_create_pending_order_totals_under_tax_inclusive_pricing(): void
    {
        $this->taxInclusivePricing(true);
        $this->actor(['pos.access']);

        $id = $this->postJson('/api/v1/admin/pos/pending-order', $this->posCart())
            ->assertSuccessful()
            ->json('order.id');

        // Tax is extracted, not added: 2997 × 16/116 = 413.38, 500 × 16/116 = 68.97.
        // The total therefore excludes it — 3452.045 − 86.301125 + 150 = 3515.743875.
        $this->assertOrderMoney(Order::findOrFail($id), 3452.05, 482.35, 3515.74);
    }

    // ── PosController::updatePendingOrder ───────────────────────────────────

    public function test_update_pending_order_totals(): void
    {
        $this->taxInclusivePricing(false);
        $this->actor(['pos.access']);

        $id = $this->postJson('/api/v1/admin/pos/pending-order', [
            'outlet_id' => $this->outlet->id,
            'items'     => [[
                'product_id' => $this->productB->id,
                'quantity'   => 1,
                'unit_price' => 100.00,
            ]],
        ])->assertSuccessful()->json('order.id');

        $this->patchJson("/api/v1/admin/pos/pending-order/{$id}", $this->posCart())
            ->assertSuccessful();

        // Re-priced to the same awkward cart — must land on the same figures as
        // creating it that way in the first place.
        $this->assertOrderMoney(Order::findOrFail($id), 3452.05, 559.52, 4075.26);
    }

    // ── PosController::createSale ───────────────────────────────────────────

    /**
     * The paid-at-the-till path. Same arithmetic as the pending-order writers,
     * with one wrinkle: createSale derives the figure the tender must cover
     * BEFORE shipping is known, then persists a total that includes it. Both
     * are pinned — 4075.26 on the row, 3925.26 + 150 demanded at the till.
     */
    public function test_create_sale_totals(): void
    {
        $this->taxInclusivePricing(false);
        $user = $this->actor(['pos.access']);
        $this->openRegister($user);

        $id = $this->postJson('/api/v1/admin/pos/sales', $this->posCart() + [
            'payment_method' => 'cash',
            'payments'       => [['method' => 'cash', 'amount' => 4075.26, 'cash_received' => 4100]],
        ])->assertSuccessful()->json('order.id');

        $this->assertOrderMoney(Order::findOrFail($id), 3452.05, 559.52, 4075.26);
    }

    /** A tender short of goods-plus-shipping is refused. */
    public function test_create_sale_requires_the_tender_to_cover_shipping_too(): void
    {
        $this->taxInclusivePricing(false);
        $user = $this->actor(['pos.access']);
        $this->openRegister($user);

        $this->postJson('/api/v1/admin/pos/sales', $this->posCart() + [
            'payment_method' => 'cash',
            'payments'       => [['method' => 'cash', 'amount' => 3925.26, 'cash_received' => 4000]],
        ])->assertStatus(422);
    }

    private function openRegister(User $user): void
    {
        DB::table('cash_registers')->insert([
            'register_number'   => "REG-{$this->outlet->id}-{$user->id}",
            'outlet_id'         => $this->outlet->id,
            'register_name'     => 'Test Register',
            'status'            => 'open',
            'currency_code'     => 'KES',
            'opening_balance'   => 5000,
            'expected_cash'     => 5000,
            'total_sales'       => 0,
            'total_cash_sales'  => 0,
            'total_card_sales'  => 0,
            'total_mpesa_sales' => 0,
            'total_refunds'     => 0,
            'transaction_count' => 0,
            'opened_by'         => $user->id,
            'opened_at'         => now(),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    // ── OrderController::adjustItemPrice ────────────────────────────────────

    /**
     * Raising one line's unit price re-derives the whole order. The cart
     * discount and shipping already on the row ride along untouched.
     */
    public function test_adjust_item_price_totals(): void
    {
        $this->taxInclusivePricing(false);
        $this->actor(['orders.edit']);

        $order = $this->orderWithLines(discount: 100, shipping: 150);
        $item  = $order->items->first();

        $this->patchJson("/api/v1/admin/orders/{$order->id}/items/{$item->id}/price", [
            'unit_price' => 1200,
        ])->assertOk();

        // Line A becomes 1200 × 3 = 3600 less its stored 44.96 discount = 3555.04,
        // taxed on the full 3600 → 576.00. Line B stays 500.00 / 80.00.
        // subtotal 4055.04, total 4055.04 − 100 + 656 + 150.
        $this->assertOrderMoney($order->fresh(), 4055.04, 656.0, 4761.04);
    }

    // ── OrderController::updateCurrency ─────────────────────────────────────

    /**
     * Re-pricing into another currency rewrites every line, then re-derives the
     * order. The cart discount and shipping are NOT converted — pinning that
     * here because it is surprising, and because the refactor must not quietly
     * "fix" it.
     */
    public function test_update_currency_totals(): void
    {
        $this->taxInclusivePricing(false);
        $this->actor(['orders.edit']);

        DB::table('currencies')->insert([
            ['code' => 'KES', 'name' => 'Shilling', 'symbol' => 'KSh', 'exchange_rate' => 1,
             'is_active' => true, 'is_base' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'USD', 'name' => 'Dollar', 'symbol' => '$', 'exchange_rate' => 0.01,
             'is_active' => true, 'is_base' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('countries')->updateOrInsert(
            ['code' => 'US'],
            ['name' => 'United States', 'default_currency_code' => 'USD',
             'created_at' => now(), 'updated_at' => now()],
        );

        // No line discounts here: converting an order whose stored line discount
        // is in the OLD currency drives the line negative, which is a separate
        // bug and not what this test is pinning.
        $order = $this->orderWithLines(discount: 10, shipping: 20, lineDiscount: 0);

        foreach ($order->items as $item) {
            DB::table('product_prices')->insert([
                'product_id'         => $item->product_id,
                'product_variant_id' => null,
                'currency_code'      => 'KES',
                'regular_price'      => $item->unit_price,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        }

        $this->postJson("/api/v1/admin/orders/{$order->id}/update-currency", [
            'country_code' => 'US',
        ])->assertOk();

        // 999 → 9.99 and 250 → 2.50 at 0.01. Lines: 29.97 and 5.00.
        // Line tax is rounded to the column on write: 29.97×16% = 4.7952 → 4.80.
        $fresh = $order->fresh();
        $this->assertSame(34.97, (float) $fresh->subtotal);
        $this->assertSame(5.60, (float) $fresh->tax_amount);
        // 34.97 − 10 (cart discount, NOT converted) + 5.60 + 20 (shipping, NOT converted)
        $this->assertSame(50.57, (float) $fresh->total_amount);
    }

    /** An order carrying the awkward cart, already persisted. */
    private function orderWithLines(float $discount = 0, float $shipping = 0, float $lineDiscount = 44.96): Order
    {
        $order = Order::factory()->create([
            'order_type'         => 'online',
            'status'             => 'pending',
            'payment_status'     => 'pending',
            'currency_code'      => 'KES',
            'subtotal'           => 3452.05,
            'discount_amount'    => $discount,
            'tax_amount'         => 559.52,
            'prices_include_tax' => false,
            'shipping_amount'    => $shipping,
            'total_amount'       => 0,
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->productA->id,
            'product_name' => 'A', 'sku' => 'A', 'quantity' => 3,
            'unit_price' => 999.00, 'discount_amount' => $lineDiscount,
            'tax_amount' => 479.52, 'total_price' => 3431.56,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->productB->id,
            'product_name' => 'B', 'sku' => 'B', 'quantity' => 2,
            'unit_price' => 250.00, 'discount_amount' => 0,
            'tax_amount' => 80.00, 'total_price' => 580.00,
        ]);

        return $order->load('items');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ABSTAINERS — pinned because they DISAGREE
    // ═══════════════════════════════════════════════════════════════════════

    // ── OrderController::setShippingFee ─────────────────────────────────────

    /**
     * Shipping is a delta, not a recompute. It moves total_amount by the
     * difference and leaves subtotal and tax_amount exactly where they were —
     * which is what lets freight be attached to an already-paid receipt without
     * restating the goods.
     *
     * The order below is deliberately inconsistent: its stored total does not
     * match its lines. setShippingFee must preserve that, not repair it.
     */
    public function test_set_shipping_fee_moves_the_total_without_recomputing(): void
    {
        $this->actor(['orders.set_shipping_fee']);

        $order = Order::factory()->create([
            'status'          => 'confirmed',
            'payment_status'  => 'pending',
            'subtotal'        => 1000,
            'tax_amount'      => 160,
            'shipping_amount' => 0,
            'total_amount'    => 9999,   // deliberately not derivable from the above
        ]);

        $this->patchJson("/api/v1/admin/orders/{$order->id}/shipping-fee", [
            'amount' => 250,
        ])->assertOk();

        $fresh = $order->fresh();
        $this->assertSame(1000.0, (float) $fresh->subtotal);     // untouched
        $this->assertSame(160.0, (float) $fresh->tax_amount);    // untouched
        $this->assertSame(10249.0, (float) $fresh->total_amount); // 9999 + 250, not recomputed
    }

    // ── OrderController::checkout ───────────────────────────────────────────

    /**
     * checkout sources its figures from TaxCalculationService::calculateOrder,
     * whose 'subtotal' is the sum of NET line subtotals. Under tax-inclusive
     * pricing that is (price × qty) − tax, so the stored subtotal is BELOW the
     * goods value — 1724.14 rather than 2000.00 on 2 × 1000.00 at 16%.
     *
     * OrderTotals would store 2000.00. That is why checkout was not pointed at
     * it: doing so would restate the subtotal on every new online order.
     */
    public function test_checkout_persists_a_net_subtotal_under_tax_inclusive_pricing(): void
    {
        $this->taxInclusivePricing(true);

        $calc = TaxCalculationService::calculateOrder([[
            'product_id'      => $this->productA->id,
            'unit_price'      => 1000.0,
            'quantity'        => 2,
            'discount_amount' => 0.0,
        ]]);

        $this->assertSame(1724.14, $calc['subtotal']);    // NOT 2000.00
        $this->assertSame(275.86, $calc['total_tax']);
        $this->assertSame(2000.0, $calc['total_gross']);  // the total does agree
    }

    /**
     * The second disagreement: a line discount is applied to the unit price
     * BEFORE tax, so a discounted line is taxed on the discounted amount.
     * OrderTotals takes each line's tax as already computed on the full base.
     */
    public function test_tax_calculation_service_taxes_the_discounted_amount(): void
    {
        $this->taxInclusivePricing(false);

        $calc = TaxCalculationService::calculateOrder([[
            'product_id'      => $this->productA->id,
            'unit_price'      => 1000.0,
            'quantity'        => 2,
            'discount_amount' => 500.0,
        ]]);

        $this->assertSame(1500.0, $calc['subtotal']);
        $this->assertSame(240.0, $calc['total_tax']);   // 16% of 1500, not of 2000 (=320)
    }

    // ── QuotationService::convertToInvoice ──────────────────────────────────

    /**
     * Conversion computes nothing — it copies the quotation's stored figures
     * onto the order verbatim. Those figures came from QuotationController,
     * which also uses TaxCalculationService, so the divergence above rides
     * along into the order.
     *
     * Pinned with figures that OrderTotals would NOT reproduce, so that if
     * conversion ever starts recomputing, this fails.
     */
    public function test_convert_to_invoice_copies_the_quotation_totals_verbatim(): void
    {
        $quotation = Quotation::create([
            'quote_number'        => 'QUO-CHAR-1',
            'status'              => Quotation::SENT,
            'currency_code'       => 'KES',
            'customer_first_name' => 'Jane',
            'subtotal'            => 1724.14,   // net, as TaxCalculationService reports it
            'discount_amount'     => 0,
            'tax_amount'          => 275.86,
            'total_amount'        => 2000.00,
            'issued_at'           => now(),
        ]);

        DB::table('quotation_items')->insert([
            'quotation_id'    => $quotation->id,
            'product_id'      => $this->productA->id,
            'product_name'    => 'A',
            'sku'             => 'A',
            'quantity'        => 2,
            'unit_price'      => 1000.00,
            'discount_amount' => 0,
            'tax_amount'      => 275.86,
            'total_price'     => 2000.00,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $result = QuotationService::convertToInvoice($quotation->fresh('items'));
        $order  = $result['order'];

        // Copied, not derived: OrderTotals over these lines would say subtotal
        // 2000.00, and a tax-exclusive total of 2275.86.
        $this->assertOrderMoney($order, 1724.14, 275.86, 2000.00);
    }
}
