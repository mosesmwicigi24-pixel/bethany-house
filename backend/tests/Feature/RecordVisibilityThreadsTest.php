<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\InventoryTransfer;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 1C, items 4 and 5 — a thread or a chip is as private as its record.
 *
 * GET/POST /admin/comments and POST /admin/intelligence/entity-previews are
 * open to every staff login and keyed only by a type and an id. Neither asked
 * whether the caller could see the record: a tailor could read (and post into)
 * the discussion on any sales order or purchase order, and a chip preview
 * handed anyone an order's total and customer name by guessing ids.
 *
 * Both now apply the record's own visibility rule (RecordVisibility) and
 * answer 404 / leave the preview out when it fails.
 */
class RecordVisibilityThreadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function actAs(User $user): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    private function thread(string $class, int $id): void
    {
        Comment::create([
            'commentable_type' => $class, 'commentable_id' => $id,
            'user_id' => User::factory()->create()->id,
            'type' => 'comment', 'body' => 'Customer wants it by Friday — call her on 0722000111',
            'is_internal' => true, 'mentions' => [],
        ]);
    }

    private function readThread(string $model, int $id)
    {
        return $this->getJson("/api/v1/admin/comments?model={$model}&id={$id}");
    }

    private function postComment(string $model, int $id)
    {
        return $this->postJson('/api/v1/admin/comments', ['model' => $model, 'id' => $id, 'body' => 'hello']);
    }

    private function productionOrder(): ProductionOrder
    {
        return ProductionOrder::create([
            'order_number' => 'PRD-' . uniqid(), 'product_id' => Product::factory()->create()->id,
            'quantity' => 1, 'status' => 'in_progress',
        ]);
    }

    private function purchaseOrder(): PurchaseOrder
    {
        $supplierId = DB::table('suppliers')->insertGetId([
            'code' => 'SUP-' . uniqid(), 'name' => 'Supplier', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = DB::table('purchase_orders')->insertGetId([
            'po_number' => 'PO-' . uniqid(), 'supplier_id' => $supplierId,
            'order_date' => now()->format('Y-m-d'), 'status' => 'draft',
            'subtotal' => 1, 'total_amount' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return PurchaseOrder::findOrFail($id);
    }

    // ── Comments: orders ─────────────────────────────────────────────────────

    public function test_a_tailor_cannot_read_or_post_on_a_sales_orders_thread(): void
    {
        $order = Order::factory()->create();
        $this->thread(Order::class, $order->id);
        $this->actAs($this->user('tailor'));

        $this->readThread('Order', $order->id)->assertNotFound();
        $this->postComment('Order', $order->id)->assertNotFound();
        $this->assertSame(1, Comment::count(), 'nothing was posted');
    }

    public function test_a_cashier_reads_her_own_sales_thread_but_not_another_cashiers(): void
    {
        $clerk = $this->user('pos_clerk');
        $mine   = Order::factory()->create(['created_by' => $clerk->id]);
        $theirs = Order::factory()->create(['created_by' => User::factory()->create()->id]);
        $this->thread(Order::class, $theirs->id);
        $this->actAs($clerk);

        // Posted into an empty thread: replying to a thread that already has
        // another participant currently 500s on a pre-existing, unrelated bug
        // (NotificationService::commentReply does not exist).
        $this->postComment('Order', $mine->id)->assertCreated();
        $this->readThread('Order', $mine->id)->assertOk()->assertJsonCount(1, 'comments');
        $this->readThread('Order', $theirs->id)->assertNotFound();
        $this->postComment('Order', $theirs->id)->assertNotFound();
    }

    // ── Comments: production ────────────────────────────────────────────────

    public function test_a_tailor_reads_the_thread_on_their_own_job_only(): void
    {
        $tailor = $this->user('tailor');
        $mine   = $this->productionOrder();
        $theirs = $this->productionOrder();
        $stage  = ProductionStage::firstOrCreate(['slug' => 'cut-th'], ['name' => 'Cut', 'sort_order' => 1, 'is_active' => true]);
        ProductionTask::withoutViewerScope()->create([
            'production_order_id' => $mine->id, 'production_stage_id' => $stage->id,
            'assigned_to' => $tailor->id, 'status' => 'pending',
        ]);
        $this->thread(ProductionOrder::class, $theirs->id);
        $this->actAs($tailor);

        $this->postComment('ProductionOrder', $mine->id)->assertCreated();
        $this->readThread('ProductionOrder', $mine->id)->assertOk()->assertJsonCount(1, 'comments');
        $this->readThread('ProductionOrder', $theirs->id)->assertNotFound();
        $this->postComment('ProductionOrder', $theirs->id)->assertNotFound();
    }

    // ── Comments: procurement, inventory, returns ───────────────────────────

    public function test_procurement_threads_need_procurement_view(): void
    {
        $po = $this->purchaseOrder();
        $this->thread(PurchaseOrder::class, $po->id);

        $this->actAs($this->user('outlet_manager'));
        $this->readThread('PurchaseOrder', $po->id)->assertNotFound();

        $this->actAs($this->user('procurement_officer'));
        $this->readThread('PurchaseOrder', $po->id)->assertOk()->assertJsonCount(1, 'comments');
    }

    public function test_transfer_threads_need_inventory_view(): void
    {
        $a = Outlet::factory()->create();
        $b = Outlet::factory()->create();
        $transfer = InventoryTransfer::create([
            'transfer_number' => 'TR-' . uniqid(), 'from_outlet_id' => $a->id, 'to_outlet_id' => $b->id,
            'transfer_date' => now()->toDateString(), 'status' => 'pending',
        ]);
        $this->thread(InventoryTransfer::class, $transfer->id);

        $this->actAs($this->user('tailor'));
        $this->readThread('InventoryTransfer', $transfer->id)->assertNotFound();

        $this->actAs($this->user('procurement_officer'));
        $this->readThread('InventoryTransfer', $transfer->id)->assertOk();
    }

    public function test_return_threads_need_manage_returns(): void
    {
        $order = Order::factory()->create();
        $return = OrderReturn::create(['return_number' => 'RET-' . uniqid(), 'order_id' => $order->id, 'status' => 'requested']);
        $this->thread(OrderReturn::class, $return->id);

        $this->actAs($this->user('pos_clerk'));
        $this->readThread('OrderReturn', $return->id)->assertNotFound();

        $this->actAs($this->user('outlet_manager'));
        $this->readThread('OrderReturn', $return->id)->assertOk();
    }

    public function test_a_missing_record_is_a_404_not_an_empty_thread(): void
    {
        $this->actAs($this->user('admin'));
        $this->readThread('Order', 999999)->assertNotFound();
    }

    // ── Entity previews ─────────────────────────────────────────────────────

    public function test_entity_previews_leave_out_what_the_caller_cannot_open(): void
    {
        $tailor = $this->user('tailor');
        $order  = Order::factory()->create(['customer_first_name' => 'Hidden', 'customer_last_name' => 'Buyer']);
        $mine   = $this->productionOrder();
        $theirs = $this->productionOrder();
        $stage  = ProductionStage::firstOrCreate(['slug' => 'cut-pv'], ['name' => 'Cut', 'sort_order' => 1, 'is_active' => true]);
        ProductionTask::withoutViewerScope()->create([
            'production_order_id' => $mine->id, 'production_stage_id' => $stage->id,
            'assigned_to' => $tailor->id, 'status' => 'pending',
        ]);
        $this->actAs($tailor);

        $res = $this->postJson('/api/v1/admin/intelligence/entity-previews', ['entities' => [
            ['type' => 'order', 'id' => $order->id],
            ['type' => 'production_order', 'id' => $mine->id],
            ['type' => 'production_order', 'id' => $theirs->id],
        ]])->assertOk();

        $previews = $res->json('previews');
        $this->assertArrayNotHasKey("order:{$order->id}", $previews);
        $this->assertArrayHasKey("production_order:{$mine->id}", $previews);
        $this->assertArrayNotHasKey("production_order:{$theirs->id}", $previews);
        $this->assertStringNotContainsString('Hidden', $res->getContent());
    }

    public function test_tagging_search_offers_a_tailor_only_their_own_jobs(): void
    {
        // Sibling of the preview gate: the # picker that produces the chips.
        $tailor = $this->user('tailor');
        $mine   = $this->productionOrder();
        $theirs = $this->productionOrder();
        $stage  = ProductionStage::firstOrCreate(['slug' => 'cut-es'], ['name' => 'Cut', 'sort_order' => 1, 'is_active' => true]);
        ProductionTask::withoutViewerScope()->create([
            'production_order_id' => $mine->id, 'production_stage_id' => $stage->id,
            'assigned_to' => $tailor->id, 'status' => 'pending',
        ]);
        $this->actAs($tailor);

        $ids = collect($this->getJson('/api/v1/admin/channels/entity-search?types=production_order')
            ->assertOk()->json('results'))->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_entity_previews_still_work_for_someone_who_can_see_them(): void
    {
        $clerk = $this->user('pos_clerk');
        $mine   = Order::factory()->create(['created_by' => $clerk->id]);
        $theirs = Order::factory()->create(['created_by' => User::factory()->create()->id]);
        $this->actAs($clerk);

        $previews = $this->postJson('/api/v1/admin/intelligence/entity-previews', ['entities' => [
            ['type' => 'order', 'id' => $mine->id],
            ['type' => 'order', 'id' => $theirs->id],
        ]])->assertOk()->json('previews');

        $this->assertArrayHasKey("order:{$mine->id}", $previews);
        $this->assertArrayNotHasKey("order:{$theirs->id}", $previews, 'own scope applies to chips too');
    }
}
