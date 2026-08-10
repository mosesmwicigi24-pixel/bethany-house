<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Guards the `sort_by` allow-list across the list endpoints.
 *
 * These endpoints passed the client-supplied `sort_by` (and
 * `sort_dir`/`sort_order`) straight into `orderBy()`. This is *not* a SQL
 * injection — Laravel's grammar quotes the identifier and the builder rejects
 * any direction that is not `asc`/`desc`. The real defect is availability and
 * disclosure: an unknown column raises a QueryException, so any caller could
 * turn a list endpoint into a **500** at will and probe which column names
 * exist by watching 200-vs-500 (one of these routes, `GET /{id}/reviews`, is
 * public storefront surface).
 *
 * Every endpoint below now routes its sort through {@see \App\Support\SortResolver},
 * so an unknown `sort_by` collapses to that endpoint's safe default column.
 * The tests assert that fallback (HTTP 200, never a 500), that a legitimate
 * whitelisted sort still orders correctly, that the customers `name`
 * special-case still works, and that sort direction is honoured on the two
 * endpoints that historically only read `sort_dir` while the SPA sends
 * `sort_order`.
 */
class SortInjectionGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A hostile-looking column name. Laravel quotes it, so it can never
     * execute — it simply is not a column. Postgres resolves ORDER BY columns
     * at plan time, so — even against an empty table — this would raise a
     * QueryException (HTTP 500) if the allow-list did not filter it out.
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
     * `quantity_available` is a generated column on inventory_items
     * (quantity_on_hand - quantity_reserved) and is the number the stock table
     * actually displays, so it is on the allow-list. The fixtures are built so
     * that ordering by it is *not* the same as ordering by quantity_on_hand —
     * that is what proves the generated column is the one being sorted.
     */
    public function test_stock_levels_sorts_by_the_generated_quantity_available_column(): void
    {
        $this->actingWithPermissions(['inventory.view']);

        InventoryItem::factory()->create(['quantity_on_hand' => 100, 'quantity_reserved' => 90]); // available 10
        InventoryItem::factory()->create(['quantity_on_hand' => 20,  'quantity_reserved' => 0]);  // available 20
        InventoryItem::factory()->create(['quantity_on_hand' => 50,  'quantity_reserved' => 45]); // available 5

        $res = $this->getJson(
            '/api/v1/admin/inventory/stock-levels?sort_by=quantity_available&sort_order=asc'
        );

        $res->assertOk();
        $this->assertSame(
            [5, 10, 20],
            collect($res->json('data'))->pluck('quantity_available')->all()
        );

        $desc = $this->getJson(
            '/api/v1/admin/inventory/stock-levels?sort_by=quantity_available&sort_order=desc'
        );

        $desc->assertOk();
        $this->assertSame(
            [20, 10, 5],
            collect($desc->json('data'))->pluck('quantity_available')->all()
        );
    }

    /**
     * The SPA (useTableState) sends `sort_order`; this endpoint only ever read
     * `sort_dir`, so the direction was silently dropped and the list always
     * came back ascending. Both spellings must now work.
     */
    public function test_stock_levels_honours_direction_from_either_param(): void
    {
        $this->actingWithPermissions(['inventory.view']);

        InventoryItem::factory()->create(['quantity_on_hand' => 30]);
        InventoryItem::factory()->create(['quantity_on_hand' => 5]);
        InventoryItem::factory()->create(['quantity_on_hand' => 90]);

        $viaSortOrder = $this->getJson(
            '/api/v1/admin/inventory/stock-levels?sort_by=quantity_on_hand&sort_order=desc'
        );
        $viaSortOrder->assertOk();
        $this->assertSame(
            [90, 30, 5],
            collect($viaSortOrder->json('data'))->pluck('quantity_on_hand')->all()
        );

        // The legacy spelling keeps working for any consumer already using it.
        $viaSortDir = $this->getJson(
            '/api/v1/admin/inventory/stock-levels?sort_by=quantity_on_hand&sort_dir=desc'
        );
        $viaSortDir->assertOk();
        $this->assertSame(
            [90, 30, 5],
            collect($viaSortDir->json('data'))->pluck('quantity_on_hand')->all()
        );
    }

    /** Same `sort_order` / `sort_dir` mismatch as stock-levels. */
    public function test_raw_materials_honours_direction_from_either_param(): void
    {
        $this->actingWithPermissions(['inventory.view']);

        foreach (['Alpha Linen', 'Bravo Cotton', 'Charlie Silk'] as $i => $name) {
            Material::create([
                'code'            => 'MAT-' . $i,
                'name'            => $name,
                'unit_of_measure' => 'm',
                'unit_cost'       => 100,
                'reorder_point'   => 1,
                'is_active'       => true,
            ]);
        }

        $viaSortOrder = $this->getJson('/api/v1/admin/inventory/materials?sort_by=name&sort_order=desc');
        $viaSortOrder->assertOk();
        $this->assertSame(
            ['Charlie Silk', 'Bravo Cotton', 'Alpha Linen'],
            collect($viaSortOrder->json('data'))->pluck('name')->all()
        );

        $viaSortDir = $this->getJson('/api/v1/admin/inventory/materials?sort_by=name&sort_dir=desc');
        $viaSortDir->assertOk();
        $this->assertSame(
            ['Charlie Silk', 'Bravo Cotton', 'Alpha Linen'],
            collect($viaSortDir->json('data'))->pluck('name')->all()
        );

        // ...and ascending is still ascending.
        $asc = $this->getJson('/api/v1/admin/inventory/materials?sort_by=name&sort_order=asc');
        $asc->assertOk();
        $this->assertSame(
            ['Alpha Linen', 'Bravo Cotton', 'Charlie Silk'],
            collect($asc->json('data'))->pluck('name')->all()
        );
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
