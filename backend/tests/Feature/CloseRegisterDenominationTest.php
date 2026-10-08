<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * D10: closing a register can capture a physical denomination count. When
 * present it is the authoritative counted cash (actual_cash is derived from it),
 * it is persisted, and the discrepancy (cash_difference) reflects it.
 */
class CloseRegisterDenominationTest extends TestCase
{
    use RefreshDatabase;

    public function test_close_derives_and_persists_the_denomination_count(): void
    {
        // Was a super_admin until role hardening 1B: the owner's accounts no
        // longer work the till (owner.no_transact refuses them a close), so an
        // admin holding the close key closes it. What this pins — how the
        // denomination count is derived and stored — is unchanged.
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $user->givePermissionTo(
            Permission::findOrCreate('pos.access', 'sanctum'),
            Permission::findOrCreate('pos.close_register', 'sanctum'),
            // The catalogue admin's outlet reach — a permission since 4D.
            Permission::findOrCreate('outlets.all_access', 'sanctum'),
        );
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $outlet   = Outlet::factory()->create();
        $register = CashRegister::create([
            'outlet_id'     => $outlet->id, 'register_name' => 'Till', 'status' => 'open',
            'currency_code' => 'KES', 'opening_balance' => 1000, 'expected_cash' => 5000,
            'opened_by'     => $user->id, 'opened_at' => now(),
        ]);
        // Closing requires a submitted EoD report for today.
        DB::table('cash_register_eod_reports')->insert([
            'register_id' => $register->id, 'user_id' => $user->id, 'outlet_id' => $outlet->id,
            'report_date' => today(), 'submitted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/admin/pos/register/close', [
            'outlet_id'          => $outlet->id,
            'closing_cash'       => 0, // ignored — denominations are authoritative
            'denomination_count' => ['1000' => 5, '100' => 2], // 5000 + 200 = 5200
        ])->assertOk();

        $register->refresh();
        $this->assertEquals(5200, $register->actual_cash);      // derived from the count
        $this->assertEquals(200, $register->cash_difference);   // 5200 counted − 5000 expected
        $this->assertNotNull($register->denomination_count);    // breakdown persisted
    }
}
