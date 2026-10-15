<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\ChangeProposal;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cost is kept once, on the KES row (owner report 2026-10-15: saving
 * "Single Sided Stole", CLE-S-009, failed with "Cost is kept on the KES price
 * row only.").
 *
 * The console sent cost_price on every currency row — null, or a figure loaded
 * from an old USD/ZMW row — and the product and variant save paths passed it
 * to the product_cost_change handler, which refuses any non-KES row. So every
 * save by a cost holder on a product that has a USD or ZMW row failed.
 *
 * Pins: a stray non-KES cost in a product or variant save payload is dropped,
 * not refused, and never stored (on an existing row or a new one, on create or
 * update); a KES cost still goes through the cost-change bands unchanged; the
 * direct proposal API keeps its refusal for a non-KES row.
 */
class ProductCostKesOnlyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;   // super admin — the owner who hit the bug
    private User $admin;   // catalogue editor; holds products.edit_cost via products.*
    private User $fm;      // finance manager — signs the 5–25% cost band

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        DB::table('currencies')->updateOrInsert(['code' => 'GBP'], [
            'name' => 'British Pound', 'symbol' => '£', 'exchange_rate' => 0.006667,
            'reporting_rate_to_kes' => 165, 'is_base' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        ReportingCurrency::forget();

        $this->owner = $this->user('super_admin');
        $this->admin = $this->user('admin');
        $this->fm    = $this->user('finance_manager');
    }

    private function user(string $role): User
    {
        $u = User::factory()->create(['status' => 'active']);
        $u->assignRole(Role::findOrCreate($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function row(int $productId, ?int $variantId, string $currency, float $regular, ?float $cost): ProductPrice
    {
        return ProductPrice::create([
            'product_id' => $productId, 'product_variant_id' => $variantId, 'currency_code' => $currency,
            'regular_price' => $regular, 'sale_price' => null, 'cost_price' => $cost,
        ]);
    }

    private function cost(int $productId, ?int $variantId, string $currency): ?float
    {
        $v = ProductPrice::where('product_id', $productId)
            ->when($variantId === null, fn ($q) => $q->whereNull('product_variant_id'), fn ($q) => $q->where('product_variant_id', $variantId))
            ->where('currency_code', $currency)->value('cost_price');

        return $v === null ? null : (float) $v;
    }

    /** The CLE-S-009 shape: KES 2,500 / USD 30 / ZMW 500, no cost anywhere, no GBP row. */
    private function stole(): Product
    {
        $product = Product::factory()->create();
        $this->row($product->id, null, 'KES', 2500, null);
        $this->row($product->id, null, 'USD', 30, null);
        $this->row($product->id, null, 'ZMW', 500, null);

        return $product;
    }

    /** Every row the console sends, cost on each one, as the form did. */
    private function everyRow(float $kesCost, float $otherCost): array
    {
        return [
            ['currency_code' => 'GBP', 'regular_price' => 16.67, 'sale_price' => null, 'cost_price' => $otherCost],
            ['currency_code' => 'KES', 'regular_price' => 2500,  'sale_price' => null, 'cost_price' => $kesCost],
            ['currency_code' => 'USD', 'regular_price' => 30,    'sale_price' => null, 'cost_price' => $otherCost],
            ['currency_code' => 'ZMW', 'regular_price' => 500,   'sale_price' => null, 'cost_price' => $otherCost],
        ];
    }

    public function test_the_owners_save_with_cost_on_every_row_succeeds_and_keeps_cost_on_kes_only(): void
    {
        $product = $this->stole();
        Sanctum::actingAs($this->owner);

        // Exactly the failing request: KES cost 1,100, and the other rows
        // carrying a cost too (here the same figure, as the old form copied).
        $this->putJson("/api/v1/admin/products/{$product->id}", ['prices' => $this->everyRow(1100, 1100)])
            ->assertOk()
            ->assertJsonPath('proposals.0.status', 'applied');   // a first cost measures 0 %: at once

        $this->assertSame(1100.0, $this->cost($product->id, null, 'KES'));
        $this->assertNull($this->cost($product->id, null, 'USD'), 'USD keeps no cost');
        $this->assertNull($this->cost($product->id, null, 'ZMW'), 'ZMW keeps no cost');
        $this->assertNull($this->cost($product->id, null, 'GBP'), 'a NEW GBP row is created without a cost');
        $this->assertSame(1, ChangeProposal::where('event', 'product_cost_change')->count(), 'one cost proposal: the KES row');
    }

    public function test_a_null_cost_on_the_other_rows_no_longer_blocks_a_save(): void
    {
        $product = $this->stole();
        Sanctum::actingAs($this->admin);

        $rows = array_map(fn ($r) => $r['currency_code'] === 'KES' ? $r : ['cost_price' => null] + $r, $this->everyRow(1100, 0));
        $this->putJson("/api/v1/admin/products/{$product->id}", ['prices' => $rows])->assertOk();

        $this->assertSame(1100.0, $this->cost($product->id, null, 'KES'));
    }

    public function test_a_legacy_cost_on_a_usd_row_is_left_exactly_as_it_was(): void
    {
        // 74 USD and 57 ZMW rows carry a cost from before the KES-only rule.
        // A save neither rewrites nor clears them; nothing reads them.
        $product = $this->stole();
        ProductPrice::where('product_id', $product->id)->where('currency_code', 'USD')->update(['cost_price' => 9]);
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/v1/admin/products/{$product->id}", ['prices' => $this->everyRow(1100, 12)])->assertOk();

        $this->assertSame(9.0, $this->cost($product->id, null, 'USD'));
    }

    public function test_a_variant_save_with_cost_on_every_row_keeps_cost_on_its_kes_row_only(): void
    {
        $product = $this->stole();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->row($product->id, $variant->id, 'KES', 2500, null);
        $this->row($product->id, $variant->id, 'USD', 30, null);
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/v1/admin/products/{$product->id}/variants/{$variant->id}", [
            'prices' => [
                ['currency_code' => 'KES', 'regular_price' => 2500, 'sale_price' => null, 'cost_price' => 1150],
                ['currency_code' => 'USD', 'regular_price' => 30,   'sale_price' => null, 'cost_price' => 11.5],
                ['currency_code' => 'ZMW', 'regular_price' => 500,  'sale_price' => null, 'cost_price' => 161],
            ],
        ])->assertOk();

        $this->assertSame(1150.0, $this->cost($product->id, $variant->id, 'KES'));
        $this->assertNull($this->cost($product->id, $variant->id, 'USD'));
        $this->assertNull($this->cost($product->id, $variant->id, 'ZMW'), 'the new ZMW variant row has no cost');
        $this->assertNull($this->cost($product->id, null, 'KES'), 'the product row is untouched');
    }

    public function test_creating_a_product_or_a_variant_stores_cost_on_kes_only(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson('/api/v1/admin/products', [
            // tax_class as the console sends it (the column is NOT NULL).
            'sku' => 'KES-ONLY-1', 'product_type' => 'variable', 'status' => 'draft', 'tax_class' => 'standard',
            'translations' => [['language_code' => 'en', 'name' => 'Single Sided Stole', 'description' => 'Stole']],
            'prices' => $this->everyRow(1100, 7.37),
        ])->assertCreated()->json('product.id');

        $this->assertSame(1100.0, $this->cost($id, null, 'KES'));
        $this->assertNull($this->cost($id, null, 'GBP'));
        $this->assertNull($this->cost($id, null, 'USD'));

        $variantId = $this->postJson("/api/v1/admin/products/{$id}/variants", [
            'sku' => 'KES-ONLY-1-BLA', 'variant_name' => 'Black',
            'prices' => [
                ['currency_code' => 'KES', 'regular_price' => 2500, 'cost_price' => 1100],
                ['currency_code' => 'USD', 'regular_price' => 30,   'cost_price' => 11],
            ],
        ])->assertCreated()->json('variant.id');

        $this->assertSame(1100.0, $this->cost($id, $variantId, 'KES'));
        $this->assertNull($this->cost($id, $variantId, 'USD'));
    }

    public function test_a_kes_cost_change_on_save_still_goes_through_the_cost_bands(): void
    {
        $product = $this->stole();
        ProductPrice::where('product_id', $product->id)->where('currency_code', 'KES')->update(['cost_price' => 800]);
        $kes = ProductPrice::where('product_id', $product->id)->where('currency_code', 'KES')->firstOrFail();
        Sanctum::actingAs($this->admin);

        // 800 → 900 is +12.5 %: past the 5 % band, finance signs. The stray
        // USD/GBP/ZMW costs are dropped and raise nothing.
        $this->putJson("/api/v1/admin/products/{$product->id}", ['prices' => $this->everyRow(900, 10)])
            ->assertOk()
            ->assertJsonPath('proposals.0.status', 'pending');

        $p = ChangeProposal::where('event', 'product_cost_change')->sole();
        $this->assertSame($kes->id, (int) $p->subject_id);
        $this->assertSame(800.0, (float) $kes->fresh()->cost_price, 'waits: the live cost stays 800');

        $r = ApprovalRequest::where('event', 'product_cost_change')->where('approvable_id', $p->id)->sole();
        $this->assertSame(['approvals.finance_sign'], array_column($r->bands, 'permission'));

        Sanctum::actingAs($this->fm);
        $this->postJson("/api/v1/admin/approvals/{$r->id}/sign", ['approvable_id' => $r->approvable_id, 'version' => $r->version])->assertOk();
        $this->assertSame(900.0, (float) $kes->fresh()->cost_price, 'signed: 800 → 900');
        $this->assertNull($this->cost($product->id, null, 'USD'));
    }

    public function test_the_direct_proposal_api_still_refuses_a_cost_on_a_non_kes_row(): void
    {
        $product = $this->stole();
        $usd = ProductPrice::where('product_id', $product->id)->where('currency_code', 'USD')->firstOrFail();
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/admin/proposals', [
            'event' => 'product_cost_change', 'subject_id' => $usd->id, 'changes' => ['cost_price' => 8.59],
        ])->assertStatus(422)->assertJsonValidationErrors('cost_price');

        $this->assertNull($usd->fresh()->cost_price);
    }
}
