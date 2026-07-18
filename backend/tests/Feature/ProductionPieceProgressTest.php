<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Piece-level progress on batch production orders.
 *
 * A 50-piece order's stage tasks were binary — Stitching pending or done for
 * the WHOLE batch. Each stage now records ONE cumulative count (pieces that
 * passed it) and the distribution is derived. Two invariants keep the numbers
 * honest: you cannot pass more than every earlier stage has (ceiling), and you
 * cannot correct below what a later stage already consumed (floor). Gating is
 * flow-aware: Button may work whenever Stitching has surplus, no longer only
 * when Stitching is fully complete — and quantity-1 orders behave exactly as
 * before (ProductionStageTemplateAndGatingTest still pins that).
 */
class ProductionPieceProgressTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsTailor(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('tailor', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return ProductionTask[] cutting, stitching, button — all assigned to $user, order qty 50 */
    private function pipeline(User $user): array
    {
        $mk = fn (string $n, int $i) => ProductionStage::create([
            'name' => $n, 'slug' => strtolower($n), 'sort_order' => $i, 'is_active' => true,
        ]);
        $mk('Cutting', 1); $mk('Stitching', 2); $mk('Button', 3);

        $product = Product::factory()->create();
        $order   = ProductionOrder::create([
            'order_number' => 'PRD-PIECES', 'product_id' => $product->id,
            'quantity' => 50, 'status' => 'pending',
        ]);
        $order->seedTasks();
        $order->tasks()->update(['assigned_to' => $user->id]);

        return $order->tasks()->orderBy('sequence')->get()->all();
    }

    public function test_the_ceiling_you_cannot_button_shirts_that_were_never_stitched(): void
    {
        $user = $this->actingAsTailor();
        [$cutting, $stitching, $button] = $this->pipeline($user);

        $this->postJson("/api/v1/tailor/tasks/{$cutting->id}/progress",   ['quantity_done' => 15])->assertOk();
        $this->postJson("/api/v1/tailor/tasks/{$stitching->id}/progress", ['quantity_done' => 15])->assertOk();

        // 15 stitched → 15 buttonable, 16 is a lie and the error names the stage.
        $this->postJson("/api/v1/tailor/tasks/{$button->id}/progress", ['quantity_done' => 16])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Only 15 piece(s) have passed "Stitching" - you cannot record 16 here yet.']);

        $this->postJson("/api/v1/tailor/tasks/{$button->id}/progress", ['quantity_done' => 10])->assertOk();
    }

    public function test_the_floor_corrections_cannot_drop_below_downstream_consumption(): void
    {
        $user = $this->actingAsTailor();
        [$cutting, $stitching, $button] = $this->pipeline($user);

        $this->postJson("/api/v1/tailor/tasks/{$cutting->id}/progress",   ['quantity_done' => 20])->assertOk();
        $this->postJson("/api/v1/tailor/tasks/{$stitching->id}/progress", ['quantity_done' => 12])->assertOk();

        // Stitching consumed 12 — Cutting cannot now claim only 8 were cut.
        $this->postJson("/api/v1/tailor/tasks/{$cutting->id}/progress", ['quantity_done' => 8])
            ->assertStatus(422);

        // But correcting down to exactly 12 is legitimate.
        $this->postJson("/api/v1/tailor/tasks/{$cutting->id}/progress", ['quantity_done' => 12])->assertOk();
        $this->assertSame(12, $cutting->fresh()->quantity_done);
    }

    public function test_flow_gating_a_stage_starts_on_surplus_not_on_full_completion(): void
    {
        $user = $this->actingAsTailor();
        [$cutting, $stitching] = $this->pipeline($user);

        // Whole-batch rule would block Stitching until Cutting COMPLETES all 50.
        // Flow rule: five cut pieces are a pile on the bench — start stitching.
        $this->putJson("/api/v1/tailor/tasks/{$stitching->id}/status", ['action' => 'start'])
            ->assertStatus(422);

        $this->postJson("/api/v1/tailor/tasks/{$cutting->id}/progress", ['quantity_done' => 5])->assertOk();

        $this->putJson("/api/v1/tailor/tasks/{$stitching->id}/status", ['action' => 'start'])->assertOk();
    }

    public function test_lifecycle_first_piece_starts_last_piece_completes_correction_reopens(): void
    {
        $user = $this->actingAsTailor();
        [$cutting] = $this->pipeline($user);

        $this->assertSame('pending', $cutting->status);

        $this->postJson("/api/v1/tailor/tasks/{$cutting->id}/progress", ['quantity_done' => 1])->assertOk();
        $this->assertSame('in_progress', $cutting->fresh()->status);
        $this->assertNotNull($cutting->fresh()->started_at);

        $this->postJson("/api/v1/tailor/tasks/{$cutting->id}/progress", ['quantity_done' => 50])->assertOk();
        $this->assertSame('completed', $cutting->fresh()->status);

        // Miscounted — dropping back reopens the stage; completed was arithmetic.
        $this->postJson("/api/v1/tailor/tasks/{$cutting->id}/progress", ['quantity_done' => 48])->assertOk();
        $this->assertSame('in_progress', $cutting->fresh()->status);
        $this->assertNull($cutting->fresh()->completed_at);
    }

    public function test_completion_percentage_is_piece_weighted(): void
    {
        $user = $this->actingAsTailor();
        [$cutting, $stitching, $button] = $this->pipeline($user);
        $order = $cutting->productionOrder ?? ProductionOrder::where('order_number', 'PRD-PIECES')->first();

        $this->postJson("/api/v1/tailor/tasks/{$cutting->id}/progress",   ['quantity_done' => 30])->assertOk();
        $this->postJson("/api/v1/tailor/tasks/{$stitching->id}/progress", ['quantity_done' => 15])->assertOk();

        // (30 + 15 + 0) / (50 × 3) = 30%
        $this->assertSame(30, $order->fresh()->getCompletionPercentage());
    }
}
