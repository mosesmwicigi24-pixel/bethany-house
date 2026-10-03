<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\ApprovalThreshold;
use App\Models\Product;
use App\Models\PurchaseOrder;
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
 * Phase 3B — the approval engine's rules, exercised through purchase orders
 * (the event with three bands): cumulative bands in order, maker ≠ checker at
 * every band, escalation, anti-splitting, KES at the reporting rate, expiry,
 * versions and binding, and thresholds as audited, super-admin-only config.
 *
 * Defaults under test (migration seed, owner's decision sheet):
 *   purchase_order: ≤100,000 procurement_manager · ≤500,000 + finance · above + super admin
 */
class ApprovalEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;   // raises POs
    private User $pm;        // procurement manager
    private User $fm;        // finance manager
    private User $owner;     // super admin

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        ReportingCurrency::forget();

        $this->officer = $this->user('procurement_officer');
        $this->pm      = $this->user('procurement_manager');
        $this->fm      = $this->user('finance_manager');
        $this->owner   = $this->user('super_admin');
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

    private function supplierId(): int
    {
        return DB::table('suppliers')->insertGetId([
            'code' => 'SUP-' . uniqid(), 'name' => 'Acme Wholesale',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Raise and submit a PO for $total through the API, as $maker. */
    private function submitPo(User $maker, float $total, string $currency = 'KES', ?int $supplierId = null): PurchaseOrder
    {
        Sanctum::actingAs($maker);
        $product = Product::factory()->create();
        $id = $this->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id'            => $supplierId ?? $this->supplierId(),
            'expected_delivery_date' => now()->addWeek()->toDateString(),
            'currency'               => $currency,
            'items'                  => [['type' => 'product', 'item_id' => $product->id, 'quantity' => 1, 'unit_price' => $total]],
        ])->assertCreated()->json('purchase_order.id');

        $this->postJson("/api/v1/admin/purchase-orders/{$id}/submit")->assertOk();

        return PurchaseOrder::findOrFail($id);
    }

    private function requestFor(PurchaseOrder $po): ApprovalRequest
    {
        return ApprovalRequest::where('event', 'purchase_order')
            ->where('approvable_id', $po->id)->orderByDesc('version')->firstOrFail();
    }

    private function sign(User $as, ApprovalRequest $r, array $over = [])
    {
        Sanctum::actingAs($as);

        return $this->postJson("/api/v1/admin/approvals/{$r->id}/sign", array_merge([
            'approvable_id' => $r->approvable_id,
            'version'       => $r->version,
        ], $over));
    }

    private function inboxIds(User $as): array
    {
        Sanctum::actingAs($as);

        return collect($this->getJson('/api/v1/admin/approvals/inbox')->assertOk()->json('data'))->pluck('id')->all();
    }

    private function permissionsOf(ApprovalRequest $r): array
    {
        return array_column($r->fresh()->bands, 'permission');
    }

    // ── cumulative bands, in order ───────────────────────────────────────────

    public function test_a_300k_po_needs_the_procurement_manager_then_finance(): void
    {
        $po = $this->submitPo($this->officer, 300000);
        $r  = $this->requestFor($po);
        $this->assertSame(['procurement.approve', 'approvals.finance_sign'], $this->permissionsOf($r));
        $this->assertEquals(300000, (float) $r->amount_kes);

        // Finance cannot sign before the procurement band.
        $this->assertNotContains($r->id, $this->inboxIds($this->fm));
        $this->sign($this->fm, $r)->assertForbidden()->assertJsonPath('code', 'NOT_YOUR_BAND');

        $this->assertContains($r->id, $this->inboxIds($this->pm));
        $this->sign($this->pm, $r)->assertOk()->assertJsonPath('request.status', 'pending');
        $this->assertSame('pending_approval', $po->fresh()->status, 'one band of two is not an approval');

        $this->assertContains($r->id, $this->inboxIds($this->fm));
        $this->assertNotContains($r->id, $this->inboxIds($this->pm), 'the PM band is signed; it left the PM inbox');
        $this->sign($this->fm, $r)->assertOk()->assertJsonPath('request.status', 'approved');

        $po->refresh();
        $this->assertSame('approved', $po->status);
        $this->assertSame($this->fm->id, (int) $po->approved_by, 'the final signer approves the record');
        $this->assertSame(2, DB::table('approval_signatures')->where('approval_request_id', $r->id)->count());

        // Every step is in the audit trail.
        $this->assertDatabaseHas('activity_log', ['event' => 'approval_submitted', 'causer_id' => $this->officer->id]);
        $this->assertDatabaseHas('activity_log', ['event' => 'approval_signed', 'causer_id' => $this->pm->id]);
        $this->assertDatabaseHas('activity_log', ['event' => 'approval_approved', 'causer_id' => $this->fm->id]);
    }

    public function test_a_600k_po_needs_the_procurement_manager_finance_and_the_super_admin(): void
    {
        $po = $this->submitPo($this->officer, 600000);
        $r  = $this->requestFor($po);
        $this->assertSame(['procurement.approve', 'approvals.finance_sign', 'approvals.super_sign'], $this->permissionsOf($r));

        // The owner may not use up his top-band signature on a lower band.
        $this->sign($this->owner, $r)->assertForbidden()->assertJsonPath('code', 'NOT_YOUR_BAND');

        $this->sign($this->pm, $r)->assertOk();
        $this->sign($this->fm, $r)->assertOk();
        $this->assertSame('pending_approval', $po->fresh()->status);
        $this->assertContains($r->id, $this->inboxIds($this->owner));
        $this->sign($this->owner, $r)->assertOk()->assertJsonPath('request.status', 'approved');
        $this->assertSame('approved', $po->fresh()->status);
    }

    public function test_the_owner_may_veto_at_any_band_though_he_may_not_sign_below_his_own(): void
    {
        $po = $this->submitPo($this->officer, 600000);
        $r  = $this->requestFor($po);

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/approvals/{$r->id}/reject", ['approvable_id' => $po->id, 'version' => 1, 'reason' => 'We are not buying this'])
            ->assertOk()->assertJsonPath('request.status', 'rejected');
        $this->assertSame('draft', $po->fresh()->status);
    }

    public function test_a_small_po_needs_the_procurement_manager_alone_and_the_owner_may_stand_in(): void
    {
        $po = $this->submitPo($this->officer, 600);
        $r  = $this->requestFor($po);
        $this->assertSame(['procurement.approve'], $this->permissionsOf($r));

        $this->assertContains($r->id, $this->inboxIds($this->owner));
        $this->sign($this->owner, $r)->assertOk()->assertJsonPath('request.status', 'approved');
    }

    public function test_the_maker_is_blocked_at_every_band(): void
    {
        // Holds every authority there is — and still signs none of his own.
        $maker = $this->user('procurement_officer', 'procurement_manager', 'finance_manager', 'super_admin');
        $po = $this->submitPo($maker, 600000);
        $r  = $this->requestFor($po);

        $this->assertNotContains($r->id, $this->inboxIds($maker));

        $this->sign($maker, $r)->assertForbidden()->assertJsonPath('code', 'SELF_APPROVAL');
        $this->sign($this->pm, $r)->assertOk();
        $this->sign($maker, $r)->assertForbidden()->assertJsonPath('code', 'SELF_APPROVAL');
        $this->sign($this->fm, $r)->assertOk();
        $this->sign($maker, $r)->assertForbidden()->assertJsonPath('code', 'SELF_APPROVAL');

        $this->assertSame('pending_approval', $po->fresh()->status);
        $this->assertSame(3, DB::table('activity_log')->where('event', 'self_approval_blocked')->where('causer_id', $maker->id)->count());
    }

    public function test_one_person_signs_one_band(): void
    {
        $both = $this->user('procurement_manager', 'finance_manager');
        $po = $this->submitPo($this->officer, 300000);
        $r  = $this->requestFor($po);

        $this->sign($both, $r)->assertOk();
        $this->assertNotContains($r->id, $this->inboxIds($both));
        $this->sign($both, $r)->assertForbidden()->assertJsonPath('code', 'ALREADY_SIGNED');
        $this->sign($this->fm, $r)->assertOk()->assertJsonPath('request.status', 'approved');
    }

    // ── escalation ──────────────────────────────────────────────────────────

    public function test_when_only_the_maker_holds_a_band_it_escalates_to_the_next(): void
    {
        // The only procurement manager raises a 50,000 PO: nobody else can
        // sign the procurement band, so finance signs in its place.
        $this->pm->syncRoles([]);
        $soloPm = $this->user('procurement_manager');
        $po = $this->submitPo($soloPm, 50000);
        $r  = $this->requestFor($po);
        $this->assertSame(['procurement.approve'], $this->permissionsOf($r));

        Sanctum::actingAs($this->fm);
        $item = collect($this->getJson('/api/v1/admin/approvals/inbox')->json('data'))->firstWhere('id', $r->id);
        $this->assertNotNull($item, 'escalated to finance');
        $this->assertTrue($item['escalated']);
        $this->assertSame('approvals.finance_sign', $item['awaiting']['permission']);

        $this->sign($this->fm, $r)->assertOk()->assertJsonPath('request.status', 'approved');
        $sig = DB::table('approval_signatures')->where('approval_request_id', $r->id)->first();
        $this->assertSame([1, 2], json_decode($sig->covers, true));
        $this->assertDatabaseHas('activity_log', ['event' => 'approval_approved', 'causer_id' => $this->fm->id]);
    }

    public function test_a_lone_finance_manager_escalates_to_the_super_admin(): void
    {
        // 300,000: PM then finance; the only finance manager is the second signer's
        // band — but he is the maker. The finance band escalates to the owner.
        $this->fm->syncRoles([]);
        $soloFm = $this->user('finance_manager', 'procurement_officer');
        $po = $this->submitPo($soloFm, 300000);
        $r  = $this->requestFor($po);

        $this->sign($this->pm, $r)->assertOk();
        $this->assertContains($r->id, $this->inboxIds($this->owner));
        $this->sign($this->owner, $r)->assertOk()->assertJsonPath('request.status', 'approved');
    }

    // ── anti-splitting ──────────────────────────────────────────────────────

    public function test_three_40k_pos_to_one_supplier_in_24h_reach_the_finance_band(): void
    {
        $supplier = $this->supplierId();
        $first  = $this->requestFor($this->submitPo($this->officer, 40000, 'KES', $supplier));
        $second = $this->requestFor($this->submitPo($this->officer, 40000, 'KES', $supplier));
        $third  = $this->requestFor($this->submitPo($this->officer, 40000, 'KES', $supplier));

        $this->assertSame(['procurement.approve'], $this->permissionsOf($first));
        $this->assertSame(['procurement.approve'], $this->permissionsOf($second));
        $this->assertSame(['procurement.approve', 'approvals.finance_sign'], $this->permissionsOf($third));
        $this->assertEquals(120000, (float) $third->basis_kes);
        $this->assertEquals(40000, (float) $third->amount_kes);

        // Another maker, or another supplier, starts their own total.
        $this->assertSame(['procurement.approve'], $this->permissionsOf($this->requestFor($this->submitPo($this->user('procurement_officer'), 40000, 'KES', $supplier))));
        $this->assertSame(['procurement.approve'], $this->permissionsOf($this->requestFor($this->submitPo($this->officer, 40000))));

        // A day later the window has moved on.
        $this->travel(25)->hours();
        $this->assertSame(['procurement.approve'], $this->permissionsOf($this->requestFor($this->submitPo($this->officer, 40000, 'KES', $supplier))));
    }

    // ── KES at the reporting rate ───────────────────────────────────────────

    public function test_a_foreign_po_is_banded_at_the_reporting_rate_and_a_missing_rate_needs_every_band(): void
    {
        // USD 1,000 at the reporting rate of 128 = KES 128,000: PM + finance.
        // (The pricing rate, 100, would have kept it under the PM band.)
        $usd = $this->requestFor($this->submitPo($this->officer, 1000, 'USD'));
        $this->assertEquals(128000, (float) $usd->amount_kes);
        $this->assertSame(['procurement.approve', 'approvals.finance_sign'], $this->permissionsOf($usd));

        // EUR has no reporting rate: never guessed — every band.
        DB::table('currencies')->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€', 'exchange_rate' => 0.009, 'reporting_rate_to_kes' => null, 'created_at' => now(), 'updated_at' => now()]);
        ReportingCurrency::forget();
        $eur = $this->requestFor($this->submitPo($this->officer, 10, 'EUR'));
        $this->assertNull($eur->amount_kes);
        $this->assertTrue($eur->value_unknown);
        $this->assertSame(['procurement.approve', 'approvals.finance_sign', 'approvals.super_sign'], $this->permissionsOf($eur));
    }

    // ── expiry ──────────────────────────────────────────────────────────────

    public function test_a_request_left_72_hours_goes_back_to_the_maker_and_is_never_approved(): void
    {
        $po = $this->submitPo($this->officer, 300000);
        $r  = $this->requestFor($po);
        $this->sign($this->pm, $r)->assertOk();

        $this->travel(73)->hours();
        Artisan::call('approvals:expire');

        $this->assertSame('expired', $r->fresh()->status);
        $po->refresh();
        $this->assertSame('draft', $po->status, 'back to the maker');
        $this->assertNull($po->approved_by);
        $this->assertNotContains($r->id, $this->inboxIds($this->fm));
        $this->sign($this->fm, $r)->assertStatus(409);
        $this->assertDatabaseHas('activity_log', ['event' => 'approval_expired']);

        // Nor can the record-level endpoint adopt it again.
        Sanctum::actingAs($this->pm);
        $this->postJson("/api/v1/admin/purchase-orders/{$po->id}/approve")->assertStatus(422);

        // The maker resubmits: a new version, linked to the expired one, from the first band.
        Sanctum::actingAs($this->officer);
        $this->postJson("/api/v1/admin/purchase-orders/{$po->id}/submit")->assertOk();
        $v2 = $this->requestFor($po);
        $this->assertSame(2, $v2->version);
        $this->assertSame($r->id, $v2->supersedes_id);
        $this->assertSame(1, $v2->current_band);
    }

    public function test_the_expiry_command_is_scheduled(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $this->assertTrue($events->contains(fn ($e) => str_contains((string) $e->command, 'approvals:expire')));
    }

    // ── rejection and resubmission ──────────────────────────────────────────

    public function test_a_rejection_returns_the_po_and_it_comes_back_only_as_a_new_version(): void
    {
        $po = $this->submitPo($this->officer, 300000);
        $r  = $this->requestFor($po);
        $this->sign($this->pm, $r)->assertOk();

        Sanctum::actingAs($this->fm);
        $this->postJson("/api/v1/admin/approvals/{$r->id}/reject", ['approvable_id' => $po->id, 'version' => 1])
            ->assertStatus(422);   // a reason is required
        $this->postJson("/api/v1/admin/approvals/{$r->id}/reject", ['approvable_id' => $po->id, 'version' => 1, 'reason' => 'Price is above the quote'])
            ->assertOk()->assertJsonPath('request.status', 'rejected');
        $this->assertSame('draft', $po->fresh()->status);

        // Only the maker resubmits.
        Sanctum::actingAs($this->pm);
        $this->postJson("/api/v1/admin/approvals/{$r->id}/resubmit")->assertForbidden();

        Sanctum::actingAs($this->officer);
        $mine = collect($this->getJson('/api/v1/admin/approvals/mine')->assertOk()->json('data'))->firstWhere('id', $r->id);
        $this->assertSame('rejected', $mine['status']);
        $this->assertSame('Price is above the quote', $mine['rejected_reason']);
        $this->assertTrue($mine['can_resubmit']);
        $this->assertCount(2, $mine['signatures']);

        $new = $this->postJson("/api/v1/admin/approvals/{$r->id}/resubmit")->assertCreated()->json('request');
        $this->assertSame(2, $new['version']);
        $this->assertSame($r->id, $new['supersedes_id']);
        $this->assertSame('pending_approval', $po->fresh()->status);
        $this->postJson("/api/v1/admin/approvals/{$r->id}/resubmit")->assertStatus(409);   // once
    }

    // ── binding to record id + version + content ────────────────────────────

    public function test_an_approval_is_bound_to_the_record_and_version(): void
    {
        $po    = $this->submitPo($this->officer, 600);
        $other = $this->submitPo($this->officer, 600);
        $r     = $this->requestFor($po);

        $this->sign($this->pm, $r, ['approvable_id' => $other->id])->assertStatus(422)->assertJsonPath('code', 'APPROVAL_MISMATCH');
        $this->sign($this->pm, $r, ['version' => 2])->assertStatus(422)->assertJsonPath('code', 'APPROVAL_VERSION_MISMATCH');
        Sanctum::actingAs($this->pm);
        $this->postJson("/api/v1/admin/approvals/{$r->id}/sign", [])->assertStatus(422);   // both are required

        $this->assertSame('pending_approval', $po->fresh()->status);
        $this->assertDatabaseHas('activity_log', ['event' => 'approval_binding_mismatch', 'causer_id' => $this->pm->id]);
    }

    public function test_a_record_changed_after_submission_cannot_be_signed(): void
    {
        $po = $this->submitPo($this->officer, 600);
        $r  = $this->requestFor($po);
        DB::table('purchase_order_items')->where('purchase_order_id', $po->id)->update(['unit_price' => 900000]);

        $this->sign($this->pm, $r)->assertStatus(422)->assertJsonPath('code', 'APPROVAL_STALE');
        $this->assertSame('pending_approval', $po->fresh()->status);
    }

    public function test_editing_a_pending_po_amount_withdraws_its_approval_and_returns_it_to_draft(): void
    {
        $po = $this->submitPo($this->officer, 600);
        $r  = $this->requestFor($po);

        Sanctum::actingAs($this->officer);
        $this->putJson("/api/v1/admin/purchase-orders/{$po->id}", ['shipping_cost' => 999000])->assertOk();

        $this->assertSame('cancelled', $r->fresh()->status);
        $this->assertSame('draft', $po->fresh()->status);
    }

    // ── the record-level endpoints sign through the engine ──────────────────

    public function test_the_po_approve_endpoint_signs_one_band_and_says_what_is_still_needed(): void
    {
        $po = $this->submitPo($this->officer, 300000);

        Sanctum::actingAs($this->pm);
        $this->postJson("/api/v1/admin/purchase-orders/{$po->id}/approve")
            ->assertOk()
            ->assertJsonPath('approval.status', 'pending')
            ->assertJsonPath('approval.awaiting.permission', 'approvals.finance_sign');
        $this->assertSame('pending_approval', $po->fresh()->status);
    }

    public function test_a_po_waiting_from_before_the_engine_is_adopted_on_first_signature(): void
    {
        $po = PurchaseOrder::create([
            'supplier_id' => $this->supplierId(), 'order_date' => now()->toDateString(), 'status' => 'pending_approval',
            'currency_code' => 'KES', 'subtotal' => 600, 'total_amount' => 600, 'created_by' => $this->officer->id,
        ]);
        $this->assertSame(0, ApprovalRequest::count());

        Sanctum::actingAs($this->pm);
        $this->postJson("/api/v1/admin/purchase-orders/{$po->id}/approve")->assertOk();
        $this->assertSame('approved', $po->fresh()->status);
        $this->assertSame($this->officer->id, (int) $this->requestFor($po)->maker_id);
    }

    public function test_the_adopt_command_brings_waiting_records_into_the_inbox(): void
    {
        $po = PurchaseOrder::create([
            'supplier_id' => $this->supplierId(), 'order_date' => now()->toDateString(), 'status' => 'pending_approval',
            'currency_code' => 'KES', 'subtotal' => 600, 'total_amount' => 600, 'created_by' => $this->officer->id,
        ]);
        Artisan::call('approvals:adopt-pending');
        $this->assertContains($this->requestFor($po)->id, $this->inboxIds($this->pm));

        Artisan::call('approvals:adopt-pending');   // idempotent
        $this->assertSame(1, ApprovalRequest::where('approvable_id', $po->id)->count());
    }

    // ── thresholds: configuration, super-admin only, audited ────────────────

    public function test_only_the_super_admin_changes_a_threshold_and_it_is_audited(): void
    {
        $bands = ['bands' => [
            ['up_to_kes' => 50000,  'approver_permission' => 'procurement.approve'],
            ['up_to_kes' => 500000, 'approver_permission' => 'approvals.finance_sign'],
            ['up_to_kes' => null,   'approver_permission' => 'approvals.super_sign'],
        ]];

        foreach ([$this->fm, $this->pm, $this->user('admin'), $this->user('system_admin')] as $notOwner) {
            Sanctum::actingAs($notOwner);
            $this->putJson('/api/v1/admin/approvals/thresholds/purchase_order', $bands)->assertForbidden();
        }

        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/admin/approvals/thresholds/purchase_order', ['bands' => [
            ['up_to_kes' => 50000, 'approver_permission' => 'no.such_permission'],
            ['up_to_kes' => null,  'approver_permission' => 'approvals.super_sign'],
        ]])->assertStatus(422);
        $this->putJson('/api/v1/admin/approvals/thresholds/purchase_order', $bands)->assertOk();

        $this->assertDatabaseHas('activity_log', ['event' => 'approval_thresholds_changed', 'causer_id' => $this->owner->id]);
        $this->assertSame(6, ApprovalThreshold::where('event', 'purchase_order')->count(), 'the old set is kept, not edited');

        // In force once its moment has come: a 60,000 PO now needs finance too.
        $this->travel(2)->seconds();
        $r = $this->requestFor($this->submitPo($this->officer, 60000));
        $this->assertSame(['procurement.approve', 'approvals.finance_sign'], $this->permissionsOf($r));

        Sanctum::actingAs($this->fm);
        $this->getJson('/api/v1/admin/approvals/thresholds')->assertOk()
            ->assertJsonFragment(['event' => 'purchase_order']);
        Sanctum::actingAs($this->user('pos_clerk'));
        $this->getJson('/api/v1/admin/approvals/thresholds')->assertForbidden();
    }

    public function test_a_threshold_set_for_later_does_nothing_until_then_and_never_backdates(): void
    {
        Sanctum::actingAs($this->owner);
        $later = now()->addDay()->toIso8601String();
        $this->putJson('/api/v1/admin/approvals/thresholds/purchase_order', [
            'effective_from' => $later,
            'bands' => [
                ['up_to_kes' => 1000, 'approver_permission' => 'procurement.approve'],
                ['up_to_kes' => null, 'approver_permission' => 'approvals.finance_sign'],
            ],
        ])->assertOk();
        $this->putJson('/api/v1/admin/approvals/thresholds/purchase_order', [
            'effective_from' => now()->subDay()->toIso8601String(),
            'bands' => [['up_to_kes' => null, 'approver_permission' => 'procurement.approve']],
        ])->assertStatus(422);

        $this->assertSame(['procurement.approve'], $this->permissionsOf($this->requestFor($this->submitPo($this->officer, 5000))));
        $this->travel(25)->hours();
        $this->assertSame(['procurement.approve', 'approvals.finance_sign'], $this->permissionsOf($this->requestFor($this->submitPo($this->officer, 5000))));
    }

    public function test_a_request_keeps_the_bands_it_was_submitted_under(): void
    {
        $r = $this->requestFor($this->submitPo($this->officer, 300000));

        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/admin/approvals/thresholds/purchase_order', ['bands' => [
            ['up_to_kes' => null, 'approver_permission' => 'procurement.approve'],
        ]])->assertOk();

        $this->sign($this->pm, $r)->assertOk()->assertJsonPath('request.status', 'pending');
        $this->assertSame(['procurement.approve', 'approvals.finance_sign'], $this->permissionsOf($r));
    }
}
