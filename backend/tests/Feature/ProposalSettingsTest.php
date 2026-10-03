<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\ChangeProposal;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\StepsUp;
use Tests\TestCase;

/**
 * Phase 3C — proposals on finance settings: tax rates, the reporting and the
 * customer pricing exchange rates, payment settlement. Finance proposes and the
 * super admin signs (the pricing rate: admin proposes, finance signs). Tax,
 * reporting FX and settlement are effective-dated and never retroactive. A
 * super admin's own change waits for the other super admin. Plus: the 3C
 * thresholds are configuration the owner alone changes, and the permission
 * migration gives and takes back exactly its grants.
 */
class ProposalSettingsTest extends TestCase
{
    use RefreshDatabase, StepsUp;

    private User $admin;
    private User $fm;
    private User $owner;
    private User $owner2;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        ReportingCurrency::forget();

        $this->admin  = $this->user('admin');
        $this->fm     = $this->user('finance_manager');
        $this->owner  = $this->user('super_admin');
        $this->owner2 = $this->user('super_admin');

        // Tax-rate, currency-rate and payment-method edits are step-up routes
        // (Phase 4C) whether they apply directly or as a proposal (Phase 3C);
        // every actor here has just re-confirmed who they are.
        foreach ([$this->admin, $this->fm, $this->owner, $this->owner2] as $u) {
            $this->stepUp($u);
        }
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

