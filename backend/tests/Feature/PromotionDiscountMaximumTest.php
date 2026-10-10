<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\Marketing\Discounts;
use App\Http\Livewire\Admin\Marketing\Promotions;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Promotion;
use App\Models\User;
use App\Services\PromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Promotions, seasonal campaigns and coupons under the owner's 5% rule.
 *
 * A promotion is a discount that runs by itself, on the storefront and in
 * Neema's orders, for everyone, until it ends. So the rule bites where it is
 * SET: creating, raising or switching on one worth more than 5% is the
 * super_admin's alone. Once the owner has set it, it applies at the value he set.
 *
 * Worth, for a fixed amount, is measured against the cheapest item it can
 * touch — a fixed sum is the largest share of the cheapest price, and the rule
 * is about the share.
 */
class PromotionDiscountMaximumTest extends TestCase
{
    use RefreshDatabase;

    private const SENTENCE = 'Promotions, coupons and sale prices above 5% are set by a super admin.';

    protected function setUp(): void
    {
        parent::setUp();
        config(['pos.discount_cap_percent' => 5.0]);
    }

    private function admin(): User
    {
        Role::findOrCreate('admin', 'sanctum');
        $user = User::factory()->create();
        $user->assignRole('admin');
        foreach (['marketing.view', 'marketing.manage'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function superAdmin(): User
    {
        Role::findOrCreate('super_admin', 'sanctum');
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function priced(float $regular): Product
    {
        $product = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        ProductPrice::create([
            'product_id' => $product->id, 'product_variant_id' => null,
            'currency_code' => 'KES', 'regular_price' => $regular,
        ]);

        return $product;
    }

    private function create(array $attrs)
    {
        return $this->postJson('/api/v1/admin/marketing/promotions', array_merge([
            'name' => 'Advent', 'discount_type' => 'percentage', 'discount_value' => 5,
            'is_active' => true,
        ], $attrs));
    }

    private function assertRefused($response, string $field = 'discount_value'): void
    {
        $response->assertStatus(422)->assertJsonPath('message', self::SENTENCE);
        $this->assertSame([self::SENTENCE], $response->json('errors')[$field] ?? null, $response->getContent());
    }

    // ── Percentage promotions ─────────────────────────────────────────────────

    public function test_an_admin_cannot_set_a_promotion_above_5_percent(): void
    {
        Sanctum::actingAs($this->admin());

        $this->assertRefused($this->create(['discount_value' => 6]));
        $this->assertSame(0, Promotion::count());
    }

    public function test_an_admin_may_set_one_at_5_percent(): void
    {
        Sanctum::actingAs($this->admin());

        $this->create(['discount_value' => 5])->assertCreated();
    }

    public function test_a_super_admin_may_set_30_percent_and_it_is_recorded_as_his(): void
    {
        $owner = $this->superAdmin();
        Sanctum::actingAs($owner);

        $id = $this->create(['discount_value' => 30])->assertCreated()->json('data.id');
        $this->assertSame($owner->id, Promotion::find($id)->created_by);
    }

    public function test_a_switched_off_draft_above_5_percent_is_still_the_owners_to_create(): void
    {
        Sanctum::actingAs($this->admin());

        $this->assertRefused($this->create(['discount_value' => 20, 'is_active' => false]));
    }

    // ── Fixed-amount promotions ──────────────────────────────────────────────

    public function test_a_fixed_amount_is_measured_against_the_cheapest_item_it_reaches(): void
    {
        Sanctum::actingAs($this->admin());
        $this->priced(1000);    // 5% of this is 50
        $this->priced(20000);

        $this->assertRefused($this->create(['discount_type' => 'fixed', 'discount_value' => 51]));
        $this->create(['discount_type' => 'fixed', 'discount_value' => 50])->assertCreated();
    }

    public function test_a_scoped_fixed_amount_is_measured_against_its_own_items(): void
    {
        Sanctum::actingAs($this->admin());
        $this->priced(1000);
        $gown = $this->priced(20000);    // 5% of this is 1,000

        $scope = ['conditions' => ['product_ids' => [$gown->id]], 'discount_type' => 'fixed'];
        $this->assertRefused($this->create($scope + ['discount_value' => 1001]));
        $this->create($scope + ['discount_value' => 1000])->assertCreated();
    }

    public function test_a_fixed_amount_with_nothing_priced_to_measure_against_is_the_owners(): void
    {
        Sanctum::actingAs($this->admin());

        $this->assertRefused($this->create(['discount_type' => 'fixed', 'discount_value' => 10]));
    }

    // ── Editing one the owner set ────────────────────────────────────────────

    private function ownersPromotion(array $attrs = []): Promotion
    {
        return Promotion::create(array_merge([
            'name' => 'Lent', 'discount_type' => 'percentage', 'discount_value' => 20,
            'is_active' => true, 'priority' => 1,
        ], $attrs));
    }

    private function update(Promotion $p, array $attrs)
    {
        return $this->putJson("/api/v1/admin/marketing/promotions/{$p->id}", array_merge([
            'name' => $p->name, 'discount_type' => $p->discount_type,
            'discount_value' => (float) $p->discount_value, 'is_active' => $p->is_active,
        ], $attrs));
    }

    public function test_an_admin_cannot_extend_or_rename_the_owners_20_percent_promotion(): void
    {
        $p = $this->ownersPromotion();
        Sanctum::actingAs($this->admin());

        $this->assertRefused($this->update($p, ['ends_at' => now()->addYear()->toDateString()]));
        $this->assertRefused($this->update($p, ['name' => 'Lent (extended)']));
        $this->assertNull($p->fresh()->ends_at);
        $this->assertSame('Lent', $p->fresh()->name);
    }

    public function test_an_admin_can_switch_the_owners_promotion_off_but_not_back_on(): void
    {
        $p = $this->ownersPromotion();
        Sanctum::actingAs($this->admin());

        $this->update($p, ['is_active' => false])->assertOk();
        $this->assertFalse($p->fresh()->is_active);

        $this->assertRefused($this->update($p->fresh(), ['is_active' => true]));
        $this->assertFalse($p->fresh()->is_active);
    }

    public function test_an_admin_can_bring_a_promotion_down_to_5_percent(): void
    {
        $p = $this->ownersPromotion();
        Sanctum::actingAs($this->admin());

        $this->update($p, ['discount_value' => 5])->assertOk();
        $this->assertSame('5.00', $p->fresh()->discount_value);
    }

    public function test_a_super_admin_can_edit_his_own_promotion(): void
    {
        $p = $this->ownersPromotion();
        Sanctum::actingAs($this->superAdmin());

        $this->update($p, ['discount_value' => 25])->assertOk();
        $this->assertSame('25.00', $p->fresh()->discount_value);
    }

    // ── The storefront honours what the owner set ────────────────────────────

    public function test_a_promotion_already_on_the_books_is_untouched_and_still_applies_at_its_value(): void
    {
        $p      = $this->ownersPromotion(['discount_value' => 20]);
        $coupon = Coupon::create(['code' => 'OLD30', 'type' => 'percentage', 'value' => 30, 'is_active' => true]);
        $before = [$p->fresh()->toArray(), $coupon->fresh()->toArray()];

        // Ordinary marketing work around them.
        Sanctum::actingAs($this->admin());
        $this->create(['discount_value' => 5])->assertCreated();

        $this->assertEquals($before, [$p->fresh()->toArray(), $coupon->fresh()->toArray()]);

        $product = $this->priced(1000);
        $service = new PromotionService();
        $this->assertSame($p->id, $service->promotionFor($product)?->id);
        $this->assertSame(800.0, $service->discountedUnit(1000, $p->fresh()));
    }

    // ── The older Livewire admin, which is still routed ──────────────────────

    public function test_livewire_promotions_refuses_an_admin_above_5_percent(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Promotions::class)
            ->set('name', 'Pentecost')->set('discountType', 'percentage')->set('discountValue', '10')
            ->call('save')
            ->assertHasErrors(['discountValue']);
        $this->assertSame(0, Promotion::count());

        Livewire::test(Promotions::class)
            ->set('name', 'Pentecost')->set('discountType', 'percentage')->set('discountValue', '5')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(1, Promotion::count());
    }

    public function test_livewire_promotions_will_not_let_an_admin_switch_on_the_owners_20_percent(): void
    {
        $p = $this->ownersPromotion(['is_active' => false]);
        $this->actingAs($this->admin());

        Livewire::test(Promotions::class)->call('toggleActive', $p->id);
        $this->assertFalse($p->fresh()->is_active);

        $this->actingAs($this->superAdmin());
        Livewire::test(Promotions::class)->call('toggleActive', $p->id);
        $this->assertTrue($p->fresh()->is_active);
    }

    public function test_livewire_coupons_refuse_an_admin_above_5_percent_and_allow_the_owner(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Discounts::class)
            ->set('code', 'BIG10')->set('type', 'percentage')->set('value', '10')
            ->call('save')
            ->assertHasErrors(['value']);
        $this->assertSame(0, Coupon::count());

        $this->priced(1000);
        Livewire::test(Discounts::class)
            ->set('code', 'FLAT60')->set('type', 'fixed')->set('value', '60')
            ->call('save')
            ->assertHasErrors(['value']);
        Livewire::test(Discounts::class)
            ->set('code', 'FLAT60')->set('type', 'fixed')->set('value', '60')->set('minimumOrderAmount', '1200')
            ->call('save')
            ->assertHasNoErrors();   // 60 is 5% of the smallest order it can apply to

        $this->actingAs($this->superAdmin());
        Livewire::test(Discounts::class)
            ->set('code', 'OWNER30')->set('type', 'percentage')->set('value', '30')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsCanonicalizing(['FLAT60', 'OWNER30'], Coupon::pluck('code')->all());
    }
}
