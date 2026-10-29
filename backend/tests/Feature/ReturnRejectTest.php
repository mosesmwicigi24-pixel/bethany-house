<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression: ReturnController::reject wrote rejected_at / rejected_by /
 * rejection_reason to order_returns — none of which exist — so every
 * rejection 500'd (SQLSTATE 42703) before anything committed.
 */
class ReturnRejectTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(Permission::findOrCreate('orders.manage_returns', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($this->admin);
    }

    private function seedReturn(string $status = 'pending'): int
    {
        $order = Order::factory()->create();

        return DB::table('order_returns')->insertGetId([
            'return_number' => 'RET-TEST-' . uniqid(),
            'order_id'      => $order->id,
            'status'        => $status,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function test_rejecting_a_pending_return_records_the_reason(): void
    {
        $returnId = $this->seedReturn();

        $this->postJson("/api/v1/admin/returns/{$returnId}/reject", [
            'reason' => 'Item shows wear beyond the return policy',
        ])->assertOk();

        $return = DB::table('order_returns')->find($returnId);
        $this->assertSame('rejected', $return->status);
        $this->assertSame('Item shows wear beyond the return policy', $return->rejection_reason);
        $this->assertSame($this->admin->id, (int) $return->rejected_by);
        $this->assertNotNull($return->rejected_at);
    }

    public function test_a_return_that_is_not_pending_cannot_be_rejected(): void
    {
        $returnId = $this->seedReturn('completed');

        $this->postJson("/api/v1/admin/returns/{$returnId}/reject", ['reason' => 'x'])
            ->assertStatus(422);

        $this->assertSame('completed', DB::table('order_returns')->find($returnId)->status);
    }

    public function test_a_reason_is_required(): void
    {
        $returnId = $this->seedReturn();

        $this->postJson("/api/v1/admin/returns/{$returnId}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }
}
