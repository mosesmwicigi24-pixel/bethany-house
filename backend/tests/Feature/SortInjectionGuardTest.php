<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Guards audit finding SEC-3 across the admin list endpoints.
 *
 * These endpoints interpolated the client-supplied `sort_by` (and
 * `sort_dir`/`sort_order`) straight into `orderBy()`. A column identifier
 * cannot be parameter-bound the way a value can, so an attacker-controlled
 * string reached the query builder — at minimum turning any unknown column
 * into a 500, and side-stepping the allow-list that the Orders/Shipments
 * endpoints already enforce through {@see \App\Support\SortResolver}.
 *
 * Every endpoint below now routes its sort through SortResolver, so a hostile
 * or unknown `sort_by` collapses to that endpoint's safe default column
 * instead of reaching SQL. The tests assert that fallback (HTTP 200, never a
 * 500), that a legitimate whitelisted sort still orders correctly, and that
 * the customers `name` special-case still works.
 */
class SortInjectionGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A value that is only a valid ORDER BY target if it is interpolated
     * unsafely. Postgres resolves ORDER BY columns at plan time, so — even
     * against an empty table — this would raise a QueryException (HTTP 500)
     * if it were not filtered out by the allow-list.
     */
    private const INJECTION = 'id); DROP TABLE users; --';

    private function actingWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_stock_levels_neutralises_sort_injection_and_falls_back_to_default(): void
    {
        $this->actingWithPermissions(['inventory.view']);

        InventoryItem::factory()->create(['quantity_on_hand' => 30]);
        InventoryItem::factory()->create(['quantity_on_hand' => 5]);
        InventoryItem::factory()->create(['quantity_on_hand' => 90]);

        $res = $this->getJson(
            '/api/v1/admin/inventory/stock-levels?sort_by=' . urlencode(self::INJECTION)
        );

        $res->assertOk();
        // All rows survived (the query ran) and the default sort
        // (quantity_on_hand asc) applied instead of the injected column.
        $quantities = collect($res->json('data'))->pluck('quantity_on_hand')->all();
        $this->assertSame([5, 30, 90], $quantities);
    }

    public function test_stock_levels_allows_a_whitelisted_sort_column(): void
    {
        $this->actingWithPermissions(['inventory.view']);

        InventoryItem::factory()->create(['quantity_on_hand' => 10, 'reorder_point' => 8]);
        InventoryItem::factory()->create(['quantity_on_hand' => 10, 'reorder_point' => 2]);

        $res = $this->getJson(
            '/api/v1/admin/inventory/stock-levels?sort_by=reorder_point&sort_dir=asc'
        );

        $res->assertOk();
        $points = collect($res->json('data'))->pluck('reorder_point')->all();
        $this->assertSame([2, 8], $points);
    }

    /**
     * @dataProvider adminListEndpoints
     */
    public function test_admin_list_endpoint_neutralises_sort_injection(string $permission, string $url): void
    {
        $this->actingWithPermissions([$permission]);

        // Empty tables are enough: an unfiltered injected/unknown column would
        // still fail at plan time. Success proves the allow-list fallback ran.
        $res = $this->getJson(
            $url . '?sort_by=' . urlencode(self::INJECTION)
                 . '&sort_order=' . urlencode(self::INJECTION)
        );

        $res->assertOk();
    }

    public static function adminListEndpoints(): array
    {
        return [
            'suppliers'     => ['procurement.view', '/api/v1/admin/suppliers'],
            'raw materials' => ['inventory.view',   '/api/v1/admin/inventory/materials'],
            'customers'     => ['customers.view',   '/api/v1/admin/customers'],
        ];
    }

    public function test_customers_name_sort_still_resolves(): void
    {
        $this->actingWithPermissions(['customers.view']);

        // `name` is the one allowed sort key that maps to a join rather than a
        // customers column; the fix must keep that branch working.
        $res = $this->getJson('/api/v1/admin/customers?sort_by=name&sort_order=asc');

        $res->assertOk();
    }
}
