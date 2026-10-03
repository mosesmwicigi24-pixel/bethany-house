<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use App\Services\Auth\TerminalPin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SignsInForReal;
use Tests\TestCase;

/**
 * The clerk's terminal PIN (plan §12.3): set by the clerk, hashed, 4–6 digits;
 * reset (cleared, never seen) by an outlet manager for their own outlet's
 * clerks. One service, App\Services\Auth\TerminalPin, so the till's approver
 * PIN (Phase 4B) checks PINs the same way the idle lock does.
 */
class TerminalPinTest extends TestCase
{
    use RefreshDatabase, SignsInForReal;

    private const SET = '/api/v1/admin/profile/terminal-pin';

    private function atOutlet(User $u, Outlet $o): User
    {
        $u->outlets()->attach($o->id, ['is_primary' => true]);

        return $u;
    }

    public function test_a_clerk_sets_a_pin_with_their_password_and_it_is_stored_hashed(): void
    {
        $u = $this->staffWithRoles(['pos_clerk']);
        $t = $this->bearerFor($u);

        $this->withBearer($t, 'PUT', self::SET, ['pin' => '4821', 'pin_confirmation' => '4821', 'current_password' => 'password'])
            ->assertOk();

        $row = DB::table('terminal_pins')->where('user_id', $u->id)->first();
        $this->assertNotNull($row);
        $this->assertStringNotContainsString('4821', $row->pin_hash);
        $this->assertTrue(app(TerminalPin::class)->verify($u, '4821'));
        $this->assertFalse(app(TerminalPin::class)->verify($u, '4822'));
    }

    public function test_the_pin_must_be_four_to_six_digits_and_not_trivial(): void
    {
        $u = $this->staffWithRoles(['pos_clerk']);
        $t = $this->bearerFor($u);

        foreach (['12', '1234567', 'abcd', '12a4', '1111', '1234', '9876'] as $bad) {
            $this->withBearer($t, 'PUT', self::SET, ['pin' => $bad, 'pin_confirmation' => $bad, 'current_password' => 'password'])
                ->assertStatus(422);
        }
        $this->withBearer($t, 'PUT', self::SET, ['pin' => '730195', 'pin_confirmation' => '730195', 'current_password' => 'password'])
            ->assertOk();
    }

    public function test_setting_a_pin_needs_the_password(): void
    {
        $u = $this->staffWithRoles(['pos_clerk']);
        $t = $this->bearerFor($u);

        $this->withBearer($t, 'PUT', self::SET, ['pin' => '4821', 'pin_confirmation' => '4821', 'current_password' => 'wrong'])
            ->assertStatus(422);
        $this->assertFalse(app(TerminalPin::class)->isSet($u));
    }

    public function test_an_outlet_manager_resets_their_own_outlets_clerk(): void
    {
        $o = Outlet::factory()->create();
        $clerk = $this->atOutlet($this->staffWithRoles(['pos_clerk']), $o);
        app(TerminalPin::class)->set($clerk, '4821');
        $om = $this->atOutlet($this->staffWithRoles(['outlet_manager']), $o);

        $this->withBearer($this->bearerFor($om), 'POST', "/api/v1/admin/users/{$clerk->id}/terminal-pin/reset")->assertOk();

        $this->assertFalse(app(TerminalPin::class)->isSet($clerk));
        $this->assertDatabaseHas('activity_log', ['event' => 'terminal_pin_reset', 'subject_id' => $clerk->id]);
    }

    public function test_an_outlet_manager_cannot_reset_a_clerk_at_another_outlet(): void
    {
        $clerk = $this->atOutlet($this->staffWithRoles(['pos_clerk']), Outlet::factory()->create());
        app(TerminalPin::class)->set($clerk, '4821');
        $om = $this->atOutlet($this->staffWithRoles(['outlet_manager']), Outlet::factory()->create());

        $this->withBearer($this->bearerFor($om), 'POST', "/api/v1/admin/users/{$clerk->id}/terminal-pin/reset")->assertForbidden();
        $this->assertTrue(app(TerminalPin::class)->isSet($clerk));
    }

    public function test_only_a_clerks_pin_is_reset_this_way_and_not_by_another_clerk(): void
    {
        $o = Outlet::factory()->create();
        $peer = $this->atOutlet($this->staffWithRoles(['outlet_manager']), $o);
        app(TerminalPin::class)->set($peer, '4821');
        $om = $this->atOutlet($this->staffWithRoles(['outlet_manager']), $o);
        $this->withBearer($this->bearerFor($om), 'POST', "/api/v1/admin/users/{$peer->id}/terminal-pin/reset")->assertForbidden();

        $clerk = $this->atOutlet($this->staffWithRoles(['pos_clerk']), $o);
        app(TerminalPin::class)->set($clerk, '4821');
        $otherClerk = $this->atOutlet($this->staffWithRoles(['pos_clerk']), $o);
        $this->withBearer($this->bearerFor($otherClerk), 'POST', "/api/v1/admin/users/{$clerk->id}/terminal-pin/reset")->assertForbidden();
        $this->assertTrue(app(TerminalPin::class)->isSet($clerk));
    }

    public function test_verification_is_limited_per_account(): void
    {
        $u = $this->staffWithRoles(['pos_clerk']);
        $pins = app(TerminalPin::class);
        $pins->set($u, '4821');

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($pins->verify($u, '0000'));
        }
        $this->assertFalse($pins->verify($u, '4821'), 'locked out after five wrong PINs, even with the right one');
        $this->assertTrue($pins->lockedOut($u));
    }

    public function test_the_console_learns_whether_a_pin_is_set(): void
    {
        $u = $this->staffWithRoles(['pos_clerk']);
        $t = $this->bearerFor($u);
        $this->withBearer($t, 'GET', '/api/v1/admin/auth/me')->assertJsonPath('user.terminal_pin_set', false)
            ->assertJsonPath('user.session_policy.on_idle', 'pin_lock');

        app(TerminalPin::class)->set($u, '4821');
        $this->withBearer($t, 'GET', '/api/v1/admin/auth/me')->assertJsonPath('user.terminal_pin_set', true);
    }
}
