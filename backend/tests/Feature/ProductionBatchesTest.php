<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderBatch;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Colourway batches inside a production order.
 *
 * 100 white cassocks = 10 blue-trim + 10 green-trim + 80 cream: same body, trim
 * decided at production time. Batch quantities must sum EXACTLY to the order's
 * quantity; piece counting then runs per batch with the same pipeline
 * arithmetic, and the task's own quantity_done becomes the SUM of its batch
 * rows — so gating, completion % and lifecycle keep reading the total.
 */
class ProductionBatchesTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCoordinator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('tailor', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('production.view', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('production.raise_order', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    /** Order of 20 with Cutting → Stitching, both tasks assigned to $user. */
    private function seededOrder(User $user): ProductionOrder
    {
        ProductionStage::create(['name' => 'Cutting',   'slug' => 'cutting-b',   'sort_order' => 1, 'is_active' => true]);
        ProductionStage::create(['name' => 'Stitching', 'slug' => 'stitching-b', 'sort_order' => 2, 'is_active' => true]);

        $product = Product::factory()->create();
        $order   = ProductionOrder::create([
            'order_number' => 'PRD-BATCH-' . $product->id,
            'product_id'   => $product->id,
            'quantity'     => 20,
            'status'       => 'in_progress',
        ]);
        $order->seedTasks();
        $order->tasks()->update(['assigned_to' => $user->id]);

        return $order;
    }

    private function defineBatches(ProductionOrder $order): array
    {
        $this->putJson("/api/v1/admin/production-orders/{$order->id}/batches", [
            'batches' => [
                ['label' => 'Blue trim',  'quantity' => 10, 'attributes' => ['piping' => 'blue']],
                ['label' => 'Green trim', 'quantity' => 10, 'attributes' => ['piping' => 'green']],
            ],
        ])->assertOk();

        return $order->batches()->get()->keyBy('label')->all();
    }

    // ── Defining batches ─────────────────────────────────────────────────────

    public function test_batch_quantities_must_sum_exactly_to_the_order(): void
    {
        $user  = $this->actingAsCoordinator();
        $order = $this->seededOrder($user);

        // 10 + 5 = 15 ≠ 20 — the spreadsheet error this feature exists to kill.
        $this->putJson("/api/v1/admin/production-orders/{$order->id}/batches", [
            'batches' => [
                ['label' => 'Blue',  'quantity' => 10],
                ['label' => 'Green', 'quantity' => 5],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, $order->batches()->count());
    }

    public function test_batches_save_when_the_arithmetic_holds(): void
    {
        $user  = $this->actingAsCoordinator();
        $order = $this->seededOrder($user);

        $this->defineBatches($order);

        $this->assertSame(2, $order->batches()->count());
        $this->assertSame(20, (int) $order->batches()->sum('quantity'));
    }

    public function test_batches_cannot_be_redefined_once_counting_started(): void
    {
        $user  = $this->actingAsCoordinator();
        $order = $this->seededOrder($user);
        $batches = $this->defineBatches($order);

        $cutting = $order->tasks()->orderBy('sequence')->first();
        $this->postJson("/api/v1/tailor/tasks/{$cutting->id}/progress", [
            'quantity_done' => 3, 'batch_id' => $batches['Blue trim']->id,
        ])->assertOk();

        // Re-slicing a half-counted order would orphan real work.
        $this->putJson("/api/v1/admin/production-orders/{$order->id}/batches", [
            'batches' => [['label' => 'Everything', 'quantity' => 20]],
        ])->assertStatus(422);
    }

    // ── Counting per batch ───────────────────────────────────────────────────

    public function test_a_batched_order_requires_a_batch_when_counting(): void
    {
        $user  = $this->actingAsCoordinator();
        $order = $this->seededOrder($user);
        $this->defineBatches($order);

        $cutting = $order->tasks()->orderBy('sequence')->first();
        $this->postJson("/api/v1/tailor/tasks/{$cutting->id}/progress", ['quantity_done' => 3])
            ->assertStatus(422);
    }

    public function test_the_pipeline_ceiling_runs_within_the_batch(): void
    {
        $user  = $this->actingAsCoordinator();
        $order = $this->seededOrder($user);
        $batches = $this->defineBatches($order);
        [$cutting, $stitching] = $order->tasks()->orderBy('sequence')->get()->all();

        // Cut 10 GREEN but only 5 BLUE.
        $this->postJson("/api/v1/tailor/tasks/{$cutting->id}/progress", ['quantity_done' => 10, 'batch_id' => $batches['Green trim']->id])->assertOk();
        $this->postJson("/api/v1/tailor/tasks/{$cutting->id}/progress", ['quantity_done' => 5,  'batch_id' => $batches['Blue trim']->id])->assertOk();

        // Green's surplus must NOT let blue overshoot: stitching blue is capped
        // at the 5 blue pieces that were actually cut.
        $this->postJson("/api/v1/tailor/tasks/{$stitching->id}/progress", ['quantity_done' => 6, 'batch_id' => $batches['Blue trim']->id])
            ->assertStatus(422);
        $this->postJson("/api/v1/tailor/tasks/{$stitching->id}/progress", ['quantity_done' => 5, 'batch_id' => $batches['Blue trim']->id])
            ->assertOk();
    }

    public function test_the_task_total_is_the_sum_of_its_batches(): void
    {
        $user  = $this->actingAsCoordinator();
        $order = $this->seededOrder($user);
        $batches = $this->defineBatches($order);
        $cutting = $order->tasks()->orderBy('sequence')->first();

        $this->postJson("/api/v1/tailor/tasks/{$cutting->id}/progress", ['quantity_done' => 7, 'batch_id' => $batches['Blue trim']->id])->assertOk();
        $this->postJson("/api/v1/tailor/tasks/{$cutting->id}/progress", ['quantity_done' => 4, 'batch_id' => $batches['Green trim']->id])->assertOk();

        // Derived, never typed: 7 blue + 4 green = 11 of 20.
        $this->assertSame(11, (int) $cutting->fresh()->quantity_done);
    }

    public function test_the_order_completes_only_when_every_batch_has_passed_every_stage(): void
    {
        $user  = $this->actingAsCoordinator();
        $order = $this->seededOrder($user);
        $batches = $this->defineBatches($order);
        [$cutting, $stitching] = $order->tasks()->orderBy('sequence')->get()->all();

        foreach (['Blue trim', 'Green trim'] as $label) {
            $this->postJson("/api/v1/tailor/tasks/{$cutting->id}/progress",   ['quantity_done' => 10, 'batch_id' => $batches[$label]->id])->assertOk();
            $this->postJson("/api/v1/tailor/tasks/{$stitching->id}/progress", ['quantity_done' => 10, 'batch_id' => $batches[$label]->id])->assertOk();
        }

        // 10 blue done + 10 green done = a completed order of 20.
        $this->assertSame('completed', $cutting->fresh()->status);
        $this->assertSame('completed', $stitching->fresh()->status);
        $this->assertSame('qc_pending', $order->fresh()->status);
    }

    public function test_reference_images_attach_to_and_detach_from_a_batch(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $user  = $this->actingAsCoordinator();
        $order = $this->seededOrder($user);
        $batches = $this->defineBatches($order);
        $blue = $batches['Blue trim'];

        $upload = $this->post(
            "/api/v1/admin/production-orders/{$order->id}/batches/{$blue->id}/images",
            ['image' => \Illuminate\Http\UploadedFile::fake()->image('fabric.jpg', 600, 400)],
        );
        $upload->assertOk();

        $images = $blue->fresh()->images;
        $this->assertCount(1, $images);

        $this->deleteJson(
            "/api/v1/admin/production-orders/{$order->id}/batches/{$blue->id}/images",
            ['url' => $images[0]],
        )->assertOk();

        $this->assertSame([], $blue->fresh()->images ?? []);
    }
}
