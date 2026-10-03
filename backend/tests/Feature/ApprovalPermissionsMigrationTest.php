<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 3B's keys: approvals.finance_sign → finance_manager;
 * payments.request_void / request_reassign → accountant; approvals.super_sign
 * → no role (super_admin via Gate::before). The migration makes production
 * right before the next sync and takes back exactly what it gave.
 */
class ApprovalPermissionsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_03_520002_approval_engine_permissions.php';

    private const NEW = ['approvals.finance_sign', 'approvals.super_sign', 'payments.request_void', 'payments.request_reassign'];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function migration(): object
    {
        return require database_path('migrations/' . self::MIGRATION);
    }

    private function holds(string $role, string $permission): bool
    {
        return Role::findByName($role, 'sanctum')->permissions()->where('name', $permission)->exists();
    }

    /** Production before this migration: Phase 2 roles, none of the four keys. */
    private function rewind(): void
    {
        $this->migration()->down();
        foreach (self::NEW as $slug) {
            if ($id = DB::table('permissions')->where('name', $slug)->value('id')) {
                DB::table('role_has_permissions')->where('permission_id', $id)->delete();
                DB::table('permissions')->where('id', $id)->delete();
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_sync_grants_the_keys_to_the_roles_the_plan_names_and_super_sign_to_none(): void
    {
        $this->assertTrue($this->holds('finance_manager', 'approvals.finance_sign'));
        $this->assertTrue($this->holds('accountant', 'payments.request_void'));
        $this->assertTrue($this->holds('accountant', 'payments.request_reassign'));
        $this->assertSame(0, DB::table('role_has_permissions')
            ->where('permission_id', Permission::findByName('approvals.super_sign', 'sanctum')->id)->count());
        foreach (['admin', 'procurement_manager', 'outlet_manager', 'pos_clerk', 'system_admin'] as $role) {
            foreach (self::NEW as $p) {
                $this->assertFalse($this->holds($role, $p), "{$role} must not hold {$p}");
            }
        }
    }

    public function test_on_productions_shape_up_grants_and_down_takes_back_exactly_that(): void
    {
        $this->rewind();
        $person = User::factory()->create();
        $migration = $this->migration();

        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($this->holds('finance_manager', 'approvals.finance_sign'));
        $this->assertTrue($this->holds('accountant', 'payments.request_void'));
        $this->assertNotNull(Permission::where('name', 'approvals.super_sign')->first());

        $person->givePermissionTo('payments.request_void');   // a direct grant made after
        $migration->up();                                       // idempotent

        $migration->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($this->holds('finance_manager', 'approvals.finance_sign'));
        $this->assertFalse($this->holds('accountant', 'payments.request_void'));
        $this->assertNull(Permission::where('name', 'approvals.finance_sign')->first());
        $this->assertNotNull(Permission::where('name', 'payments.request_void')->first(), 'kept: someone holds it directly');
        $this->assertTrue($person->fresh()->hasPermissionTo('payments.request_void', 'sanctum'));
    }

    public function test_the_accountant_can_ask_but_not_execute_and_admin_can_do_neither(): void
    {
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        Sanctum::actingAs($accountant->fresh());
        $this->assertNotSame(403, $this->postJson('/api/v1/admin/payment-transactions/999999/void-request', ['reason' => 'x'])->status());
        $this->assertNotSame(403, $this->postJson('/api/v1/admin/payment-transactions/999999/reassign-request', ['reason' => 'x', 'order_id' => 1])->status());
        $this->postJson('/api/v1/admin/payment-transactions/999999/void', ['reason' => 'x'])->assertForbidden();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin->fresh());
        $this->postJson('/api/v1/admin/payment-transactions/999999/void-request', ['reason' => 'x'])->assertForbidden();
        $this->postJson('/api/v1/admin/payment-transactions/999999/reassign-request', ['reason' => 'x'])->assertForbidden();
    }
}
