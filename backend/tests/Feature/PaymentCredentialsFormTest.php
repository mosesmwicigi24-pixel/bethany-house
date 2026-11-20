<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MpesaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Concerns\StepsUp;
use Tests\TestCase;

/**
 * Setup → Payment methods → credentials (2026-11-19). The form was shown a
 * row of dots for every saved value and wrote those dots back on save,
 * replacing the real keys. Now: a secret is never sent to the browser, a
 * blank (or the mask) keeps what is saved, and what is not secret — the
 * business name, shortcode, environment — is shown so it can be checked.
 */
class PaymentCredentialsFormTest extends TestCase
{
    use RefreshDatabase;
    use StepsUp;

    private int $mpesaId;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');

        $owner = User::factory()->create(['status' => 'active']);
        $owner->assignRole(Role::findByName('super_admin', 'sanctum'));
        Sanctum::actingAs($owner);
        $this->stepUp($owner);

        DB::table('payment_methods')->where('code', 'mpesa')->delete();
        $this->mpesaId = DB::table('payment_methods')->insertGetId([
            'code' => 'mpesa', 'name' => 'M-PESA', 'type' => 'mobile_money',
            'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('settings')->where('key', 'like', 'mpesa_%')->delete();
        config(['services.mpesa' => []]);
        MpesaService::invalidateCache();
    }

    private function save(array $config)
    {
        return $this->putJson("/api/v1/admin/payment-methods-management/{$this->mpesaId}/config", ['configuration' => $config]);
    }

    private function shown(): array
    {
        return $this->getJson("/api/v1/admin/payment-methods-management/{$this->mpesaId}")->assertOk()->json('payment_method')
            ?? $this->getJson("/api/v1/admin/payment-methods-management/{$this->mpesaId}")->json();
    }

    public function test_saved_keys_are_what_mpesa_runs_on_and_secrets_never_come_back(): void
    {
        $this->assertFalse(MpesaService::isConfigured());

        $this->save([
            'account_name' => 'Bethany House', 'shortcode' => '600123',
            'consumer_key' => 'CK-real-1', 'consumer_secret' => 'CS-real-1',
            'passkey' => 'PK-real-1', 'environment' => 'production',
        ])->assertOk();

        $creds = MpesaService::credentials();
        $this->assertSame('CK-real-1', $creds['consumer_key']);
        $this->assertSame('CS-real-1', $creds['consumer_secret']);
        $this->assertSame('600123', $creds['shortcode']);
        $this->assertSame('production', $creds['environment']);
        $this->assertSame('Bethany House', DB::table('settings')->where('key', 'mpesa_account_name')->value('value'));

        $shown = $this->shown();
        $this->assertSame('Bethany House', $shown['config']['account_name']);
        $this->assertSame('600123', $shown['config']['shortcode']);
        $this->assertSame('production', $shown['config']['environment']);
        foreach (['consumer_key', 'consumer_secret', 'passkey'] as $secret) {
            $this->assertSame('', $shown['config'][$secret], "{$secret} must not be sent to the browser");
            $this->assertTrue($shown['config_set'][$secret]);
        }
        $this->assertStringNotContainsString('real-1', json_encode($shown));
    }

    public function test_a_blank_or_masked_field_keeps_the_saved_key(): void
    {
        $this->save([
            'account_name' => 'Bethany House', 'shortcode' => '600123',
            'consumer_key' => 'CK-real-1', 'consumer_secret' => 'CS-real-1',
            'passkey' => 'PK-real-1', 'environment' => 'production',
        ])->assertOk();

        // The form as it used to come back, and as it comes back now.
        $this->save([
            'account_name' => 'Bethany House Ltd', 'shortcode' => '600123',
            'consumer_key' => '••••••••', 'consumer_secret' => '', 'passkey' => '  ',
            'environment' => 'production',
        ])->assertOk();

        $creds = MpesaService::credentials();
        $this->assertSame('CK-real-1', $creds['consumer_key']);
        $this->assertSame('CS-real-1', $creds['consumer_secret']);
        $this->assertSame('PK-real-1', $creds['passkey']);
        $this->assertSame('Bethany House Ltd', DB::table('settings')->where('key', 'mpesa_account_name')->value('value'));

        // A new key replaces the old one, and only that one.
        $this->save(['consumer_secret' => 'CS-real-2'])->assertOk();
        $this->assertSame('CS-real-2', MpesaService::credentials()['consumer_secret']);
        $this->assertSame('CK-real-1', MpesaService::credentials()['consumer_key']);
    }

    public function test_the_environment_is_sandbox_or_production_and_nothing_else(): void
    {
        $this->save(['environment' => 'live'])->assertStatus(422);
        $this->assertFalse(DB::table('settings')->where('key', 'mpesa_environment')->exists());
    }
}
