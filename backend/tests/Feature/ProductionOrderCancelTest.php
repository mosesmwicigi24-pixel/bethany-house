<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cancelling a production order that tailors hold stages on. It used to save
 * the cancel and then crash (500, ArgumentCountError from a mis-called
 * notifier), so the manager saw an error and the tailors were never told.
 */
class ProductionOrderCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function userWithRole(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    /** A pending order with Cutting held by one tailor and Stitching by another. */
    private function orderWithTailors(User $cutter, User $stitcher): ProductionOrder
    {
        $po = ProductionOrder::create([
            'order_number' => 'PRD-CXL-' . uniqid(), 'product_id' => Product::factory()->create()->id,
            'status' => 'pending', 'quantity' => 4,
        ]);
        foreach ([[$cutter, 1], [$stitcher, 2]] as [$who, $seq]) {
            $stage = ProductionStage::create(['name' => "Stage {$seq}", 'slug' => "cxl-{$seq}-" . uniqid(), 'sort_order' => $seq, 'is_active' => true]);
            ProductionTask::withoutViewerScope()->create([
                'production_order_id' => $po->id, 'production_stage_id' => $stage->id,
                'assigned_to' => $who->id, 'sequence' => $seq, 'status' => 'pending', 'quantity_done' => 0,
            ]);
        }

        return $po;
    }

    /** @return list<array> the stored notification payloads for a user */
    private function notificationsFor(User $u): array
    {
        return DB::table('notifications')->where('notifiable_id', $u->id)->pluck('data')
            ->map(fn ($d) => json_decode($d, true))->all();
    }

    public function test_cancelling_an_assigned_order_succeeds_and_tells_its_tailors(): void
    {
        [$cutter, $stitcher] = [$this->userWithRole('tailor'), $this->userWithRole('tailor')];
        $manager = $this->userWithRole('admin');
        $po = $this->orderWithTailors($cutter, $stitcher);

        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/admin/production-orders/{$po->id}/cancel", ['reason' => 'Customer changed their mind'])
            ->assertOk()
            ->assertJsonPath('message', 'Production order cancelled.');

        $this->assertSame('cancelled', $po->fresh()->status);

        foreach ([$cutter, $stitcher] as $tailor) {
            $rows = $this->notificationsFor($tailor);
            $this->assertCount(1, $rows, 'each tailor holding a stage is told once');
            $this->assertStringContainsString('cancelled', $rows[0]['title']);
            $this->assertStringContainsString($po->order_number, $rows[0]['body']);
            $this->assertStringContainsString('Customer changed their mind', $rows[0]['body']);
            $this->assertSame("/production/orders/{$po->id}", $rows[0]['action_url']);
        }
        $this->assertSame([], $this->notificationsFor($manager), 'the person who cancelled is not pinged');
    }

    public function test_the_delete_route_takes_the_same_path(): void
    {
        $tailor = $this->userWithRole('tailor');
        $po = $this->orderWithTailors($tailor, $tailor);

        Sanctum::actingAs($this->userWithRole('admin'));
        $this->deleteJson("/api/v1/admin/production-orders/{$po->id}")->assertOk();

        $this->assertSame('cancelled', $po->fresh()->status);
        $this->assertCount(1, $this->notificationsFor($tailor), 'one notice even when one tailor holds both stages');
    }

    public function test_an_order_with_nobody_assigned_still_cancels_quietly(): void
    {
        $po = ProductionOrder::create([
            'order_number' => 'PRD-CXL-EMPTY', 'product_id' => Product::factory()->create()->id,
            'status' => 'pending', 'quantity' => 1,
        ]);

        Sanctum::actingAs($this->userWithRole('admin'));
        $this->postJson("/api/v1/admin/production-orders/{$po->id}/cancel")->assertOk();

        $this->assertSame('cancelled', $po->fresh()->status);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_work_already_under_way_still_cannot_be_cancelled(): void
    {
        $tailor = $this->userWithRole('tailor');
        $po = $this->orderWithTailors($tailor, $tailor);
        $po->update(['status' => 'in_progress']);

        Sanctum::actingAs($this->userWithRole('admin'));
        $this->postJson("/api/v1/admin/production-orders/{$po->id}/cancel")->assertStatus(422);

        $this->assertSame('in_progress', $po->fresh()->status);
        $this->assertSame([], $this->notificationsFor($tailor));
    }
}
