<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\ProductTranslation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 1C, item 2 — a tailor's job card carries the job, not the customer.
 *
 * Owner's field rule for tailors: order reference, garment spec, measurements,
 * materials and quantities (no cost), instructions, deadline, QC, status, and
 * the customer's FIRST NAME. Contacts (phone, email) only for roles holding
 * customers.view; cost figures only for products.view_cost.
 *
 * Before this, every production payload a tailor could open carried the
 * customer's full name, phone and email (the appended customer_contact plus
 * the sales order's snapshot columns), and the detail and task views carried
 * material unit costs.
 */
class ProductionPayloadRedactionTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '0722555111';
    private const EMAIL = 'grace.wanjiku@example.test';
    private const DIRECT_PHONE = '0733444222';

    private ProductionOrder $po;
    private ProductionTask $task;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function actAs(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        return $user->fresh();
    }

    /** A customer job, assigned to $tailor, with a costed BOM and an allocation. */
    private function job(User $tailor): void
    {
        $product = Product::factory()->create();
        ProductTranslation::create(['product_id' => $product->id, 'language_code' => 'en', 'name' => 'Clergy Cassock']);

        $customer = Customer::create([
            'customer_number' => 'C-' . uniqid(),
            'first_name' => 'Grace', 'last_name' => 'Wanjiku',
            'email' => 'grace.direct@example.test', 'phone' => self::DIRECT_PHONE,
        ]);
        $order = Order::factory()->create([
            'customer_first_name' => 'Grace', 'customer_last_name' => 'Wanjiku',
            'customer_phone' => self::PHONE, 'customer_email' => self::EMAIL,
            'customer_id' => $customer->id,
        ]);

        $this->po = ProductionOrder::create([
            'order_number' => 'PRD-RED-0001',
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'customer_order_id' => $order->id,
            'is_customer_order' => true,
            'quantity' => 1,
            'status' => 'in_progress',
            'priority' => 'high',
            'due_date' => now()->addDays(5),
            'measurements' => ['chest' => '102', 'length' => '148'],
            'specifications' => ['fabric' => 'Black wool crepe'],
            'notes' => 'Double-stitch the hem',
        ]);

        $stage = ProductionStage::firstOrCreate(['slug' => 'cutting-red'], ['name' => 'Cutting', 'sort_order' => 1, 'is_active' => true]);
        $this->task = ProductionTask::withoutViewerScope()->create([
            'production_order_id' => $this->po->id,
            'production_stage_id' => $stage->id,
            'assigned_to' => $tailor->id,
            'status' => 'in_progress',
        ]);

        $materialId = DB::table('materials')->insertGetId([
            'code' => 'MAT-RED-1', 'name' => 'Black Wool Crepe', 'unit_of_measure' => 'm',
            'unit_cost' => 987.65, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $bomId = DB::table('bills_of_materials')->insertGetId([
            'product_id' => $product->id, 'version' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('bom_items')->insert([
            'bom_id' => $bomId, 'material_id' => $materialId, 'quantity' => 3.5,
            'unit_of_measure' => 'm', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('material_allocations')->insert([
            'production_order_id' => $this->po->id, 'material_id' => $materialId,
            'quantity_required' => 3.5, 'quantity_allocated' => 3.5,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assertNoContact(string $body, string $where): void
    {
        foreach ([self::PHONE, self::EMAIL, self::DIRECT_PHONE, 'grace.direct@example.test', 'Wanjiku'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "{$where} leaked {$needle}");
        }
    }

    private function assertNoCost(string $body, string $where): void
    {
        foreach (['987.65', 'unit_cost', 'cost_per_unit'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "{$where} leaked {$needle}");
        }
    }

    // ── The tailor ───────────────────────────────────────────────────────────

    public function test_a_tailors_task_list_is_the_job_without_the_customers_contacts(): void
    {
        $tailor = $this->actAs('tailor');
        $this->job($tailor);

        $res  = $this->getJson('/api/v1/tailor/tasks')->assertOk();
        $body = $res->getContent();

        $this->assertNoContact($body, 'tailor/tasks');
        $this->assertNoCost($body, 'tailor/tasks');

        // Everything the tailor works from is still there.
        $order = $res->json('0.production_order');
        $this->assertSame('PRD-RED-0001', $order['order_number']);
        $this->assertSame('Grace', $order['customer_label'], 'first name only');
        $this->assertSame('102', $order['measurements']['chest']);
        $this->assertSame('Black wool crepe', $order['specifications']['fabric']);
        $this->assertSame('Double-stitch the hem', $order['notes']);
        $this->assertNotNull($order['due_date']);
        $this->assertStringContainsString('Black Wool Crepe', $body, 'materials stay');
    }

    public function test_a_tailors_task_detail_carries_materials_without_their_cost(): void
    {
        $tailor = $this->actAs('tailor');
        $this->job($tailor);

        $res  = $this->getJson("/api/v1/tailor/tasks/{$this->task->id}")->assertOk();
        $body = $res->getContent();

        $this->assertNoCost($body, 'tailor/tasks/{id}');
        $this->assertNoContact($body, 'tailor/tasks/{id}');
        $this->assertSame('Black Wool Crepe', $res->json('production_order.material_allocations.0.material.name'));
        $this->assertEquals(3.5, (float) $res->json('production_order.material_allocations.0.quantity_allocated'));
    }

    public function test_the_production_order_detail_a_tailor_opens_is_redacted(): void
    {
        $tailor = $this->actAs('tailor');
        $this->job($tailor);

        $res  = $this->getJson("/api/v1/admin/production-orders/{$this->po->id}")->assertOk();
        $body = $res->getContent();

        $this->assertNoContact($body, 'production-orders/{id}');
        $this->assertNoCost($body, 'production-orders/{id}');

        $this->assertSame('Grace', $res->json('order.customer_label'));
        $this->assertNull($res->json('order.customer_contact'));
        $this->assertSame('PRD-RED-0001', $res->json('order.order_number'));
        $this->assertSame('Black Wool Crepe', $res->json('order.material_requirements.0.material_name'));
        $this->assertEquals(3.5, $res->json('order.material_requirements.0.required'));
    }

    public function test_the_lists_a_tailor_can_reach_are_redacted_too(): void
    {
        $tailor = $this->actAs('tailor');
        $this->job($tailor);

        foreach (['/api/v1/admin/production-orders', '/api/v1/admin/production-tasks'] as $url) {
            $body = $this->getJson($url)->assertOk()->getContent();
            $this->assertStringContainsString('PRD-RED-0001', $body, "{$url} still lists the job");
            $this->assertNoContact($body, $url);
        }

        $sched = $this->getJson('/api/v1/admin/production/schedule')->assertOk();
        $this->assertSame('Grace', $sched->json('upcoming_orders.0.customer_name'));
    }

    // ── Roles that keep what they had ────────────────────────────────────────

    public function test_an_outlet_manager_keeps_the_contact_but_not_the_cost(): void
    {
        $tailor = User::factory()->create();
        $tailor->assignRole(Role::findByName('tailor', 'sanctum'));
        $this->job($tailor);
        $this->actAs('outlet_manager');

        $res  = $this->getJson("/api/v1/admin/production-orders/{$this->po->id}")->assertOk();
        $body = $res->getContent();

        $this->assertSame('Grace Wanjiku', $res->json('order.customer_label'));
        $this->assertSame(self::DIRECT_PHONE, $res->json('order.customer_contact'));
        $this->assertSame(self::PHONE, $res->json('order.customer_order.customer_phone'));
        $this->assertNoCost($body, 'outlet manager production detail');
    }

    public function test_admin_keeps_everything(): void
    {
        $tailor = User::factory()->create();
        $this->job($tailor);
        $this->actAs('admin');

        $res = $this->getJson("/api/v1/admin/production-orders/{$this->po->id}")->assertOk();

        $this->assertSame('Grace Wanjiku', $res->json('order.customer_label'));
        $this->assertSame(self::DIRECT_PHONE, $res->json('order.customer_contact'));
        $this->assertStringContainsString('987.65', $res->getContent(), 'admin still sees the BOM cost');
    }
}
