<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Audit fix: ExpenseController scoped outlet managers by `$user->outlet_id`, a
 * column users don't have (outlet assignment is the many-to-many outlet_user
 * pivot), so the scoping was broken. It now scopes by the assigned outlets.
 */
class ExpenseOutletScopingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAs(array $permissions, array $roles): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        foreach ($roles as $r) {
            $user->assignRole(Role::findOrCreate($r, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeExpense(string $ref, int $categoryId, int $outletId, int $userId): void
    {
        Expense::create([
            'reference_number' => $ref,
            'title'            => "{$ref} expense",
            'category_id'      => $categoryId,
            'amount'           => 1000,
            'currency_code'    => 'KES',
            'exchange_rate'    => 1,
            'amount_kes'       => 1000,
            'expense_date'     => '2026-07-01',
            'payment_method'   => 'cash',
            'outlet_id'        => $outletId,
            'created_by'       => $userId,
            'status'           => 'approved',
        ]);
    }

    public function test_outlet_manager_sees_only_assigned_outlet_expenses(): void
    {
        $outletA = Outlet::factory()->create();
        $outletB = Outlet::factory()->create();

        $manager = $this->actingAs(['expenses.view'], ['outlet_manager']);
        $manager->outlets()->attach($outletA->id);

        $cat = ExpenseCategory::create(['name' => 'Rent', 'code' => 'RENT']);
        $this->makeExpense('EXP-A', $cat->id, $outletA->id, $manager->id);
        $this->makeExpense('EXP-B', $cat->id, $outletB->id, $manager->id);

        $res = $this->getJson('/api/v1/admin/expenses');

        $res->assertOk();
        $res->assertJsonFragment(['reference_number' => 'EXP-A']);
        $res->assertJsonMissing(['reference_number' => 'EXP-B']);
    }

    public function test_admin_is_not_outlet_scoped(): void
    {
        $outletA = Outlet::factory()->create();
        $outletB = Outlet::factory()->create();

        $admin = $this->actingAs(['expenses.view'], ['admin']);

        $cat = ExpenseCategory::create(['name' => 'Utilities', 'code' => 'UTIL']);
        $this->makeExpense('EXP-A', $cat->id, $outletA->id, $admin->id);
        $this->makeExpense('EXP-B', $cat->id, $outletB->id, $admin->id);

        $res = $this->getJson('/api/v1/admin/expenses');

        $res->assertOk();
        $res->assertJsonFragment(['reference_number' => 'EXP-A']);
        $res->assertJsonFragment(['reference_number' => 'EXP-B']);
    }
}
