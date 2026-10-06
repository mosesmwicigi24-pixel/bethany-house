<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Owner, 2026-10-06: a product deleted at 09:27 was saved from a tab still open
 * at 09:28 and the console showed "No query results for model
 * [App\Models\Product] 130". Same 404 — words a person can act on.
 */
class MissingRecordMessageTest extends TestCase
{
    use RefreshDatabase;

    private function staffWith(array $permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_a_deleted_product_says_when_and_where_to_restore_it(): void
    {
        $this->staffWith(['products.view', 'products.edit']);
        $product = Product::factory()->create();
        $product->delete();

        $res = $this->getJson("/api/v1/admin/products/{$product->id}")->assertNotFound();

        $this->assertSame('deleted', $res->json('reason'));
        $this->assertStringStartsWith('This product was deleted on ', $res->json('message'));
        $this->assertStringContainsString('Recycle Bin', $res->json('message'));
        $this->assertStringNotContainsString('No query results', $res->json('message'));

        $this->putJson("/api/v1/admin/products/{$product->id}", ['name' => 'x'])
            ->assertNotFound()->assertJson(['reason' => 'deleted']);
    }

    public function test_a_product_that_never_existed_says_it_no_longer_exists(): void
    {
        $this->staffWith(['products.view']);

        $res = $this->getJson('/api/v1/admin/products/999999')->assertNotFound();

        $this->assertSame('not_found', $res->json('reason'));
        $this->assertStringStartsWith('This product no longer exists', $res->json('message'));
    }

    public function test_an_unknown_route_keeps_the_plain_404(): void
    {
        $this->staffWith(['products.view']);

        $res = $this->getJson('/api/v1/admin/no-such-endpoint')->assertNotFound();

        $this->assertNull($res->json('reason'));
    }
}
