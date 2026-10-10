<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Quotation;
use App\Models\User;
use App\Support\DiscountRule;
use App\Support\RoleDiscountCaps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\StepsUp;
use Tests\TestCase;

/**
 * Per-role discount limits (owner, 2026-10-10): "clerks at 10% and Admins up
 * to 15%. Make it not automatically but when needed a clerk can give up to.
 * Create a place where super admin can set this too."
 *
 * The migration seeds pos_clerk 10 and admin 15. A role without a row stays at
 * the global 5%; a person with several roles gets the highest of their rows;
 * Neema's service account stays at 5% although it holds pos_clerk; the super
 * admin has no limit. Promotions stay on the global 5%. The limits are set at
 * /admin/settings/discount-limits by the super admin alone, with step-up, and
 * every change is on the audit trail.
 */
class RoleDiscountLimitsTest extends TestCase
{
    use RefreshDatabase;
    use StepsUp;

    private const NEEMA = 'neema-bot@bethanyhouse.co.ke';

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'pos.discount_cap_percent'  => 5.0,
            'security.service_accounts' => [self::NEEMA],
        ]);
        $this->outlet = Outlet::factory()->create();
    }

    private static function sentence(string $percent): string
    {
        return "Your discount limit is {$percent}% — a super admin can give more.";
    }

    /** @param list<string> $roles */
    private function staff(array $roles, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        foreach ($roles as $role) {
            Role::findOrCreate($role, 'sanctum');
            $user->assignRole($role);
        }
        foreach ([
            'pos.access', 'pos.discount', 'quotations.view', 'quotations.create',
            'orders.view', 'orders.edit', 'orders.edit_items', 'products.view',
            'settings.view', 'settings.edit',
        ] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        $user->outlets()->attach($this->outlet->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /** @param list<string> $roles */
    private function actAs(array $roles, array $attrs = []): User
    {
        $user = $this->staff($roles, $attrs);
        Sanctum::actingAs($user);
        CashRegister::create([
            'outlet_id'       => $this->outlet->id,
            'register_name'   => 'Till ' . $user->id,
            'status'          => 'open',
            'currency_code'   => 'KES',
            'opening_balance' => 1000,
            'expected_cash'   => 1000,
            'opened_by'       => $user->id,
            'opened_at'       => now(),
        ]);

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

    private function product(float $price = 1000.0): Product
    {
        $product = Product::factory()->create();
        DB::table('product_translations')->insert([
            'product_id' => $product->id, 'language_code' => 'en', 'name' => 'Chasuble',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_prices')->insert([
            'product_id' => $product->id, 'product_variant_id' => null,
            'currency_code' => 'KES', 'regular_price' => $price, 'cost_price' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        InventoryItem::create([
            'product_id' => $product->id, 'product_variant_id' => null,
            'outlet_id' => $this->outlet->id, 'quantity_on_hand' => 100,
            'quantity_reserved' => 0, 'reorder_point' => 0,
        ]);

        return $product;
    }

    private function line(Product $product, string $type = 'none', float $value = 0): array
    {
        return [
            'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1000,
            'discount_type' => $type, 'discount_value' => $value,
        ];
    }

    private function sale(array $items, array $extra = [])
    {
        return $this->postJson('/api/v1/admin/pos/sales', array_merge([
            'outlet_id' => $this->outlet->id, 'payment_method' => 'cash',
            'cash_received' => 5000, 'items' => $items,
        ], $extra));
    }

    private function pendingOrder(array $items, array $extra = [])
    {
        return $this->postJson('/api/v1/admin/pos/pending-order', array_merge([
            'outlet_id' => $this->outlet->id, 'items' => $items,
        ], $extra));
    }

    private function quotation(float $discount)
    {
        // One 2 × 1,000 = 2,000 line.
        return $this->postJson('/api/v1/admin/quotations', [
            'customer_first_name' => 'Jane',
            'items' => [['product_name' => 'Cope', 'quantity' => 2, 'unit_price' => 1000, 'discount_amount' => $discount]],
        ]);
    }

    private function assertRefused($response, string $field, string $sentence): void
    {
        $response->assertStatus(422)->assertJsonPath('message', $sentence);
        $this->assertSame([$sentence], $response->json('errors')[$field] ?? null, "refused on {$field}: " . $response->getContent());
    }

    private function limits(array $limits)
    {
        return $this->putJson('/api/v1/admin/settings/discount-limits', ['limits' => $limits]);
    }

    // ── The seeded limits at the till ────────────────────────────────────────

    public function test_the_migration_seeds_clerk_10_and_admin_15(): void
    {
        $this->assertSame(['admin' => 15.0, 'pos_clerk' => 10.0], collect(RoleDiscountCaps::fresh())->sortKeys()->all());
    }

    public function test_a_clerk_may_give_10_percent_on_a_line_and_not_10_01(): void
    {
        $this->actAs(['pos_clerk']);
        $p = $this->product();

        $this->assertRefused($this->sale([$this->line($p, 'percent', 10.01)]), 'items.0.discount_value', self::sentence('10'));
        $this->assertRefused($this->sale([$this->line($p, 'flat', 100.10)]), 'items.0.discount_value', self::sentence('10'));
        $this->assertSame(0, Order::count());

        $this->sale([$this->line($p, 'percent', 10)])->assertSuccessful();
        $this->assertSame(900.0, (float) Order::first()->total_amount);
    }

    public function test_a_clerks_10_percent_is_of_the_whole_order(): void
    {
        $this->actAs(['pos_clerk']);
        $p = $this->product();

        // 60 off the line, then 5% of the 940 left (47): 107 of a 1,000 order.
        $this->assertRefused(
            $this->pendingOrder([$this->line($p, 'percent', 6)], ['cart_discount_type' => 'percent', 'cart_discount_value' => 5]),
            'cart_discount_value',
            self::sentence('10'),
        );
        // 50 + 47.50 = 97.50: within 10% of the order.
        $this->pendingOrder([$this->line($p, 'percent', 5)], ['cart_discount_type' => 'percent', 'cart_discount_value' => 5])
            ->assertSuccessful();
    }

    public function test_an_admin_may_give_15_percent_and_not_more(): void
    {
        $this->actAs(['admin']);
        $p = $this->product();

        $this->assertRefused($this->pendingOrder([$this->line($p, 'percent', 15.01)]), 'items.0.discount_value', self::sentence('15'));
        $this->pendingOrder([$this->line($p, 'percent', 15)])->assertSuccessful();

        // A quotation: 15% of 2,000 is 300.
        $this->assertRefused($this->quotation(300.01), 'items.0.discount_amount', self::sentence('15'));
        $this->quotation(300)->assertCreated();
    }

    public function test_an_admins_15_percent_is_of_the_whole_order(): void
    {
        $this->actAs(['admin']);
        $p = $this->product();

        // 100 off the line + 6% of the 900 left (54) = 154 of 1,000.
        $this->assertRefused(
            $this->pendingOrder([$this->line($p, 'percent', 10)], ['cart_discount_type' => 'percent', 'cart_discount_value' => 6]),
            'cart_discount_value',
            self::sentence('15'),
        );
    }

    public function test_a_role_without_a_limit_stays_at_the_global_5_percent(): void
    {
        $manager = $this->actAs(['outlet_manager']);
        $p = $this->product();

        $this->assertSame(5.0, DiscountRule::capFor($manager));
        $this->assertRefused($this->pendingOrder([$this->line($p, 'percent', 6)]), 'items.0.discount_value', self::sentence('5'));
        $this->pendingOrder([$this->line($p, 'percent', 5)])->assertSuccessful();
    }

    public function test_someone_with_several_roles_gets_the_highest_limit(): void
    {
        $both = $this->actAs(['pos_clerk', 'admin']);
        $p = $this->product();

        $this->assertSame(15.0, DiscountRule::capFor($both));
        $this->pendingOrder([$this->line($p, 'percent', 15)])->assertSuccessful();

        $clerkAndManager = $this->staff(['pos_clerk', 'outlet_manager']);
        $this->assertSame(10.0, DiscountRule::capFor($clerkAndManager), 'a role without a row adds nothing');
    }

    public function test_neemas_service_account_stays_at_5_percent_although_it_holds_pos_clerk(): void
    {
        $neema = $this->actAs(['pos_clerk'], ['email' => self::NEEMA]);
        $this->assertTrue($neema->isServiceAccount());
        $this->assertSame(5.0, DiscountRule::capFor($neema));

        $p = $this->product();
        $this->assertRefused(
            $this->pendingOrder([$this->line($p, 'percent', 6)]),
            'items.0.discount_value',
            self::sentence('5'),
        );
        $this->pendingOrder([$this->line($p, 'percent', 5)])->assertSuccessful();
    }

    public function test_the_super_admin_has_no_limit(): void
    {
        $owner = $this->superAdmin();
        Sanctum::actingAs($owner);

        $this->assertNull(DiscountRule::capFor($owner));
        $this->assertNull(DiscountRule::refusal($owner, 900, 1000));
        $id = $this->quotation(1200)->assertCreated()->json('quotation.id');
        $this->assertSame(1200.0, (float) Quotation::find($id)->discount_amount);
    }

    public function test_an_unreadable_table_holds_everyone_to_the_global_maximum_not_no_limit(): void
    {
        $clerk = $this->actAs(['pos_clerk']);
        $p = $this->product();
        DB::statement('ALTER TABLE role_discount_caps RENAME TO role_discount_caps_gone');
        RoleDiscountCaps::forget();

        $this->assertSame(5.0, DiscountRule::capFor($clerk));
        $this->assertRefused($this->pendingOrder([$this->line($p, 'percent', 6)]), 'items.0.discount_value', self::sentence('5'));
        // The failed read happened inside the order's transaction and did not poison it.
        $this->pendingOrder([$this->line($p, 'percent', 5)])->assertSuccessful();
    }

    // ── Promotions stay on the global maximum ────────────────────────────────

    public function test_an_admin_still_cannot_set_a_promotion_above_5_percent(): void
    {
        $admin = $this->staff(['admin']);
        foreach (['marketing.view', 'marketing.manage'] as $perm) {
            $admin->givePermissionTo(Permission::findOrCreate($perm, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/marketing/promotions', [
            'name' => 'Advent', 'discount_type' => 'percentage', 'discount_value' => 10, 'is_active' => true,
        ])->assertStatus(422)->assertJsonPath('message', 'Promotions, coupons and sale prices above 5% are set by a super admin.');
        $this->assertSame(0, Promotion::count());
    }

    // ── The console is told ──────────────────────────────────────────────────

    public function test_the_signed_in_user_carries_their_own_limit_and_the_promotion_ceiling(): void
    {
        Sanctum::actingAs($this->staff(['pos_clerk']));
        $this->getJson('/api/v1/admin/auth/me')->assertOk()
            ->assertJsonPath('user.discount_cap_percent', 10)
            ->assertJsonPath('user.promotion_cap_percent', 5);

        Sanctum::actingAs($this->superAdmin());
        $this->getJson('/api/v1/admin/auth/me')->assertOk()
            ->assertJsonPath('user.discount_cap_percent', null)
            ->assertJsonPath('user.promotion_cap_percent', null);
    }

    // ── Setting the limits ───────────────────────────────────────────────────

    public function test_the_super_admin_sees_every_role_with_its_limit_or_the_default(): void
    {
        foreach (['pos_clerk', 'admin', 'outlet_manager', 'customer'] as $r) {
            Role::findOrCreate($r, 'sanctum');
        }
        Sanctum::actingAs($this->superAdmin());

        $res = $this->getJson('/api/v1/admin/settings/discount-limits')->assertOk()
            ->assertJsonPath('default_percent', 5);
        $byRole = collect($res->json('limits'))->keyBy('role');

        $this->assertSame(10, $byRole['pos_clerk']['cap_percent']);
        $this->assertSame(15, $byRole['admin']['cap_percent']);
        $this->assertNull($byRole['outlet_manager']['cap_percent']);
        $this->assertSame(5, $byRole['outlet_manager']['effective_percent']);
        $this->assertFalse($byRole->has('super_admin'));
        $this->assertFalse($byRole->has('customer'));
    }

    public function test_a_change_takes_effect_for_the_next_discount_and_is_audited(): void
    {
        $owner = $this->superAdmin();
        $clerk = $this->actAs(['pos_clerk']);
        $p = $this->product();
        $this->assertSame(10.0, DiscountRule::capFor($clerk));   // warms the cache

        Sanctum::actingAs($owner);
        $this->stepUp($owner);
        $this->limits([['role' => 'pos_clerk', 'cap_percent' => 12.5]])->assertOk()
            ->assertJsonPath('message', 'Discount limits saved.');

        $row = DB::table('role_discount_caps')->where('role', 'pos_clerk')->first();
        $this->assertSame(12.5, (float) $row->cap_percent);
        $this->assertSame($owner->id, (int) $row->updated_by);

        Sanctum::actingAs($clerk);
        $this->assertRefused($this->pendingOrder([$this->line($p, 'percent', 12.51)]), 'items.0.discount_value', self::sentence('12.5'));
        $this->pendingOrder([$this->line($p, 'percent', 12.5)])->assertSuccessful();

        $entry = DB::table('activity_log')->where('event', 'settings_updated')->orderByDesc('id')->first();
        $this->assertNotNull($entry);
        $this->assertSame($owner->id, (int) $entry->causer_id);
        $props = json_decode($entry->properties, true);
        $this->assertSame('discount limits', $props['group']);
        $this->assertEquals(['old' => 10.0, 'new' => 12.5], $props['changes']['pos_clerk']);
        $this->assertStringContainsString('pos_clerk 10% → 12.5%', $entry->description);
    }

    public function test_null_puts_a_role_back_on_the_default_and_a_new_row_can_be_added(): void
    {
        $owner = $this->superAdmin();
        Role::findOrCreate('outlet_manager', 'sanctum');
        Sanctum::actingAs($owner);
        $this->stepUp($owner);

        $this->limits([
            ['role' => 'pos_clerk', 'cap_percent' => null],
            ['role' => 'outlet_manager', 'cap_percent' => 8],
        ])->assertOk();

        $this->assertNull(DB::table('role_discount_caps')->where('role', 'pos_clerk')->first());
        $this->assertSame(5.0, DiscountRule::capFor($this->staff(['pos_clerk'])));
        $this->assertSame(8.0, DiscountRule::capFor($this->staff(['outlet_manager'])));

        $props = json_decode(DB::table('activity_log')->where('event', 'settings_updated')->orderByDesc('id')->value('properties'), true);
        $this->assertEquals(['old' => 10.0, 'new' => null], $props['changes']['pos_clerk']);
        $this->assertEquals(['old' => null, 'new' => 8.0], $props['changes']['outlet_manager']);
    }

    public function test_a_save_that_changes_nothing_writes_no_audit_row(): void
    {
        $owner = $this->superAdmin();
        Sanctum::actingAs($owner);
        $this->stepUp($owner);
        $before = DB::table('activity_log')->where('event', 'settings_updated')->count();

        $this->limits([['role' => 'pos_clerk', 'cap_percent' => 10]])->assertOk()->assertJsonPath('message', 'Nothing changed.');
        $this->assertSame($before, DB::table('activity_log')->where('event', 'settings_updated')->count());
    }

    public function test_invalid_limits_are_refused(): void
    {
        $owner = $this->superAdmin();
        Sanctum::actingAs($owner);
        $this->stepUp($owner);

        $this->limits([['role' => 'pos_clerk', 'cap_percent' => 100.5]])->assertStatus(422)->assertJsonValidationErrors('limits.0.cap_percent');
        $this->limits([['role' => 'pos_clerk', 'cap_percent' => -1]])->assertStatus(422)->assertJsonValidationErrors('limits.0.cap_percent');
        $this->limits([['role' => 'pos_clerk', 'cap_percent' => 10.123]])->assertStatus(422)->assertJsonValidationErrors('limits.0.cap_percent');
        $this->limits([['role' => 'super_admin', 'cap_percent' => 50]])->assertStatus(422)->assertJsonValidationErrors('limits.0.role');
        $this->limits([['role' => 'no_such_role', 'cap_percent' => 50]])->assertStatus(422)->assertJsonValidationErrors('limits.0.role');
        $this->limits([['role' => 'pos_clerk']])->assertStatus(422)->assertJsonValidationErrors('limits.0.cap_percent');

        $this->assertSame(10.0, RoleDiscountCaps::fresh()['pos_clerk']);
    }

    public function test_only_the_super_admin_may_read_or_set_the_limits(): void
    {
        foreach (['admin', 'system_admin', 'pos_clerk', 'outlet_manager'] as $role) {
            $user = $this->staff([$role]);
            Sanctum::actingAs($user);
            $this->stepUp($user);

            $this->getJson('/api/v1/admin/settings/discount-limits')->assertForbidden()->assertJsonPath('code', 'SUPER_ADMIN_ONLY');
            $this->limits([['role' => $role, 'cap_percent' => 50]])->assertForbidden()->assertJsonPath('code', 'SUPER_ADMIN_ONLY');
        }

        $this->assertSame(['admin' => 15.0, 'pos_clerk' => 10.0], collect(RoleDiscountCaps::fresh())->sortKeys()->all());
    }

    public function test_a_save_without_step_up_is_challenged_and_changes_nothing(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->limits([['role' => 'pos_clerk', 'cap_percent' => 50]])
            ->assertForbidden()->assertJsonPath('code', 'step_up_required');
        $this->assertSame(10.0, RoleDiscountCaps::fresh()['pos_clerk']);
    }
}
