<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Role hardening 4D: every activity_log row says who acted IN WHAT ROLE, at
 * which outlet, on which token, through which door, and whether it worked —
 * under properties._audit.
 */
class AuditEventContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Probe routes inside each door's URL space: the channel is derived
        // from the matched route, so a probe must live where real routes do.
        $probe = function () {
            $subject = new class extends Model {
                protected $table = 'outlets';
            };
            $subject->setRawAttributes(['id' => 41, 'outlet_id' => request('subject_outlet')]);
            ActivityLogService::log('probe_event', request('subject_outlet') ? $subject : null,
                request('fail') ? ['outcome' => 'failure'] : []);
            return response()->json(['ok' => true]);
        };
        Route::middleware(['api', 'auth:sanctum'])->post('api/v1/admin/pos/__audit_probe', $probe);
        Route::middleware(['api', 'auth:sanctum'])->post('api/v1/admin/__audit_probe', $probe);
        Route::middleware(['api', 'auth:sanctum'])->post('api/v1/__audit_probe_shop', $probe);
        Route::middleware(['api', 'api.key:required'])->post('api/v1/__audit_probe_key', $probe);
    }

    private function staff(string ...$roles): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        foreach ($roles as $r) {
            $u->assignRole(Role::findOrCreate($r, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return $u;
    }

    private function lastContext(string $event = 'probe_event'): array
    {
        $row = DB::table('activity_log')->where('event', $event)->latest('id')->first();
        $this->assertNotNull($row, "no {$event} row");
        $props = json_decode($row->properties, true);
        $this->assertArrayHasKey('_audit', $props, 'properties._audit missing');
        return $props['_audit'];
    }

    public function test_a_till_action_records_roles_outlet_token_channel_and_success(): void
    {
        $clerk = $this->staff('pos_clerk', 'outlet_manager');
        $token = $clerk->createToken('till');

        $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/admin/pos/__audit_probe', ['subject_outlet' => 7])
            ->assertOk();

        $ctx = $this->lastContext();
        $this->assertSame(['outlet_manager', 'pos_clerk'], $ctx['roles']);
        $this->assertSame(7, $ctx['outlet_id']);
        $this->assertSame('subject', $ctx['outlet_source']);
        $this->assertSame('pat:' . $token->accessToken->id, $ctx['token_id']);
        $this->assertSame('till', $ctx['channel']);
        $this->assertSame('success', $ctx['outcome']);
    }

    public function test_roles_are_those_held_when_acting_not_now(): void
    {
        $u = $this->staff('finance');
        $token = $u->createToken('console')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/admin/__audit_probe')->assertOk();

        $u->syncRoles([Role::findOrCreate('accountant', 'sanctum')]);

        $this->assertSame(['finance'], $this->lastContext()['roles']);
    }

    public function test_a_console_call_records_the_request_outlet_as_a_request_value(): void
    {
        $u = $this->staff('admin');
        $token = $u->createToken('console')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/admin/__audit_probe', ['outlet_id' => 3])->assertOk();
        $ctx = $this->lastContext();
        $this->assertSame('web_console', $ctx['channel']);
        $this->assertSame(3, $ctx['outlet_id']);
        $this->assertSame('request', $ctx['outlet_source']);

    }

    public function test_a_customer_call_is_the_storefront_channel_with_no_roles(): void
    {
        $customer = User::factory()->create(['user_type' => 'customer']);
        $this->withHeader('Authorization', 'Bearer ' . $customer->createToken('shop')->plainTextToken)
            ->postJson('/api/v1/__audit_probe_shop')->assertOk();
        $ctx = $this->lastContext();
        $this->assertSame('storefront', $ctx['channel']);
        $this->assertSame([], $ctx['roles']);
    }

    public function test_the_sales_agent_is_the_chat_agent_channel(): void
    {
        $agent = $this->staff('pos_clerk');
        $agent->forceFill(['email' => config('pos.agent_user_email')])->save();

        $this->withHeader('Authorization', 'Bearer ' . $agent->createToken('neema')->plainTextToken)
            ->postJson('/api/v1/admin/pos/__audit_probe')->assertOk();

        $this->assertSame('chat_agent', $this->lastContext()['channel']);
    }

    public function test_an_api_key_call_is_the_api_key_channel(): void
    {
        DB::table('api_keys')->insert([
            'name' => 'partner', 'key' => 'k-' . str_repeat('a', 30), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withHeader('X-API-Key', 'k-' . str_repeat('a', 30))
            ->postJson('/api/v1/__audit_probe_key')->assertOk();

        $this->assertSame('api_key', $this->lastContext()['channel']);
    }

    public function test_an_explicit_or_named_failure_is_recorded_as_failure(): void
    {
        $u = $this->staff('admin');
        $this->withHeader('Authorization', 'Bearer ' . $u->createToken('c')->plainTextToken)
            ->postJson('/api/v1/admin/__audit_probe', ['fail' => 1])->assertOk();
        $this->assertSame('failure', $this->lastContext()['outcome']);

        ActivityLogService::log('database_backup_failed', null, [], null, $u);
        $this->assertSame('failure', $this->lastContext('database_backup_failed')['outcome']);
    }

    public function test_console_commands_and_queue_jobs_are_their_own_channels(): void
    {
        ActivityLogService::log('probe_event');
        $this->assertSame('console', $this->lastContext()['channel']);

        dispatch(function () {
            ActivityLogService::log('job_probe');
        });
        $this->assertSame('job', $this->lastContext('job_probe')['channel']);

        // ...and the job's mark does not outlive it.
        ActivityLogService::log('after_job');
        $this->assertSame('console', $this->lastContext('after_job')['channel']);
    }

    public function test_observer_rows_carry_the_context_and_redaction_still_holds(): void
    {
        $u = $this->staff('admin');
        $this->actingAs($u, 'sanctum');

        $target = User::factory()->create();
        $target->update(['password' => bcrypt('another-secret-pass')]);

        $row = DB::table('activity_log')->where('subject_type', User::class)
            ->where('subject_id', $target->id)->where('event', 'updated')->latest('id')->first();
        $props = json_decode($row->properties, true);
        $this->assertEquals(['old' => '[REDACTED]', 'new' => '[REDACTED]'], $props['changes']['password']);
        $this->assertSame(['admin'], $props['_audit']['roles']);
    }

    public function test_the_direct_inserts_now_go_through_the_one_writer(): void
    {
        // ProfileController/UserController/SettingController inserted rows by
        // hand: no event, no request id, no context.
        $admin = $this->staff('finance');
        $this->withHeader('Authorization', 'Bearer ' . $admin->createToken('c')->plainTextToken)
            ->postJson('/api/v1/admin/profile/sessions/revoke-all')
            ->assertSuccessful();

        $row = DB::table('activity_log')->where('causer_id', $admin->id)
            ->where('action', 'sessions_revoked')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('sessions_revoked', $row->event);
        $this->assertNotNull($row->request_id);
        $this->assertSame('web_console', json_decode((string) $row->properties, true)['_audit']['channel'] ?? null);
    }
}
