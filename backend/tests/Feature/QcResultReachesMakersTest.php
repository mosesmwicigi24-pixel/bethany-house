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
 * Production UI Cycle 3 (shop-floor workflow): the QC result reaches the
 * people who made the garment, and a failed order stays on their queue.
 *
 * Before: only managers were notified; a failed order's tasks were all
 * completed, so it sat in the tailor's "Completed" lane and she never knew.
 *
 * Fixture: one order in QC, Cutting by the cutter, Stitching by the stitcher
 * (both completed); a third tailor not on the order; an admin inspects.
 */
class QcResultReachesMakersTest extends TestCase
{
    use RefreshDatabase;

    private User $cutter;
    private User $stitcher;
    private User $bystander;
    private User $inspector;
    private ProductionOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');

        $this->cutter    = $this->user('tailor');
        $this->stitcher  = $this->user('tailor');
        $this->bystander = $this->user('tailor');
        $this->inspector = $this->user('admin');

        $this->order = ProductionOrder::create([
            'order_number' => 'PRD-QCR-1', 'product_id' => Product::factory()->create()->id,
            'status' => 'qc_pending', 'quantity' => 2, 'due_date' => now()->addDays(3)->toDateString(),
        ]);
        foreach ([[$this->cutter, 1], [$this->stitcher, 2]] as [$who, $seq]) {
            $stage = ProductionStage::create(['name' => "Stage {$seq}", 'slug' => "qcr-{$seq}", 'sort_order' => $seq, 'is_active' => true]);
            ProductionTask::withoutViewerScope()->create([
                'production_order_id' => $this->order->id, 'production_stage_id' => $stage->id,
                'assigned_to' => $who->id, 'sequence' => $seq, 'status' => 'completed', 'quantity_done' => 2,
            ]);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    /** @return list<array> */
    private function notificationsFor(User $u): array
    {
        return DB::table('notifications')->where('notifiable_id', $u->id)->pluck('data')
            ->map(fn ($d) => json_decode($d, true))->all();
    }

    private function inspect(bool $passed, ?string $notes = null): void
    {
        Sanctum::actingAs($this->inspector);
        $this->postJson("/api/v1/admin/production-orders/{$this->order->id}/qc", [
            'passed' => $passed, 'passed_quantity' => $passed ? 2 : 0,
            'failed_quantity' => $passed ? 0 : 2, 'notes' => $notes,
        ])->assertOk();
    }

    public function test_a_failed_qc_tells_everyone_who_made_it_and_no_one_else(): void
    {
        $this->inspect(false, 'Hem uneven on the left side');

        foreach ([$this->cutter, $this->stitcher] as $maker) {
            $rows = collect($this->notificationsFor($maker))->filter(fn ($r) => str_starts_with($r['title'], 'QC '));
            $this->assertCount(1, $rows, 'each maker hears the result once');
            $row = $rows->first();
            $this->assertStringStartsWith('QC failed:', $row['title']);
            $this->assertStringContainsString('PRD-QCR-1', $row['body']);
            $this->assertStringContainsString('Hem uneven on the left side', $row['body']);
            $this->assertSame('/production/my-tasks', $row['action_url']);
        }

        $this->assertSame([], $this->notificationsFor($this->bystander), 'a tailor not on the order hears nothing');
        $this->assertSame([], collect($this->notificationsFor($this->inspector))
            ->filter(fn ($r) => str_starts_with($r['title'], 'QC failed:'))->values()->all(),
            'the inspector is not sent the bench notice for their own result');
    }

    public function test_a_passed_qc_tells_the_makers_too(): void
    {
        $this->inspect(true);

        $row = collect($this->notificationsFor($this->stitcher))->first(fn ($r) => str_starts_with($r['title'], 'QC '));
        $this->assertNotNull($row);
        $this->assertStringStartsWith('QC passed:', $row['title']);
    }

    public function test_a_failed_order_stays_on_the_makers_task_list(): void
    {
        $this->inspect(false);

        Sanctum::actingAs($this->stitcher);
        $tasks = collect($this->getJson('/api/v1/tailor/tasks?include_awaiting_qc=true')->assertOk()->json());
        $mine  = $tasks->firstWhere('production_order_id', $this->order->id);

        $this->assertNotNull($mine, "her finished stage on the failed order is in My Tasks' active queue");
        $this->assertSame('qc_failed', $mine['production_order']['status'], 'with the status the Failed QC lane keys on');

        // Home's list (no flag) stays open work only.
        $home = collect($this->getJson('/api/v1/tailor/tasks')->assertOk()->json());
        $this->assertNull($home->firstWhere('production_order_id', $this->order->id), 'Home shows open work only');
    }
}
