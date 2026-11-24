<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * service-accounts:grant-history-reader (owner, 2026-11-24). One role, to
 * only the configured service accounts, beside the role they hold; a dry run
 * unless a super admin's id is given; counts only in the output.
 */
class GrantCustomerHistoryReaderTest extends TestCase
{
    use RefreshDatabase;

    private User $bot;
    private User $owner;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        config(['security.service_accounts' => ['test-bot@example.test']]);

        $this->bot     = $this->person('Test-Bot@example.test', 'pos_clerk');
        $this->owner   = $this->person('owner@example.test', 'super_admin');
        $this->cashier = $this->person('cashier@example.test', 'pos_clerk');
    }

    private function person(string $email, string $role): User
    {
        $u = User::factory()->create(['email' => $email, 'status' => 'active', 'user_type' => 'staff']);
        $u->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function run_(array $args = []): array
    {
        $code = Artisan::call('service-accounts:grant-history-reader', $args);

        return [$code, Artisan::output()];
    }

    public function test_a_dry_run_counts_and_changes_nothing(): void
    {
        [$code, $out] = $this->run_();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Service accounts found: 1', $out);
        $this->assertStringContainsString('Already hold the role: 0', $out);
        $this->assertStringContainsString('Would be granted: 1', $out);
        $this->assertFalse($this->bot->fresh()->hasRole('customer_history_reader'));
        $this->assertStringNotContainsString('example.test', $out, 'counts only');
    }

    public function test_apply_grants_it_beside_the_existing_role_once_and_audits_it(): void
    {
        [$code, $out] = $this->run_(['--apply' => true, '--actor' => $this->owner->id]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Granted now: 1', $out);
        $this->assertEqualsCanonicalizing(['customer_history_reader', 'pos_clerk'], $this->bot->fresh()->getRoleNames()->all());

        // Nobody else gained anything.
        $this->assertSame(['pos_clerk'], $this->cashier->fresh()->getRoleNames()->all());
        $this->assertSame(1, DB::table('model_has_roles')
            ->where('role_id', Role::findByName('customer_history_reader', 'sanctum')->id)->count());

        $log = DB::table('activity_log')->where('action', 'service_account_role_granted')->get();
        $this->assertCount(1, $log);

        // Again: nothing changes, nothing more on the trail.
        [, $again] = $this->run_(['--apply' => true, '--actor' => $this->owner->id]);
        $this->assertStringContainsString('Already hold the role: 1', $again);
        $this->assertStringContainsString('Granted now: 0', $again);
        $this->assertSame(1, DB::table('activity_log')->where('action', 'service_account_role_granted')->count());
    }

    public function test_apply_is_refused_without_an_active_super_admin(): void
    {
        foreach ([[], ['--actor' => $this->cashier->id], ['--actor' => 999999], ['--actor' => 'owner@example.test']] as $extra) {
            [$code] = $this->run_(['--apply' => true] + $extra);
            $this->assertSame(1, $code);
        }
        $this->assertFalse($this->bot->fresh()->hasRole('customer_history_reader'));
    }

    public function test_an_inactive_or_unlisted_account_is_never_granted(): void
    {
        $this->bot->forceFill(['status' => 'suspended'])->save();
        config(['security.service_accounts' => ['test-bot@example.test']]);

        [, $out] = $this->run_(['--apply' => true, '--actor' => $this->owner->id]);

        $this->assertStringContainsString('Service accounts found: 0', $out);
        $this->assertStringContainsString('Granted now: 0', $out);
        $this->assertFalse($this->bot->fresh()->hasRole('customer_history_reader'));
        $this->assertFalse($this->cashier->fresh()->hasRole('customer_history_reader'));
    }
}
