<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\Reporting\MetricEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RFM segmentation (customer intelligence): canonical identity via
 * normalize_phone, NTILE scoring, named segments, and the win-back action list.
 */
class RfmSegmentsTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $phone, string $name, float $amount, int $daysAgo): void
    {
        Order::factory()->create([
            'order_type' => 'pos', 'status' => 'confirmed', 'payment_status' => 'paid',
            'total_amount' => $amount, 'currency_code' => 'KES',
            'customer_phone' => $phone, 'customer_first_name' => $name,
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    public function test_phone_formats_unify_into_one_customer_and_segments_emit(): void
    {
        // Same human, two phone spellings — must be ONE customer.
        $this->order('0722000111', 'Amos', 10000, 5);
        $this->order('+254722000111', 'Amos', 12000, 40);
        $this->order('254722000111', 'Amos', 9000, 100);
        // A high-value customer gone very quiet → at_risk / cant_lose material.
        $this->order('0733000222', 'Beryl', 80000, 200);
        $this->order('0733000222', 'Beryl', 70000, 250);
        // Anonymous walk-in (no phone, no ids).
        Order::factory()->create([
            'order_type' => 'pos', 'status' => 'confirmed', 'payment_status' => 'paid',
            'total_amount' => 500, 'currency_code' => 'KES',
            'customer_phone' => null, 'created_at' => now()->subDays(3),
        ]);

        $rfm = MetricEngine::for(User::factory()->create())->rfmSegments();

        $totalCustomers = collect($rfm['segments'])->sum('customers');
        $this->assertSame(2, $totalCustomers, 'Amos (3 spellings) + Beryl = 2 customers');
        $this->assertSame(1, $rfm['anonymous']['orders']);
        $this->assertEqualsWithDelta(500.0, $rfm['anonymous']['revenue'], 0.01);

        // Beryl: 450+ days... 200d quiet, 150k, 2 orders → must land in the
        // win-back list (at_risk or cant_lose), with unified spend.
        $beryl = collect($rfm['action_list'])->firstWhere('phone', '0733000222');
        $this->assertNotNull($beryl, 'high-value quiet customer must be on the win-back list');
        $this->assertContains($beryl['segment'], ['at_risk', 'cant_lose']);
        $this->assertEqualsWithDelta(150000.0, $beryl['revenue_365'], 0.01);

        // Segment rollup revenue must equal identified revenue (31k + 150k).
        $this->assertEqualsWithDelta(181000.0, collect($rfm['segments'])->sum('revenue_365'), 0.01);
    }

    public function test_customer_intelligence_endpoint_exposes_rfm(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $this->order('0722000333', 'Carol', 5000, 10);

        $res = $this->getJson('/api/v1/admin/reports/customer-intelligence?period=last_30');

        $res->assertOk();
        $this->assertIsArray($res->json('rfm.segments'));
        $this->assertSame(365, $res->json('rfm.window_days'));
        $this->assertIsArray($res->json('rfm.action_list'));
    }
}
