<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\ApprovalSignature;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
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
 * Owner, 2026-11-05: "When you are super admin you don't need someone to
 * approve your entries. Procurement, price change in products etc." — every
 * super admin, every kind of approval. Approved on entry, signed by the
 * maker, and written to the audit trail; everyone else still waits.
 */
class SuperAdminSelfApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        ReportingCurrency::forget();
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

    private function po(?User $by, string $status = 'draft'): PurchaseOrder
    {
        $supplier = DB::table('suppliers')->insertGetId(['code' => 'SUP-' . uniqid(), 'name' => 'Acme', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $po = PurchaseOrder::create([
            'supplier_id' => $supplier, 'outlet_id' => Outlet::factory()->create()->id, 'order_date' => now()->toDateString(),
            'status' => $status, 'currency_code' => 'KES', 'subtotal' => 600, 'total_amount' => 600, 'created_by' => $by?->id,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'item_type' => 'product', 'product_id' => Product::factory()->create()->id,
            'description' => 'Bought-in item', 'quantity' => 3, 'quantity_received' => 0, 'unit_price' => 200, 'total_price' => 600,
        ]);

        return $po;
    }

    private function assertSelfApprovedAudit(User $by): void
    {
        $this->assertTrue(DB::table('activity_log')->where('event', 'self_approved_super_admin')->where('causer_id', $by->id)->exists(),
            'a super admin approving their own entry is written to the audit trail');
    }

    public function test_a_super_admins_own_purchase_order_is_approved_on_submit(): void
    {
        $owner = $this->user('super_admin');
        $po    = $this->po($owner);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/admin/purchase-orders/{$po->id}/submit")->assertOk()
            ->assertJsonPath('message', 'Purchase order approved.');

        $po->refresh();
        $this->assertSame('approved', $po->status);
        $this->assertSame($owner->id, (int) $po->approved_by);

        $r = ApprovalRequest::where('event', 'purchase_order')->sole();
        $this->assertSame(ApprovalRequest::APPROVED, $r->status);
        $sig = ApprovalSignature::where('approval_request_id', $r->id)->sole();
        $this->assertSame($owner->id, (int) $sig->signer_id);
        $this->assertTrue((bool) $sig->owner_override, 'the signature says the maker signed it');
        $this->assertSelfApprovedAudit($owner);
    }

    public function test_anyone_else_still_waits_for_a_second_person(): void
    {
        $officer = $this->user('procurement_officer', 'procurement_manager');
        $po      = $this->po($officer);

        Sanctum::actingAs($officer);
        $this->postJson("/api/v1/admin/purchase-orders/{$po->id}/submit")->assertOk()
            ->assertJsonPath('message', 'Purchase order submitted for approval.');
        $this->assertSame('pending_approval', $po->fresh()->status);

        $this->postJson("/api/v1/admin/purchase-orders/{$po->id}/approve")->assertForbidden()->assertJsonPath('code', 'SELF_APPROVAL');
        $this->assertSame('pending_approval', $po->fresh()->status);
    }

    public function test_a_super_admin_approves_their_own_po_left_waiting_from_before(): void
    {
        $owner = $this->user('super_admin');
        $po    = $this->po($owner, 'pending_approval');

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/admin/purchase-orders/{$po->id}/approve")->assertOk();
        $this->assertSame('approved', $po->fresh()->status);
        $this->assertSelfApprovedAudit($owner);
    }

    public function test_a_super_admins_price_change_applies_at_once_however_large(): void
    {
        $owner = $this->user('super_admin');
        $row   = ProductPrice::create(['product_id' => Product::factory()->create()->id, 'product_variant_id' => null, 'currency_code' => 'KES',
            'regular_price' => 1000, 'sale_price' => null, 'cost_price' => 800]);

        // +25% needs finance from anyone else (ProposalPricingTest); not from a super admin.
        Sanctum::actingAs($owner);
        $this->putJson("/api/v1/admin/products/{$row->product_id}", ['prices' => [['currency_code' => 'KES', 'regular_price' => 1250]]])
            ->assertOk()->assertJsonPath('proposals.0.status', 'applied');

        $this->assertSame(1250.0, (float) $row->fresh()->regular_price, '1,000 → 1,250 at once');
        $this->assertSame(ApprovalRequest::APPROVED, ApprovalRequest::where('event', 'selling_price_change')->sole()->status,
            'still an approval request, signed by them — the history is whole');
        $this->assertSelfApprovedAudit($owner);
    }

    public function test_the_same_price_change_by_an_admin_still_waits_for_finance(): void
    {
        $admin = $this->user('admin');
        $row   = ProductPrice::create(['product_id' => Product::factory()->create()->id, 'product_variant_id' => null, 'currency_code' => 'KES',
            'regular_price' => 1000, 'sale_price' => null, 'cost_price' => 800]);

        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/admin/products/{$row->product_id}", ['prices' => [['currency_code' => 'KES', 'regular_price' => 1250]]])
            ->assertOk()->assertJsonPath('proposals.0.status', 'pending');
        $this->assertSame(1000.0, (float) $row->fresh()->regular_price);
    }

    public function test_a_bank_payment_a_super_admin_records_settles_at_once_and_says_who_approved_it(): void
    {
        $owner = $this->user('super_admin');
        $order = Order::factory()->create(['order_type' => 'online', 'status' => 'pending', 'total_amount' => 5000, 'payment_status' => 'pending', 'currency_code' => 'KES']);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/admin/orders/{$order->id}/payments", ['method' => 'bank_transfer', 'amount' => 2000, 'reference' => 'FT-1'])->assertSuccessful();

        $p = Payment::where('order_id', $order->id)->sole();
        $this->assertSame('paid', $p->status);
        $this->assertSame('approved', $p->approval_status);
        $this->assertSame($owner->id, (int) $p->approved_by);
        $this->assertSame(2000.0, $order->fresh()->totalPaid(), 'it counts as money received');
        $this->assertSelfApprovedAudit($owner);

        // Recorded by anyone else, the same payment waits for review.
        Sanctum::actingAs($this->user('admin'));
        $this->postJson("/api/v1/admin/orders/{$order->id}/payments", ['method' => 'bank_transfer', 'amount' => 1000, 'reference' => 'FT-2'])->assertSuccessful();
        $this->assertSame('pending_review', Payment::where('order_id', $order->id)->where('provider_reference', 'FT-2')->value('approval_status'));
        $this->assertSame(2000.0, $order->fresh()->totalPaid());
    }

    public function test_an_inactive_super_admin_gets_no_pass(): void
    {
        $u = $this->user('super_admin');
        $u->update(['status' => 'inactive']);
        $this->assertFalse(\App\Support\SuperAdminSelfApproval::applies($u->fresh()));
        $this->assertTrue(\App\Support\SuperAdminSelfApproval::applies($this->user('super_admin')));
        $this->assertFalse(\App\Support\SuperAdminSelfApproval::applies($this->user('admin')));
    }
}