    private function taxRate(float $rate = 16): int
    {
        return DB::table('tax_rates')->insertGetId([
            'name' => 'VAT', 'code' => 'VAT_' . uniqid(), 'rate' => $rate, 'tax_type' => 'percentage', 'type' => 'percentage',
            'applies_to' => 'all', 'is_active' => true, 'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function usd(): int
    {
        return (int) DB::table('currencies')->where('code', 'USD')->value('id');
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

    private function rate(int $id): float
    {
        return (float) DB::table('tax_rates')->where('id', $id)->value('rate');
    }

    // ── tax rates ────────────────────────────────────────────────────────────

    public function test_finance_proposes_a_dated_tax_change_the_super_admin_signs_and_it_takes_effect_on_its_date(): void
    {
        $id   = $this->taxRate(16);
        $from = now()->addDays(2)->startOfMinute();

        Sanctum::actingAs($this->fm);
        $this->getJson('/api/v1/admin/tax-rates')->assertOk();   // finance can read the screen now
        $this->putJson("/api/v1/admin/tax-rates/{$id}", ['rate' => 18, 'effective_from' => $from->toIso8601String()])
            ->assertStatus(202)->assertJsonPath('proposal.request.needs', ['Super admin']);
        $this->assertSame(16.0, $this->rate($id), 'waiting: 16% stands');

        $p = $this->latest('tax_rate_change');
        $r = $this->requestOf($p);
        $this->assertSame(['approvals.super_sign'], array_column($r->bands, 'permission'));
        $this->sign($this->fm, $r)->assertForbidden();

        $this->sign($this->owner, $r)->assertOk()->assertJsonPath('request.status', 'approved');
        $this->assertSame(ChangeProposal::SCHEDULED, $p->fresh()->status);
        $this->assertSame(16.0, $this->rate($id), 'signed for a later date: 16% still stands — never early');

        $this->travel(1)->days();
        Artisan::call('proposals:apply-due');
        $this->assertSame(16.0, $this->rate($id), 'a day before its date: unchanged');

        $this->travelTo($from->copy()->addMinute());
        Artisan::call('proposals:apply-due');
        $this->assertSame(18.0, $this->rate($id), 'on its date: 16% → 18%');
        $p->refresh();
        $this->assertSame(ChangeProposal::APPLIED, $p->status);
        $this->assertTrue($p->applied_at->gte($from), 'applied at or after effective_from, never before');
        $this->assertSame($this->owner->id, (int) $p->applied_by);
    }

    public function test_a_tax_change_is_never_retroactive(): void
    {
        $id = $this->taxRate(16);
        Sanctum::actingAs($this->fm);

        $this->putJson("/api/v1/admin/tax-rates/{$id}", ['rate' => 14, 'effective_from' => now()->subDay()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors('effective_from');
        $this->assertSame(0, ChangeProposal::count());

        // Signed after its date has passed: it applies when signed, not back-dated.
        $this->putJson("/api/v1/admin/tax-rates/{$id}", ['rate' => 14, 'effective_from' => now()->addHour()->toIso8601String()])->assertStatus(202);
        $p = $this->latest('tax_rate_change');
        $this->travel(3)->hours();
        $this->sign($this->owner, $this->requestOf($p))->assertOk();
        $p->refresh();
        $this->assertSame(ChangeProposal::APPLIED, $p->status);
        $this->assertSame(14.0, $this->rate($id));
        $this->assertTrue($p->applied_at->gt($p->effective_from), 'took effect at signing, after the date it named');
    }

    public function test_the_super_admins_own_tax_change_waits_for_the_other_super_admin(): void
    {
        $id = $this->taxRate(16);
        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/admin/tax-rates/{$id}", ['rate' => 17])->assertStatus(202);
        $r = $this->requestOf($this->latest('tax_rate_change'));
        $this->assertSame(16.0, $this->rate($id));

        $this->sign($this->owner, $r)->assertForbidden()->assertJsonPath('code', 'SELF_APPROVAL');
        $this->sign($this->fm, $r)->assertForbidden();
        $this->sign($this->owner2, $r)->assertOk()->assertJsonPath('request.status', 'approved');
        $this->assertSame(17.0, $this->rate($id));
    }

    public function test_with_one_super_admin_his_own_tax_change_waits_and_nobody_else_can_sign_it(): void
    {
        $this->owner2->update(['status' => 'inactive']);
        $id = $this->taxRate(16);
        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/admin/tax-rates/{$id}", ['rate' => 17])->assertStatus(202);
        $r = $this->requestOf($this->latest('tax_rate_change'));

        Sanctum::actingAs($this->owner);
        $this->assertNotContains($r->id, collect($this->getJson('/api/v1/admin/approvals/inbox')->json('data'))->pluck('id')->all());
        $this->sign($this->fm, $r)->assertForbidden();
        $this->assertSame(16.0, $this->rate($id));
    }

    public function test_finance_cannot_edit_a_tax_rates_other_details_and_a_toggle_is_a_proposal(): void
    {
        $id = $this->taxRate(16);
        Sanctum::actingAs($this->fm);

        $this->putJson("/api/v1/admin/tax-rates/{$id}", ['name' => 'Renamed', 'rate' => 16])
            ->assertForbidden()->assertJsonPath('code', 'NOT_A_SETTINGS_EDITOR');
        // Resending the unchanged name with a rate change is fine (forms resend every field).
        $this->putJson("/api/v1/admin/tax-rates/{$id}", ['name' => 'VAT', 'rate' => 18])->assertStatus(202);
        $this->postJson("/api/v1/admin/proposals/{$this->latest('tax_rate_change')->id}/withdraw")->assertOk();

        $this->putJson("/api/v1/admin/tax-rates/{$id}/toggle")->assertStatus(202);
        $this->assertTrue((bool) DB::table('tax_rates')->where('id', $id)->value('is_active'), 'still active until signed');
        $this->sign($this->owner, $this->requestOf($this->latest('tax_rate_change')))->assertOk();
        $this->assertFalse((bool) DB::table('tax_rates')->where('id', $id)->value('is_active'));
    }

    public function test_a_rejected_tax_change_leaves_the_rate(): void
    {
        $id = $this->taxRate(16);
        Sanctum::actingAs($this->fm);
        $this->putJson("/api/v1/admin/tax-rates/{$id}", ['rate' => 20])->assertStatus(202);
        $r = $this->requestOf($this->latest('tax_rate_change'));

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/approvals/{$r->id}/reject", ['approvable_id' => $r->approvable_id, 'version' => $r->version, 'reason' => 'Not this year'])->assertOk();
        $this->assertSame(16.0, $this->rate($id));
        $this->assertSame(ChangeProposal::REJECTED, $this->latest('tax_rate_change')->status);
    }

    // ── exchange rates ───────────────────────────────────────────────────────

    public function test_finance_proposes_a_reporting_rate_the_super_admin_signs(): void
    {
        $id = $this->usd();
        $this->assertSame(128.0, ReportingCurrency::rates()['USD']);

        Sanctum::actingAs($this->fm);
        $this->getJson('/api/v1/admin/currencies-management')->assertOk();
        $this->putJson("/api/v1/admin/currencies-management/{$id}", ['reporting_rate_to_kes' => 130])->assertStatus(202);
        $this->assertSame(128.0, ReportingCurrency::rates()['USD'], 'waiting: reports still at 128');

        $this->sign($this->owner, $this->requestOf($this->latest('reporting_fx_change')))->assertOk();
        $this->assertSame(130.0, ReportingCurrency::rates()['USD'], 'signed: 128 → 130, cache busted');
        $this->assertSame(0.01, (float) DB::table('currencies')->where('id', $id)->value('exchange_rate'), 'pricing untouched');
    }

    public function test_a_reporting_rate_change_left_unsigned_expires_and_changes_nothing(): void
    {
        $id = $this->usd();
        Sanctum::actingAs($this->fm);
        $this->putJson("/api/v1/admin/currencies-management/{$id}", ['reporting_rate_to_kes' => 140])->assertStatus(202);

        $this->travel(73)->hours();
        Artisan::call('approvals:expire');
        $this->assertSame(ChangeProposal::EXPIRED, $this->latest('reporting_fx_change')->status);
        ReportingCurrency::forget();
        $this->assertSame(128.0, ReportingCurrency::rates()['USD']);
    }

    public function test_admin_proposes_a_customer_pricing_rate_finance_signs(): void
    {
        $id = $this->usd();

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/admin/currencies-management/{$id}/rates", ['exchange_rate' => 0.0095])
            ->assertStatus(202)->assertJsonPath('proposals.0.request.needs', ['Finance']);
        $this->assertSame(0.01, (float) DB::table('currencies')->where('id', $id)->value('exchange_rate'));
        $r = $this->requestOf($this->latest('customer_pricing_fx_change'));

        $this->sign($this->admin, $r)->assertForbidden();
        $this->sign($this->fm, $r)->assertOk();
        $this->assertSame(0.0095, (float) DB::table('currencies')->where('id', $id)->value('exchange_rate'));
        $this->assertSame(128.0, ReportingCurrency::rates()['USD'], 'the reporting rate is a different column');
    }

    public function test_each_rate_has_its_own_maker(): void
    {
        $id = $this->usd();

        Sanctum::actingAs($this->fm);
        $this->putJson("/api/v1/admin/currencies-management/{$id}/rates", ['exchange_rate' => 0.009])
            ->assertForbidden()->assertJsonPath('code', 'NOT_A_MAKER');

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/admin/currencies-management/{$id}", ['reporting_rate_to_kes' => 131])
            ->assertForbidden()->assertJsonPath('code', 'NOT_A_MAKER');
        $this->putJson("/api/v1/admin/currencies-management/{$id}", ['name' => 'Greenback'])
            ->assertForbidden()->assertJsonPath('code', 'NOT_A_SETTINGS_EDITOR');
        $this->assertSame(0, ChangeProposal::count());
    }

    // ── payment settlement ───────────────────────────────────────────────────

    public function test_turning_payment_review_off_needs_the_super_admin_and_is_dated(): void
    {
        $id = DB::table('payment_methods')->insertGetId([
            'code' => 'bank_' . uniqid(), 'name' => 'Bank transfer', 'type' => 'bank_transfer',
            'is_active' => true, 'requires_approval' => true, 'sort_order' => 9, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $from = now()->addDay()->startOfMinute();

        Sanctum::actingAs($this->fm);
        $this->putJson("/api/v1/admin/payment-methods-management/{$id}", ['requires_approval' => false, 'effective_from' => $from->toIso8601String()])
            ->assertStatus(202);
        $this->assertTrue((bool) DB::table('payment_methods')->where('id', $id)->value('requires_approval'));

        $this->sign($this->owner, $this->requestOf($this->latest('payment_settlement_change')))->assertOk();
        $this->assertTrue((bool) DB::table('payment_methods')->where('id', $id)->value('requires_approval'), 'scheduled, not yet');

        $this->travelTo($from->copy()->addSecond());
        Artisan::call('proposals:apply-due');
        $this->assertFalse((bool) DB::table('payment_methods')->where('id', $id)->value('requires_approval'));
    }

    // ── thresholds ───────────────────────────────────────────────────────────

    public function test_the_3c_thresholds_are_configuration_only_the_owner_changes(): void
    {
        Sanctum::actingAs($this->fm);
        $events = collect($this->getJson('/api/v1/admin/proposals/thresholds')->assertOk()->json('data'))->keyBy('event');
        $this->assertEquals(10, $events['selling_price_change_direct']['bands'][0]['up_to_kes']);
        $this->assertSame('percent', $events['selling_price_change']['unit']);
        $this->assertEquals(200000, $events['customer_credit']['bands'][0]['up_to_kes']);

        $body = ['bands' => [['up_to_kes' => 15, 'approver_permission' => 'products.edit']]];
        $this->putJson('/api/v1/admin/proposals/thresholds/selling_price_change_direct', $body)->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/admin/proposals/thresholds/selling_price_change_direct', $body)->assertOk();
        $this->putJson('/api/v1/admin/proposals/thresholds/selling_price_change_direct', $body + ['effective_from' => now()->subDay()->toIso8601String()])
            ->assertStatus(422);
        $this->assertTrue(DB::table('activity_log')->where('event', 'approval_thresholds_changed')->exists());

        // The new figure governs: a 12% change now goes straight through.
        $product = Product::factory()->create();
        $row = ProductPrice::create(['product_id' => $product->id, 'currency_code' => 'KES', 'regular_price' => 1000, 'cost_price' => 500]);
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/admin/products/{$product->id}", ['prices' => [['currency_code' => 'KES', 'regular_price' => 1120]]])
            ->assertOk()->assertJsonPath('proposals.0.status', 'applied');
        $this->assertSame(1120.0, (float) $row->fresh()->regular_price);
    }

    // ── the permission migration ─────────────────────────────────────────────

    private const MIGRATION = '2026_10_03_530001_proposal_permissions.php';
    private const NEW = ['products.edit_cost', 'settings.financial_propose', 'settings.pricing_rate_propose'];

    private function holds(string $role, string $permission): bool
    {
        return Role::findByName($role, 'sanctum')->permissions()->where('name', $permission)->exists();
    }

    public function test_sync_grants_the_proposal_keys_to_the_roles_the_plan_names(): void
    {
        $this->assertTrue($this->holds('procurement_manager', 'products.edit_cost'));
        $this->assertTrue($this->holds('admin', 'products.edit_cost'));
        $this->assertTrue($this->holds('admin', 'settings.pricing_rate_propose'));
        $this->assertTrue($this->holds('finance_manager', 'settings.financial_propose'));
        foreach (['outlet_manager', 'pos_clerk', 'accountant', 'procurement_officer', 'system_admin', 'tailor'] as $role) {
            foreach (self::NEW as $p) {
                $this->assertFalse($this->holds($role, $p), "{$role} must not hold {$p}");
            }
        }
        $this->assertFalse($this->holds('admin', 'settings.financial_propose'));
        $this->assertFalse($this->holds('finance_manager', 'products.edit_cost'));
    }

    public function test_the_permission_migration_gives_and_takes_back_exactly_its_grants(): void
    {
        $migration = require database_path('migrations/' . self::MIGRATION);
        // Production before it: none of the three keys.
        $migration->down();
        foreach (self::NEW as $slug) {
            if ($id = DB::table('permissions')->where('name', $slug)->value('id')) {
                DB::table('role_has_permissions')->where('permission_id', $id)->delete();
                DB::table('permissions')->where('id', $id)->delete();
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $before = DB::table('role_has_permissions')->count();

        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($this->holds('procurement_manager', 'products.edit_cost'));
        $this->assertTrue($this->holds('admin', 'settings.pricing_rate_propose'));
        $this->assertTrue($this->holds('finance_manager', 'settings.financial_propose'));
        $this->assertSame($before + 4, DB::table('role_has_permissions')->count());

        // A key someone was given directly survives down() with its permission row.
        $person = User::factory()->create();
        $person->givePermissionTo(Permission::findByName('settings.financial_propose', 'sanctum'));

        $migration->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertSame($before, DB::table('role_has_permissions')->count());
        $this->assertNull(Permission::where('name', 'products.edit_cost')->first());
        $this->assertNotNull(Permission::where('name', 'settings.financial_propose')->first(), 'held directly: kept');
        $this->assertTrue($person->fresh()->hasPermissionTo('settings.financial_propose', 'sanctum'));
    }

    public function test_the_proposals_migration_rolls_back_its_events_and_table(): void
    {
        $migration = require database_path('migrations/2026_10_03_530002_create_change_proposals.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('change_proposals'));
        $this->assertSame(0, DB::table('approval_thresholds')->where('event', 'like', 'selling_price_change%')->count());
        $this->assertTrue(DB::table('approval_thresholds')->where('event', 'purchase_order')->exists(), '3B thresholds untouched');

        $migration->up();
        $this->assertTrue(Schema::hasTable('change_proposals'));
        $this->assertSame(2, DB::table('approval_thresholds')->where('event', 'selling_price_change')->count());
    }
}
