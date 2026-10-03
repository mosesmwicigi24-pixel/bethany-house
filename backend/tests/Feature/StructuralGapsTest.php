<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A zero must say WHY it is zero.
 *
 * Cycle 1 noticed that several metrics read zero not because the business was
 * idle but because the work behind them is never recorded: on production,
 * 0 of 145 production orders have ever been marked complete, and all 25
 * expenses sit in `pending_approval`. Throughput, on-time rate and every
 * operating-expense figure are therefore structurally unpopulatable, and the
 * margins beside them are gross of costs already incurred.
 *
 * Reported as a bare zero, that invites the wrong conclusion — that nothing was
 * made and nothing was spent. It is also indistinguishable from the metric
 * being broken, which is how a defect hides for a year.
 *
 * The gaps are now named in the payload. The test that matters most is the
 * NEGATIVE one: when the work does happen, the list must be empty, or a
 * permanent banner would train everyone to ignore it.
 */
class StructuralGapsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $staff = User::factory()->create();
        foreach ([...\Tests\ReportAccess::PAGES, 'reports.financial'] as $p) {
            $staff->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($staff);
    }

    private function gaps(): array
    {
        return $this->getJson('/api/v1/admin/reports/executive?period=this_month')
            ->assertOk()->json('structural_gaps');
    }

    private function productionOrder(string $status): void
    {
        DB::table('production_orders')->insert([
            'order_number' => 'PG-' . bin2hex(random_bytes(3)),
            'product_id' => Product::factory()->create()->id,
            'quantity' => 1, 'status' => $status, 'priority' => 'normal',
            'is_customer_order' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function expense(string $status): void
    {
        // expenses requires a category and a reference; the pattern is lifted
        // from ExpenseConsistencyTest so the two suites seed the same shape.
        // name is unique, and a test seeds two expenses — reuse the category.
        $suffix     = bin2hex(random_bytes(2));
        $categoryId = DB::table('expense_categories')->where('code', 'MAT')->value('id')
            ?: DB::table('expense_categories')->insertGetId([
                'name' => 'Materials ' . $suffix, 'code' => 'MAT',
                'created_at' => now(), 'updated_at' => now(),
            ]);

        DB::table('expenses')->insert([
            'reference_number' => 'EXP-' . bin2hex(random_bytes(3)),
            'title' => 'Cloth', 'category_id' => $categoryId,
            'amount' => 500, 'amount_kes' => 500, 'currency_code' => 'KES',
            'expense_date' => now()->toDateString(), 'status' => $status,
            'payment_method' => 'cash',
            'created_by' => User::factory()->create()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_shop_floor_that_never_signs_jobs_off_is_named_not_reported_as_idle(): void
    {
        $this->productionOrder('in_progress');
        $this->productionOrder('draft');

        $gap = collect($this->gaps())->firstWhere('key', 'production_never_completed');

        $this->assertNotNull($gap, 'two open jobs and no completions is a gap, not idleness');
        $this->assertStringContainsString('2 production orders', $gap['detail']);
        $this->assertContains('production.on_time_pct', $gap['metrics'],
            'the gap names the figures it explains, so a reader can connect them');
    }

    public function test_expenses_awaiting_approval_are_named_rather_than_counted_as_nil(): void
    {
        $this->expense('pending_approval');
        $this->expense('draft');

        $gap = collect($this->gaps())->firstWhere('key', 'expenses_never_approved');

        $this->assertNotNull($gap);
        $this->assertStringContainsString('awaiting approval', $gap['detail']);
    }

    public function test_the_list_is_empty_once_the_work_is_actually_recorded(): void
    {
        // The important direction. A banner that never goes away is furniture,
        // and furniture gets ignored — so a completed job and an approved
        // expense must clear their gaps entirely.
        $this->productionOrder('in_progress');
        $this->productionOrder('completed');
        $this->expense('pending_approval');
        $this->expense('approved');

        $this->assertSame([], $this->gaps(),
            'when the work happens, the zeros are real and nothing is claimed');
    }

    public function test_nothing_is_claimed_about_a_business_with_no_production_at_all(): void
    {
        // No production orders and no expenses is not a gap — there is nothing
        // to sign off. Reporting a gap here would be noise about absence.
        Order::create(['order_number' => 'PG-only-sales', 'status' => 'completed',
            'payment_status' => 'paid', 'currency_code' => 'KES',
            'subtotal' => 100, 'total_amount' => 100]);

        $this->assertSame([], $this->gaps());
    }
}
