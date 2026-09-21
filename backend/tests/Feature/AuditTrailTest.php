<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Audit\AuditSealer;
use App\Services\DatabaseManagementService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The audit trail: complete (every change, every staff call), attributable,
 * and impossible to quietly rewrite.
 *
 * Tests run inside a transaction (RefreshDatabase). Statements the database is
 * expected to REFUSE run inside DB::transaction() — a savepoint — so the refusal
 * rolls back only that statement, exactly as ActivityLogService does in production.
 */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $u = User::factory()->create(['status' => 'active']);
        $u->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return $u;
    }

    private function logRow(array $overrides = []): int
    {
        return DB::table('activity_log')->insertGetId(array_merge([
            'log_name' => 'default', 'description' => 'seed', 'event' => 'seed', 'action' => 'seed',
            'properties' => '{}', 'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
        ], $overrides));
    }

    private function assertRefused(callable $statement, string $what): void
    {
        try {
            DB::transaction($statement);
            $this->fail("{$what} should have been refused by the append-only trigger");
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage(), $what);
        }
    }

    // ── 1. append-only at the database ──────────────────────────────────────

    public function test_the_activity_log_refuses_update_delete_and_truncate(): void
    {
        $id = $this->logRow();

        $this->assertRefused(fn () => DB::table('activity_log')->where('id', $id)->update(['description' => 'edited']), 'UPDATE');
        $this->assertRefused(fn () => DB::table('activity_log')->where('id', $id)->delete(), 'DELETE');
        $this->assertRefused(fn () => DB::statement('TRUNCATE activity_log'), 'TRUNCATE');

        $this->assertSame('seed', DB::table('activity_log')->where('id', $id)->value('description'));
    }

    public function test_request_logs_can_only_be_pruned_through_the_announced_door(): void
    {
        $id = DB::table('request_logs')->insertGetId([
            'method' => 'GET', 'path' => '/x', 'status' => 200, 'occurred_at' => now()->subYears(2),
        ]);

        $this->assertRefused(fn () => DB::table('request_logs')->where('id', $id)->delete(), 'unannounced DELETE');

        DB::transaction(function () use ($id) {
            DB::statement("SET LOCAL audit.allow_prune = 'on'");
            DB::table('request_logs')->where('id', $id)->delete();
        });
        $this->assertDatabaseMissing('request_logs', ['id' => $id]);
    }

    // ── 2. every change, with before and after ──────────────────────────────

    public function test_an_observed_model_records_create_and_each_changed_field(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin, 'sanctum');

        $product = Product::factory()->create(['slug' => 'cassock', 'sku' => '0012']);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Product::class, 'subject_id' => $product->id, 'event' => 'created', 'causer_id' => $admin->id,
        ]);

        // "0012" → "12" is a real change (a SKU, a phone), not a numeric no-op.
        $product->update(['slug' => 'clergy-cassock', 'sku' => '12']);

        $row = DB::table('activity_log')->where('subject_type', Product::class)
            ->where('subject_id', $product->id)->where('event', 'updated')->first();
        $this->assertNotNull($row);
        $changes = json_decode($row->properties, true)['changes'];
        $this->assertEquals(['old' => 'cassock', 'new' => 'clergy-cassock'], $changes['slug']);
        $this->assertEquals(['old' => '0012', 'new' => '12'], $changes['sku']);
        $this->assertArrayNotHasKey('updated_at', $changes);
        $this->assertStringContainsString('sku 0012 → 12', $row->description);
        $this->assertNotNull($row->request_id);
    }

    public function test_a_save_that_changes_nothing_but_timestamps_writes_nothing(): void
    {
        $product = Product::factory()->create();
        $before = DB::table('activity_log')->where('event', 'updated')->count();

        $product->touch();

        $this->assertSame($before, DB::table('activity_log')->where('event', 'updated')->count());
    }

    public function test_sensitive_fields_are_recorded_as_changed_never_by_value(): void
    {
        $user = User::factory()->create();
        $user->update(['password' => bcrypt('a-new-secret-password')]);

        $row = DB::table('activity_log')->where('subject_type', User::class)
            ->where('subject_id', $user->id)->where('event', 'updated')->latest('id')->first();
        $changes = json_decode($row->properties, true)['changes'];

        $this->assertEquals(['old' => '[REDACTED]', 'new' => '[REDACTED]'], $changes['password']);
        $this->assertStringNotContainsString('$2y$', $row->properties);
    }

    public function test_a_manual_generic_log_for_an_observed_save_is_not_written_twice(): void
    {
        $product = Product::factory()->create(['slug' => 'a-slug']);
        $product->update(['slug' => 'b-slug']);

        ActivityLogService::logUpdated($product, ['slug' => 'a-slug'], ['slug' => 'b-slug']);

        $this->assertSame(1, DB::table('activity_log')->where('subject_type', Product::class)
            ->where('subject_id', $product->id)->where('event', 'updated')->count());
    }

    // ── 3. a failed audit write never takes the business write down ─────────

    public function test_a_failing_audit_write_does_not_poison_the_surrounding_transaction(): void
    {
        try {
            DB::transaction(function () {
                // Break the audit insert: the column it writes no longer exists.
                DB::statement('ALTER TABLE activity_log DROP COLUMN request_id');

                ActivityLogService::log('anything', null, ['k' => 'v']);   // fails inside its savepoint

                // The business transaction is still usable — Postgres did not abort it.
                $product = Product::factory()->create(['slug' => 'still-saved']);
                $this->assertSame('still-saved', Product::find($product->id)->slug);

                throw new \RuntimeException('roll back the DDL');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('roll back the DDL', $e->getMessage());
        }

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('activity_log', 'request_id'));
    }

    // ── 4. who looked at what ───────────────────────────────────────────────

    public function test_staff_calls_are_recorded_once_per_window_and_writes_always(): void
    {
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);
        $this->logRow();

        $this->getJson('/api/v1/admin/activity-logs?token=abc123&page=1')->assertOk();
        $this->getJson('/api/v1/admin/activity-logs?token=abc123&page=1')->assertOk();   // same URL, inside the window

        $gets = DB::table('request_logs')->where('user_id', $admin->id)->where('method', 'GET')->get();
        $this->assertCount(1, $gets);
        $this->assertSame('/api/v1/admin/activity-logs', $gets[0]->path);
        $this->assertSame(200, (int) $gets[0]->status);
        $this->assertGreaterThanOrEqual(1, (int) $gets[0]->rows_returned);
        $this->assertStringContainsString('[REDACTED]', $gets[0]->query);
        $this->assertStringNotContainsString('abc123', $gets[0]->query);

        $this->postJson('/api/v1/admin/activity-logs/clear', ['days' => 30])->assertStatus(403);
        $this->postJson('/api/v1/admin/activity-logs/clear', ['days' => 30])->assertStatus(403);
        $this->assertSame(2, DB::table('request_logs')->where('user_id', $admin->id)->where('method', 'POST')->count());
    }

    public function test_customers_are_not_recorded_as_staff_calls(): void
    {
        $customer = User::factory()->customer()->create();
        Sanctum::actingAs($customer);

        $this->getJson('/api/v1/admin/activity-logs');   // refused, and not a staff call

        $this->assertSame(0, DB::table('request_logs')->where('user_id', $customer->id)->count());
    }

    // ── 5. the trail is the owner's, and it cannot be cleared ───────────────

    public function test_only_super_admins_read_the_trail(): void
    {
        $viewer = User::factory()->create(['status' => 'active']);
        $viewer->givePermissionTo(Permission::findOrCreate('users.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/admin/activity-logs')->assertStatus(403);
        $this->getJson('/api/v1/admin/activity-logs/requests')->assertStatus(403);

        Sanctum::actingAs($this->superAdmin());
        $this->getJson('/api/v1/admin/activity-logs')->assertOk();
        $this->getJson('/api/v1/admin/activity-logs/requests')->assertOk();
        $this->getJson('/api/v1/admin/activity-logs/integrity')->assertOk()->assertJsonStructure(['tables' => ['activity_log', 'request_logs']]);
    }

    public function test_clear_deletes_nothing_and_records_the_attempt(): void
    {
        $old = $this->logRow(['created_at' => now()->subYears(3)]);
        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/v1/admin/activity-logs/clear', ['days' => 30])->assertStatus(403);

        $this->assertDatabaseHas('activity_log', ['id' => $old]);
        $this->assertDatabaseHas('activity_log', ['event' => 'audit_clear_refused']);
    }

    public function test_one_records_history_by_short_type_name_and_nothing_else(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $product = Product::factory()->create(['slug' => 'first-slug']);
        $product->update(['slug' => 'second-slug']);

        $events = collect($this->getJson("/api/v1/admin/activity-logs/record/product/{$product->id}")
            ->assertOk()->json('data'))->pluck('event');
        $this->assertContains('created', $events);
        $this->assertContains('updated', $events);
        $this->getJson("/api/v1/admin/activity-logs/record/user-token/{$product->id}")->assertNotFound();
    }

    public function test_the_weekly_purge_keeps_the_activity_log_and_prunes_old_request_logs(): void
    {
        $oldActivity = $this->logRow(['created_at' => now()->subYears(3)]);
        $oldRequest  = DB::table('request_logs')->insertGetId(['method' => 'GET', 'path' => '/a', 'status' => 200, 'occurred_at' => now()->subDays(400)]);
        $newRequest  = DB::table('request_logs')->insertGetId(['method' => 'GET', 'path' => '/b', 'status' => 200, 'occurred_at' => now()->subDays(10)]);

        $this->artisan('logs:purge-old')->assertSuccessful();

        $this->assertDatabaseHas('activity_log', ['id' => $oldActivity]);
        $this->assertDatabaseMissing('request_logs', ['id' => $oldRequest]);
        $this->assertDatabaseHas('request_logs', ['id' => $newRequest]);
    }

    public function test_the_database_tools_cannot_clear_or_wipe_the_trail(): void
    {
        foreach (DatabaseManagementService::AUDIT_TABLES as $t) {
            $this->assertArrayNotHasKey($t, DatabaseManagementService::CLEARABLE_TABLES, "{$t} must not be clearable");
            $this->assertContains($t, DatabaseManagementService::PRESERVED_TABLES, "{$t} must survive a wipe");
        }
    }

    public function test_a_restore_leaves_every_audit_object_alone(): void
    {
        $toc = implode("\n", [
            ';',
            '; Archive created at 2026-09-21',
            '215; 1259 16390 TABLE public activity_log bethany',
            '216; 1259 16391 TABLE public orders bethany',
            '3890; 0 16390 TABLE DATA public activity_log bethany',
            '3891; 0 16391 TABLE DATA public orders bethany',
            '214; 1259 16389 SEQUENCE public activity_log_id_seq bethany',
            '3892; 0 0 SEQUENCE SET public activity_log_id_seq bethany',
            '3893; 0 0 SEQUENCE SET public orders_id_seq bethany',
            '3610; 1259 16400 INDEX public activity_log_created_at_index bethany',
            '3700; 2620 16500 TRIGGER public activity_log activity_log_append_only bethany',
            '300; 1255 16450 FUNCTION public audit_append_only() bethany',
            '3894; 0 16392 TABLE DATA public request_logs bethany',
            '3895; 0 16393 TABLE DATA public audit_seals bethany',
            '3896; 0 16394 TABLE DATA public activity_logs_archive bethany',
        ]);

        $list = DatabaseManagementService::auditPreservingRestoreList($toc);
        $kept = array_values(array_filter(explode("\n", $list), fn ($l) => $l !== '' && $l[0] !== ';'));

        $this->assertSame([
            '216; 1259 16391 TABLE public orders bethany',
            '3891; 0 16391 TABLE DATA public orders bethany',
            '3893; 0 0 SEQUENCE SET public orders_id_seq bethany',
            '3896; 0 16394 TABLE DATA public activity_logs_archive bethany',   // not an audit table
        ], $kept);
    }

    // ── 6. tampering is detected ────────────────────────────────────────────

    public function test_the_seal_chain_verifies_and_catches_an_edit_and_a_deletion(): void
    {
        $sealer = app(AuditSealer::class);
        $a = $this->logRow(['description' => 'first']);
        $b = $this->logRow(['description' => 'second']);
        $this->assertNotNull($sealer->seal('activity_log'));
        $this->logRow(['description' => 'third']);
        $this->assertNotNull($sealer->seal('activity_log'));

        $this->assertTrue($sealer->verify('activity_log')['ok']);

        // Someone with database access drops the protection and edits a row.
        DB::statement('ALTER TABLE activity_log DISABLE TRIGGER activity_log_append_only');
        DB::table('activity_log')->where('id', $a)->update(['description' => 'rewritten']);
        $r = $sealer->verify('activity_log');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('content changed', $r['failures'][0]['problem']);

        DB::table('activity_log')->where('id', $a)->update(['description' => 'first']);
        DB::table('activity_log')->where('id', $b)->delete();
        $r = $sealer->verify('activity_log');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('row count', $r['failures'][0]['problem']);
        DB::statement('ALTER TABLE activity_log ENABLE TRIGGER activity_log_append_only');
    }

    public function test_a_later_schema_change_does_not_false_alarm_on_old_seals(): void
    {
        $sealer = app(AuditSealer::class);
        $this->logRow();
        $sealer->seal('activity_log');

        DB::statement('ALTER TABLE activity_log ADD COLUMN future_column text');

        $this->assertTrue($sealer->verify('activity_log')['ok']);
    }

    public function test_rows_younger_than_the_settle_window_wait_for_the_next_seal(): void
    {
        $this->logRow(['created_at' => now()]);   // may still be inside an uncommitted neighbour's window

        $this->assertNull(app(AuditSealer::class)->seal('activity_log'));
    }

    // ── 7. settings and logins ──────────────────────────────────────────────

    public function test_settings_record_old_and_new_and_never_a_secret_value(): void
    {
        // (the migrations seed these keys; set known values)
        DB::table('settings')->updateOrInsert(['key' => 'mpesa_shortcode'], ['value' => '111111']);
        DB::table('settings')->updateOrInsert(['key' => 'mpesa_passkey'], ['value' => 'old-passkey']);
        $before = ActivityLogService::settingsSnapshot(['mpesa_shortcode', 'mpesa_passkey']);

        ActivityLogService::settingsSaved($before, ['mpesa_shortcode' => '999999', 'mpesa_passkey' => 'new-passkey'], 'payment credentials (mpesa)');

        $row = DB::table('activity_log')->where('event', 'settings_updated')->latest('id')->first();
        $changes = json_decode($row->properties, true)['changes'];
        $this->assertEquals(['old' => '111111', 'new' => '999999'], $changes['mpesa_shortcode']);
        // Recorded as changed, value hidden (the whole old/new pair is redacted).
        $this->assertSame('[REDACTED]', $changes['mpesa_passkey']);
        $this->assertStringNotContainsString('new-passkey', $row->properties);
        $this->assertStringNotContainsString('old-passkey', $row->properties);
    }

    public function test_the_general_settings_save_is_on_the_trail(): void
    {
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);
        DB::table('settings')->updateOrInsert(['key' => 'maintenance_mode'], ['value' => '0']);

        $this->putJson('/api/v1/admin/settings', ['maintenance_mode' => true])->assertSuccessful();

        $row = DB::table('activity_log')->where('event', 'settings_updated')->latest('id')->first();
        $this->assertNotNull($row, 'the general settings save must be recorded');
        $this->assertEquals(['old' => '0', 'new' => '1'], json_decode($row->properties, true)['changes']['maintenance_mode']);
        $this->assertSame($admin->id, (int) $row->causer_id);
    }

    public function test_a_refused_admin_login_is_recorded_without_the_password(): void
    {
        $user = User::factory()->create(['email' => 'priest@example.com', 'status' => 'active']);

        $this->postJson('/api/v1/admin/auth/login', ['email' => 'priest@example.com', 'password' => 'wrong-guess'])
            ->assertStatus(422);

        $row = DB::table('activity_log')->where('event', 'admin_login_failed')->latest('id')->first();
        $this->assertNotNull($row);
        $props = json_decode($row->properties, true);
        $this->assertSame('wrong_password', $props['reason']);
        $this->assertSame('priest@example.com', $props['attempted_email']);
        $this->assertSame($user->id, (int) $row->subject_id);
        $this->assertNull($row->causer_id);
        $this->assertStringNotContainsString('wrong-guess', $row->properties);
    }
}
