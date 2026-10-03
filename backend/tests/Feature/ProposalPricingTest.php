<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\ChangeProposal;
use App\Models\Material;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Support\CostBasis;
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
 * Phase 3C — proposals on prices, costs and credit. Each event: below its band
 * the change applies at once; above, it waits and the live value does NOT
 * move; the last signature applies it; a rejection or an expiry leaves it; the
 * maker cannot sign. Plus: selling below cost escalates, COGS snapshots never
 * move, anti-splitting on the value 24 hours ago / the customer's other credit.
 *
 * Defaults under test (migration seed):
 *   selling_price_change  ≤10% & not below cost at once · >10% or below cost +FM · >20% below cost +SA
 *   product_cost_change / supplier_cost_change  ≤5% at once · ≤25% +FM · above +SA
 *   customer_credit  balance ≤20,000 at once (OM) · ≤200,000 +FM · above +SA
 */
class ProposalPricingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;   // catalogue editor: selling price maker
    private User $pm;      // procurement manager: cost maker
    private User $fm;      // finance manager
    private User $owner;   // super admin
    private User $owner2;  // the second super admin
    private User $om;      // outlet manager: deposit terms

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        ReportingCurrency::forget();

        $this->admin  = $this->user('admin');
        $this->pm     = $this->user('procurement_manager');
        $this->fm     = $this->user('finance_manager');
        $this->owner  = $this->user('super_admin');
        $this->owner2 = $this->user('super_admin');
        $this->om     = $this->user('outlet_manager');
    }

    private function user(string ...$roles): User
    {
        $u = User::factory()->create(['status' => 'active']);
        foreach ($roles as $r) {
            $u->assignRole(Role::findOrCreate($r, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    /** A product with a KES price row: regular 1,000, cost 800 unless said. */
    private function priced(float $regular = 1000, ?float $cost = 800): ProductPrice
    {
        $product = Product::factory()->create();

        return ProductPrice::create([
            'product_id' => $product->id, 'product_variant_id' => null, 'currency_code' => 'KES',
            'regular_price' => $regular, 'sale_price' => null, 'cost_price' => $cost,
        ]);
    }

    private function savePrice(User $as, ProductPrice $row, array $price)
    {
        Sanctum::actingAs($as);

        return $this->putJson("/api/v1/admin/products/{$row->product_id}", [
            'prices' => [array_merge(['currency_code' => $row->currency_code, 'regular_price' => (float) $row->regular_price], $price)],
        ]);
    }

    private function propose(User $as, string $event, int $subjectId, array $changes, array $extra = [])
    {
        Sanctum::actingAs($as);

        return $this->postJson('/api/v1/admin/proposals', array_merge([
            'event' => $event, 'subject_id' => $subjectId, 'changes' => $changes,
        ], $extra));
    }

    private function latest(string $event): ChangeProposal
    {
        return ChangeProposal::where('event', $event)->orderByDesc('id')->firstOrFail();
    }

    private function requestOf(ChangeProposal $p): ApprovalRequest
    {
        return ApprovalRequest::where('event', $p->event)->where('approvable_type', $p->getMorphClass())
            ->where('approvable_id', $p->id)->orderByDesc('version')->firstOrFail();
    }

    private function sign(User $as, ApprovalRequest $r)
    {
        Sanctum::actingAs($as);

        return $this->postJson("/api/v1/admin/approvals/{$r->id}/sign", ['approvable_id' => $r->approvable_id, 'version' => $r->version]);
    }

    private function reject(User $as, ApprovalRequest $r, string $reason = 'Not agreed')
    {
        Sanctum::actingAs($as);

        return $this->postJson("/api/v1/admin/approvals/{$r->id}/reject", ['approvable_id' => $r->approvable_id, 'version' => $r->version, 'reason' => $reason]);
    }

    private function bands(ApprovalRequest $r): array
    {
        return array_column($r->fresh()->bands, 'permission');
    }

    // ── selling price ────────────────────────────────────────────────────────

    public function test_a_selling_price_change_within_10_percent_and_above_cost_applies_at_once(): void
    {
        $row = $this->priced(1000, 800);

        $this->savePrice($this->admin, $row, ['regular_price' => 1080])->assertOk()
            ->assertJsonPath('proposals.0.status', 'applied')
            ->assertJsonPath('proposals.0.direct', true);

        $this->assertSame(1080.0, (float) $row->fresh()->regular_price, 'before 1,000 → after 1,080, at once');
        $p = $this->latest('selling_price_change');
        $this->assertEquals(['old' => 1000, 'new' => 1080], $p->changeset['regular_price']);
        $this->assertSame(0, ApprovalRequest::where('event', 'selling_price_change')->count(), 'no signature asked');
        $this->assertTrue(DB::table('activity_log')->where('event', 'proposal_applied')->exists(), 'audited');
    }

    public function test_a_selling_price_change_over_10_percent_waits_for_finance_and_does_not_move_the_live_price(): void
    {
        $row = $this->priced(1000, 800);

        $this->savePrice($this->admin, $row, ['regular_price' => 1250])->assertOk()
            ->assertJsonPath('proposals.0.status', 'pending')
            ->assertJsonPath('proposals.0.request.needs', ['Finance']);
        $this->assertSame(1000.0, (float) $row->fresh()->regular_price, 'the live price stays at 1,000 while it waits');

        $p = $this->latest('selling_price_change');
        $r = $this->requestOf($p);
        $this->assertSame(['approvals.finance_sign'], $this->bands($r), 'above cost: finance alone, never the super admin');

        // The maker cannot sign their own change.
        $this->sign($this->admin, $r)->assertForbidden();
        $this->sign($this->fm, $r)->assertOk()->assertJsonPath('request.status', 'approved');

        $this->assertSame(1250.0, (float) $row->fresh()->regular_price, 'approval applies it: 1,000 → 1,250');
        $this->assertSame(ChangeProposal::APPLIED, $p->fresh()->status);
    }

    public function test_a_maker_who_also_holds_the_band_still_cannot_sign_their_own_price(): void
    {
        $both = $this->user('admin', 'finance_manager');
        $row  = $this->priced(1000, 800);

        $this->savePrice($both, $row, ['regular_price' => 1500])->assertOk();
        $r = $this->requestOf($this->latest('selling_price_change'));

        $this->sign($both, $r)->assertForbidden()->assertJsonPath('code', 'SELF_APPROVAL');
        $this->assertSame(1000.0, (float) $row->fresh()->regular_price);
    }

    public function test_a_price_below_cost_needs_finance_even_inside_10_percent(): void
    {
        $row = $this->priced(1000, 1020);   // already a touch below cost

        $this->savePrice($this->admin, $row, ['regular_price' => 990])->assertOk()
            ->assertJsonPath('proposals.0.status', 'pending');
        $p = $this->latest('selling_price_change');
        $this->assertSame(['approvals.finance_sign'], $this->bands($this->requestOf($p)));
        $this->assertEqualsWithDelta(2.94, $p->measures['below_cost_pct'], 0.01);
        $this->assertSame(1000.0, (float) $row->fresh()->regular_price);
    }

    public function test_more_than_20_percent_below_cost_escalates_to_the_super_admin(): void
    {
        $row = $this->priced(1000, 800);

        // 600 is 25% below the 800 cost.
        $this->savePrice($this->admin, $row, ['regular_price' => 600])->assertOk();
        $p = $this->latest('selling_price_change');
        $r = $this->requestOf($p);
        $this->assertSame(['approvals.finance_sign', 'approvals.super_sign'], $this->bands($r));
        $this->assertEqualsWithDelta(25.0, $p->measures['below_cost_pct'], 0.001);

        $this->sign($this->fm, $r)->assertOk()->assertJsonPath('request.status', 'pending');
        $this->assertSame(1000.0, (float) $row->fresh()->regular_price, 'one band of two is not an approval');
        $this->sign($this->owner, $r)->assertOk()->assertJsonPath('request.status', 'approved');
        $this->assertSame(600.0, (float) $row->fresh()->regular_price);
    }

    public function test_a_new_sale_price_below_cost_is_judged_on_the_sale(): void
    {
        $row = $this->priced(1000, 800);

        $this->savePrice($this->admin, $row, ['regular_price' => 1000, 'sale_price' => 700])->assertOk();
        $p = $this->latest('selling_price_change');
        $this->assertEqualsWithDelta(12.5, $p->measures['below_cost_pct'], 0.001, '700 vs 800 cost');
        $this->assertEqualsWithDelta(30.0, $p->measures['change_pct']['sale_price'], 0.001, 'a new sale 30% off');
        $this->assertNull($row->fresh()->sale_price, 'no sale until it is signed');
    }

    public function test_with_no_cost_on_the_book_a_price_change_needs_every_band(): void
    {
        $row = $this->priced(1000, null);

        $this->savePrice($this->admin, $row, ['regular_price' => 1020])->assertOk();
        $r = $this->requestOf($this->latest('selling_price_change'));
        $this->assertTrue($r->value_unknown);
        $this->assertSame(['approvals.finance_sign', 'approvals.super_sign'], $this->bands($r), 'never a guess');
        $this->assertSame(1000.0, (float) $row->fresh()->regular_price);
    }

    public function test_rejection_and_expiry_leave_the_live_price(): void
    {
        $row = $this->priced(1000, 800);
        $this->savePrice($this->admin, $row, ['regular_price' => 1300])->assertOk();
        $p = $this->latest('selling_price_change');

        $this->reject($this->fm, $this->requestOf($p))->assertOk();
        $this->assertSame(ChangeProposal::REJECTED, $p->fresh()->status);
        $this->assertSame(1000.0, (float) $row->fresh()->regular_price, 'rejected: still 1,000');

        // A fresh proposal, left to expire.
        $this->savePrice($this->admin, $row, ['regular_price' => 1400])->assertOk();
        $q = $this->latest('selling_price_change');
        $this->travel(73)->hours();
        Artisan::call('approvals:expire');
        $this->assertSame(ChangeProposal::EXPIRED, $q->fresh()->status);
        $this->assertSame(1000.0, (float) $row->fresh()->regular_price, 'expired: still 1,000 — never approved by time');
    }

    public function test_a_rejected_price_comes_back_only_as_a_new_version_of_the_same_value(): void
    {
        $row = $this->priced(1000, 800);
        $this->savePrice($this->admin, $row, ['regular_price' => 1300])->assertOk();
        $p = $this->latest('selling_price_change');
        $this->reject($this->fm, $this->requestOf($p))->assertOk();

        // Only the maker resubmits; it becomes version 2, linked to version 1.
        Sanctum::actingAs($this->fm);
        $old = $this->requestOf($p);
        $this->postJson("/api/v1/admin/approvals/{$old->id}/resubmit")->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/admin/approvals/{$old->id}/resubmit")->assertCreated()
            ->assertJsonPath('request.version', 2)->assertJsonPath('request.supersedes_id', $old->id);
        $this->assertSame(ChangeProposal::PENDING, $p->fresh()->status);
        $this->assertSame(1000.0, (float) $row->fresh()->regular_price);

        $this->sign($this->fm, $this->requestOf($p))->assertOk();
        $this->assertSame(1300.0, (float) $row->fresh()->regular_price);

        // Once the live value has moved on, an old rejected proposal cannot come back.
        $this->savePrice($this->admin, $row->fresh(), ['regular_price' => 1700])->assertOk();
        $q = $this->latest('selling_price_change');
        $this->reject($this->fm, $this->requestOf($q))->assertOk();
        DB::table('product_prices')->where('id', $row->id)->update(['regular_price' => 1310]);
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/admin/approvals/{$this->requestOf($q)->id}/resubmit")->assertStatus(422);
        $this->assertSame(1310.0, (float) $row->fresh()->regular_price);
    }

    public function test_a_second_change_while_one_waits_is_refused_and_nothing_of_the_save_is_kept(): void
    {
        $row = $this->priced(1000, 800);
        $this->savePrice($this->admin, $row, ['regular_price' => 1300])->assertOk();

        $this->savePrice($this->admin, $row->fresh(), ['regular_price' => 1350])
            ->assertStatus(409)->assertJsonPath('code', 'PROPOSAL_PENDING');
        $this->assertSame(1, ChangeProposal::where('event', 'selling_price_change')->count());

        // Resending the live price (an edit of something else) raises nothing.
        $this->savePrice($this->admin, $row->fresh(), ['regular_price' => 1000])->assertOk()->assertJsonPath('proposals', []);
    }

    public function test_cuts_split_inside_24_hours_are_judged_from_the_price_24_hours_ago(): void
    {
        $row = $this->priced(1000, 500);

        $this->savePrice($this->admin, $row, ['regular_price' => 940])->assertOk()->assertJsonPath('proposals.0.status', 'applied');
        $this->savePrice($this->admin, $row->fresh(), ['regular_price' => 880])->assertOk()
            ->assertJsonPath('proposals.0.status', 'pending');   // 880 is 12% under the 1,000 of 24 h ago

        $p = $this->latest('selling_price_change');
        $this->assertEqualsWithDelta(12.0, $p->measures['direct_basis'], 0.001);
        $this->assertSame(940.0, (float) $row->fresh()->regular_price);
    }

    public function test_a_price_changed_after_submission_cannot_be_signed(): void
    {
        $row = $this->priced(1000, 800);
        $this->savePrice($this->admin, $row, ['regular_price' => 1300])->assertOk();
        $r = $this->requestOf($this->latest('selling_price_change'));

        DB::table('product_prices')->where('id', $row->id)->update(['regular_price' => 1050]);   // a write behind its back
        $this->sign($this->fm, $r)->assertStatus(422)->assertJsonPath('code', 'APPROVAL_STALE');
        $this->assertSame(1050.0, (float) $row->fresh()->regular_price);
    }

    public function test_the_inbox_shows_old_and_new(): void
    {
        $row = $this->priced(1000, 800);
        $this->savePrice($this->admin, $row, ['regular_price' => 1250])->assertOk();

        Sanctum::actingAs($this->fm);
        $item = collect($this->getJson('/api/v1/admin/approvals/inbox')->assertOk()->json('data'))
            ->firstWhere('event', 'selling_price_change');
        $this->assertNotNull($item, 'the proposal is in the finance inbox');
        $this->assertContains('Regular price: KES 1,000.00 → KES 1,250.00', $item['summary']['lines']);
        $this->assertStringStartsWith('Selling price: ', $item['summary']['title']);

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/v1/admin/proposals?subject_type=product_price&subject_id={$row->id}&status=open")
            ->assertOk()->assertJsonPath('data.0.request.awaiting', 'Finance')
            ->assertJsonPath('data.0.changes.0.new_display', 'KES 1,250.00');
    }

    public function test_the_maker_can_withdraw_and_the_live_value_stays(): void
    {
        $row = $this->priced(1000, 800);
        $this->savePrice($this->admin, $row, ['regular_price' => 1250])->assertOk();
        $p = $this->latest('selling_price_change');

        Sanctum::actingAs($this->fm);
        $this->postJson("/api/v1/admin/proposals/{$p->id}/withdraw")->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/admin/proposals/{$p->id}/withdraw")->assertOk();
        $this->assertSame(ChangeProposal::CANCELLED, $p->fresh()->status);
        $this->assertSame(ApprovalRequest::CANCELLED, $this->requestOf($p)->status);
        $this->assertSame(1000.0, (float) $row->fresh()->regular_price);
    }

    public function test_preview_says_who_must_sign_before_saving(): void
    {
        $row = $this->priced(1000, 800);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/admin/proposals/preview', ['event' => 'selling_price_change', 'subject_id' => $row->id, 'changes' => ['regular_price' => 1050]])
            ->assertOk()->assertJsonPath('direct', true);
        $this->postJson('/api/v1/admin/proposals/preview', ['event' => 'selling_price_change', 'subject_id' => $row->id, 'changes' => ['regular_price' => 500]])
            ->assertOk()->assertJsonPath('direct', false)->assertJsonPath('needs', ['Finance', 'Super admin'])
            ->assertJsonPath('message', 'This change needs approval from Finance, then Super admin.');
        $this->assertSame(0, ChangeProposal::count(), 'a preview saves nothing');
    }

    // ── product cost ─────────────────────────────────────────────────────────

    public function test_a_cost_change_within_5_percent_applies_at_once_for_the_procurement_manager(): void
    {
        $row = $this->priced(1000, 800);

        $this->propose($this->pm, 'product_cost_change', $row->id, ['cost_price' => 830])->assertOk()
            ->assertJsonPath('proposal.status', 'applied');
        $this->assertSame(830.0, (float) $row->fresh()->cost_price);
    }

    public function test_a_cost_change_over_5_percent_waits_for_finance_and_over_25_for_the_super_admin(): void
    {
        $row = $this->priced(1000, 800);

        $this->propose($this->pm, 'product_cost_change', $row->id, ['cost_price' => 900])->assertStatus(202);   // +12.5%
        $p = $this->latest('product_cost_change');
        $this->assertSame(['approvals.finance_sign'], $this->bands($this->requestOf($p)));
        $this->assertSame(800.0, (float) $row->fresh()->cost_price, 'waits: cost stays 800');
        $this->sign($this->pm, $this->requestOf($p))->assertForbidden();
        $this->sign($this->fm, $this->requestOf($p))->assertOk();
        $this->assertSame(900.0, (float) $row->fresh()->cost_price, 'signed: 800 → 900');

        $other = $this->priced(1000, 800);
        $this->propose($this->pm, 'product_cost_change', $other->id, ['cost_price' => 1100])->assertStatus(202);   // +37.5%
        $q = $this->latest('product_cost_change');
        $this->assertSame(['approvals.finance_sign', 'approvals.super_sign'], $this->bands($this->requestOf($q)));
        // The owner cannot spend his top-band signature on finance's band (engine rule)…
        $this->sign($this->owner, $this->requestOf($q))->assertForbidden()->assertJsonPath('code', 'NOT_YOUR_BAND');
        // …and finance rejects it.
        $this->reject($this->fm, $this->requestOf($q))->assertOk();
        $this->assertSame(800.0, (float) $other->fresh()->cost_price, 'rejected: cost stays 800');
    }

    public function test_a_cost_change_never_rewrites_historical_cogs(): void
    {
        $row = $this->priced(1000, 800);
        $order = Order::factory()->create();
        $snap = DB::table('order_items')->insertGetId($this->line($order->id, $row->product_id, 750));     // snapshot at sale
        $bare = DB::table('order_items')->insertGetId($this->line($order->id, $row->product_id, null));    // sold before cost existed

        $cogs = fn () => (float) DB::selectOne('SELECT SUM(quantity * ' . CostBasis::unitCostSql('oi') . ') AS c FROM order_items oi WHERE order_id = ?', [$order->id])->c;
        $before = $cogs();
        $this->assertSame(1550.0, $before, '750 snapshot + 800 through the book');

        $this->propose($this->pm, 'product_cost_change', $row->id, ['cost_price' => 1000])->assertStatus(202);
        $this->sign($this->fm, $this->requestOf($this->latest('product_cost_change')))->assertOk();
        $this->assertSame(1000.0, (float) $row->fresh()->cost_price);

        $this->assertSame($before, $cogs(), 'COGS for past sales is the same number after the change');
        $this->assertSame(750.0, (float) DB::table('order_items')->where('id', $snap)->value('cost_price'), 'a snapshot never moves');
        $this->assertSame(800.0, (float) DB::table('order_items')->where('id', $bare)->value('cost_price'), 'the bare line was frozen at the old cost');
        $this->assertSame('book_frozen', DB::table('order_items')->where('id', $bare)->value('cost_source'));
    }

    private function line(int $orderId, int $productId, ?float $cost): array
    {
        return [
            'order_id' => $orderId, 'product_id' => $productId, 'product_name' => 'Item', 'sku' => 'SKU-' . uniqid(),
            'quantity' => 1, 'unit_price' => 1000, 'total_price' => 1000, 'cost_price' => $cost,
            'cost_source' => $cost === null ? null : 'product_price', 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    public function test_an_editor_without_the_cost_key_cannot_write_cost(): void
    {
        $row = $this->priced(1000, 800);
        $this->propose($this->om, 'product_cost_change', $row->id, ['cost_price' => 810])->assertForbidden()
            ->assertJsonPath('code', 'NOT_A_MAKER');
        $this->assertSame(800.0, (float) $row->fresh()->cost_price);
    }

    // ── supplier (material) cost ─────────────────────────────────────────────

    public function test_supplier_cost_bands(): void
    {
        $m = Material::create(['code' => 'MAT-' . uniqid(), 'name' => 'Gabardine', 'unit_of_measure' => 'm', 'unit_cost' => 500]);
        Sanctum::actingAs($this->pm);

        $this->putJson("/api/v1/admin/inventory/materials/{$m->id}", ['unit_cost' => 515])->assertOk();
        $this->assertSame(515.0, (float) $m->fresh()->unit_cost, '+3%: at once');

        $this->putJson("/api/v1/admin/inventory/materials/{$m->id}", ['unit_cost' => 600, 'name' => 'Gabardine 2'])->assertOk()
            ->assertJsonPath('proposal.status', 'pending');
        $this->assertSame(515.0, (float) $m->fresh()->unit_cost, '+16.5%: waits');
        $this->assertSame('Gabardine 2', $m->fresh()->name, 'the plain edit still saves');

        $this->sign($this->fm, $this->requestOf($this->latest('supplier_cost_change')))->assertOk();
        $this->assertSame(600.0, (float) $m->fresh()->unit_cost);
    }

    public function test_a_receipt_at_a_new_cost_records_what_was_paid_but_the_material_cost_waits(): void
    {
        $m = Material::create(['code' => 'MAT-' . uniqid(), 'name' => 'Silk', 'unit_of_measure' => 'm', 'unit_cost' => 1000]);
        $outlet = \App\Models\Outlet::factory()->create();
        Sanctum::actingAs($this->pm);

        $this->postJson("/api/v1/admin/inventory/materials/{$m->id}/receive", [
            'outlet_id' => $outlet->id, 'quantity' => 5, 'transaction_type' => 'purchase', 'unit_cost' => 1400,
        ])->assertCreated()->assertJsonPath('proposal.status', 'pending');

        $this->assertSame(1000.0, (float) $m->fresh()->unit_cost, 'the master cost waits for finance');
        $this->assertSame(1400.0, (float) DB::table('material_transactions')->orderByDesc('id')->value('unit_cost'), 'the receipt keeps what was paid');

        // A second receipt while that waits still goes through.
        $this->postJson("/api/v1/admin/inventory/materials/{$m->id}/receive", [
            'outlet_id' => $outlet->id, 'quantity' => 1, 'transaction_type' => 'purchase', 'unit_cost' => 1450,
        ])->assertCreated();
    }

    public function test_a_cost_blind_maker_proposes_a_material_cost_without_seeing_any_cost(): void
    {
        // The outlet manager edits materials (inventory.adjust) but may not see cost (1C field rule).
        $m = Material::create(['code' => 'MAT-' . uniqid(), 'name' => 'Linen', 'unit_of_measure' => 'm', 'unit_cost' => 400]);
        Sanctum::actingAs($this->om);
        $this->putJson("/api/v1/admin/inventory/materials/{$m->id}", ['unit_cost' => 600])->assertOk();

        $res = $this->getJson("/api/v1/admin/proposals?subject_type=material&subject_ids={$m->id}&status=open")->assertOk();
        $this->assertSame('•••', $res->json('data.0.changes.0.old_display'), 'the old cost is not shown to a cost-blind maker');
        $this->assertNull($res->json('data.0.changes.0.old'));
        $this->assertNull($res->json('data.0.measure'));

        Sanctum::actingAs($this->fm);
        $this->getJson("/api/v1/admin/proposals?subject_type=material&subject_ids={$m->id}")->assertOk()
            ->assertJsonPath('data.0.changes.0.old_display', 'KES 400.00');
    }

    public function test_proposals_are_listed_only_to_those_who_may_see_the_event(): void
    {
        $row = $this->priced(1000, 800);
        $this->savePrice($this->admin, $row, ['regular_price' => 1250])->assertOk();

        $clerk = $this->user('pos_clerk');
        Sanctum::actingAs($clerk);
        $this->getJson("/api/v1/admin/proposals?subject_type=product_price&subject_ids={$row->id}")->assertOk()->assertJsonPath('count', 0);
        $id = $this->latest('selling_price_change')->id;
        $this->getJson("/api/v1/admin/proposals/{$id}")->assertForbidden();
    }

    // ── customer credit (deposit terms) ──────────────────────────────────────

    private function order(float $total, ?int $customerId = null, string $currency = 'KES'): Order
    {
        return Order::factory()->create([
            'total_amount' => $total, 'subtotal' => $total, 'payment_status' => 'pending',
            'customer_id' => $customerId, 'currency_code' => $currency,
        ]);
    }

    private function setDeposit(User $as, Order $o, float $deposit)
    {
        Sanctum::actingAs($as);

        return $this->postJson("/api/v1/admin/orders/{$o->id}/set-deposit", ['deposit_amount' => $deposit]);
    }

    public function test_credit_within_20000_applies_at_once_for_the_outlet_manager(): void
    {
        $o = $this->order(50000);
        $this->setDeposit($this->om, $o, 35000)->assertOk()->assertJsonPath('message', 'Deposit terms set.');
        $this->assertSame(35000.0, (float) $o->fresh()->deposit_amount, '15,000 on credit: at once');
        $this->assertTrue(DB::table('activity_log')->where('event', 'deposit_terms_set')->where('subject_id', $o->id)->exists(),
            'the order timeline entry is still written');
    }

    public function test_credit_bands_finance_then_super_admin_and_rejection_leaves_the_terms(): void
    {
        $o = $this->order(200000);
        $this->setDeposit($this->om, $o, 50000)->assertStatus(202);        // 150,000 on credit
        $p = $this->latest('customer_credit');
        $this->assertSame(['approvals.finance_sign'], $this->bands($this->requestOf($p)));
        $this->assertNull($o->fresh()->deposit_amount, 'no terms until signed');
        $this->sign($this->om, $this->requestOf($p))->assertForbidden();
        $this->sign($this->fm, $this->requestOf($p))->assertOk();
        $this->assertSame(50000.0, (float) $o->fresh()->deposit_amount);

        $big = $this->order(500000);
        $this->setDeposit($this->om, $big, 100000)->assertStatus(202);     // 400,000 on credit
        $q = $this->latest('customer_credit');
        $this->assertSame(['approvals.finance_sign', 'approvals.super_sign'], $this->bands($this->requestOf($q)));
        $this->reject($this->fm, $this->requestOf($q))->assertOk();
        $this->assertNull($big->fresh()->deposit_amount, 'rejected: the order keeps its terms (none)');
    }

    public function test_credit_split_across_one_customers_orders_is_added_up(): void
    {
        $customerId = DB::table('customers')->insertGetId([
            'customer_number' => 'C-' . uniqid(), 'first_name' => 'Ann', 'last_name' => 'K', 'email' => 'ann' . uniqid() . '@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $a = $this->order(30000, $customerId);
        $b = $this->order(30000, $customerId);

        $this->setDeposit($this->om, $a, 15000)->assertOk();          // 15,000: at once
        $this->setDeposit($this->om, $b, 15000)->assertStatus(202);   // + 15,000 = 30,000 > 20,000
        $p = $this->latest('customer_credit');
        $this->assertEqualsWithDelta(15000, $p->measures['other_credit_kes'], 0.01);
        $this->assertEqualsWithDelta(30000, (float) $this->requestOf($p)->basis_kes, 0.01);
    }

    public function test_less_credit_than_the_order_carries_applies_at_once(): void
    {
        $o = $this->order(200000);
        DB::table('orders')->where('id', $o->id)->update(['deposit_amount' => 50000]);   // 150,000 on credit already

        $this->setDeposit($this->om, $o, 120000)->assertOk();           // 80,000: less credit
        $this->assertSame(120000.0, (float) $o->fresh()->deposit_amount);
    }
}
