<?php

namespace Tests\Feature;

use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Who can see whose quotations.
 *
 * #297 scoped quotations so a clerk sees the ones she raised rather than the
 * shop's. The other half of that has to hold too: an administrator supervising
 * the shop must see everyone's — the drafts included, because a draft is
 * exactly the thing someone needs help finishing.
 */
class QuotationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $name, string $scope, array $permissions): Role
    {
        $role = Role::findOrCreate($name, 'sanctum');
        foreach ($permissions as $p) {
            $role->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        DB::table('roles')->where('id', $role->id)->update(['data_scope' => $scope]);

        return $role->fresh();
    }

    private function actor(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        return $user->fresh();
    }

    private function draftBy(User $staff, string $customer): Quotation
    {
        return Quotation::withoutViewerScope()->create([
            'status'              => Quotation::DRAFT,
            'source'              => 'admin',
            'customer_first_name' => $customer,
            'currency_code'       => 'KES',
            'subtotal'            => 97000,
            'total_amount'        => 97000,
            'created_by'          => $staff->id,
        ]);
    }

    private function listedNumbers(): array
    {
        return collect($this->getJson('/api/v1/admin/quotations')->assertSuccessful()->json('data'))
            ->pluck('customer_first_name')
            ->all();
    }

    // ── The report: a supervisor could not see the shop's drafts ─────────────

    public function test_a_super_admin_sees_every_users_quotations_including_drafts(): void
    {
        $clerkRole = $this->role('pos_clerk', 'own', ['quotations.view', 'quotations.create']);
        $clerk     = $this->actor($clerkRole);
        $this->draftBy($clerk, 'Everlyne');

        $this->actor($this->role('super_admin', 'all', ['quotations.view']));

        $this->assertContains('Everlyne', $this->listedNumbers());
    }

    public function test_an_admin_sees_every_users_quotations_including_drafts(): void
    {
        $clerkRole = $this->role('pos_clerk', 'own', ['quotations.view', 'quotations.create']);
        $clerk     = $this->actor($clerkRole);
        $this->draftBy($clerk, 'Everlyne');

        $this->actor($this->role('admin', 'all', ['quotations.view']));

        $this->assertContains('Everlyne', $this->listedNumbers());
    }

    /**
     * The trap this rule is designed to avoid: an administrator who ALSO holds
     * a narrowed role (an admin who works the till) must keep the wider view,
     * not inherit the clerk's.
     */
    public function test_an_admin_who_also_works_the_till_keeps_the_wider_view(): void
    {
        $clerkRole = $this->role('pos_clerk', 'own', ['quotations.view', 'quotations.create']);
        $clerk     = $this->actor($clerkRole);
        $this->draftBy($clerk, 'Everlyne');

        $adminRole = $this->role('admin', 'all', ['quotations.view']);
        $both      = User::factory()->create();
        $both->assignRole($adminRole);
        $both->assignRole($clerkRole);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($both->fresh());

        $this->assertContains('Everlyne', $this->listedNumbers());
    }

    // ── And the half that must keep holding ─────────────────────────────────

    public function test_a_clerk_still_sees_only_her_own(): void
    {
        $clerkRole = $this->role('pos_clerk', 'own', ['quotations.view', 'quotations.create']);

        $hers   = $this->actor($clerkRole);
        $this->draftBy($hers, 'Everlyne');

        $anothers = $this->actor($clerkRole);
        $this->draftBy($anothers, 'Priscilla');

        $listed = $this->listedNumbers();
        $this->assertContains('Priscilla', $listed);
        $this->assertNotContains('Everlyne', $listed);
    }

    public function test_a_super_admin_can_open_another_users_draft(): void
    {
        $clerkRole = $this->role('pos_clerk', 'own', ['quotations.view', 'quotations.create']);
        $clerk     = $this->actor($clerkRole);
        $draft     = $this->draftBy($clerk, 'Everlyne');

        $this->actor($this->role('super_admin', 'all', ['quotations.view']));

        $this->getJson("/api/v1/admin/quotations/{$draft->id}")
            ->assertSuccessful()
            ->assertJsonPath('quotation.customer_first_name', 'Everlyne');
    }
}
