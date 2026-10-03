<?php

namespace Tests\Feature;

use App\Mail\NewSignInDeviceMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\SignsInForReal;
use Tests\TestCase;

/**
 * Plan §12.4: a sign-in from a new device is flagged on the audit trail and
 * the person is emailed; with two-step sign-in on, it is asked for whatever
 * the device (there is no "remembered device" bypass).
 */
class SuspiciousSignInTest extends TestCase
{
    use RefreshDatabase, SignsInForReal;

    private const CHROME_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
    private const FIREFOX_WIN = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function signIn(User $u, string $agent, string $ip, array $extraHeaders = [])
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders(array_merge(['User-Agent' => $agent], $extraHeaders))
            ->postJson('/api/v1/admin/auth/login', ['email' => $u->email, 'password' => 'password']);
    }

    public function test_the_first_sign_in_learns_the_device_without_a_flag(): void
    {
        Mail::fake();
        $u = $this->staffWithRoles(['admin']);

        $this->signIn($u, self::CHROME_MAC, '41.90.10.5')->assertOk();

        Mail::assertNothingQueued();
        $this->assertDatabaseMissing('activity_log', ['event' => 'suspicious_sign_in']);
        $this->assertDatabaseHas('login_devices', ['user_id' => $u->id, 'agent_family' => 'Chrome on macOS', 'network' => '41.90.10.0/24']);
    }

    public function test_the_same_browser_on_the_same_network_is_not_new(): void
    {
        Mail::fake();
        $u = $this->staffWithRoles(['admin']);
        $this->signIn($u, self::CHROME_MAC, '41.90.10.5')->assertOk();

        $this->signIn($u, self::CHROME_MAC, '41.90.10.77')->assertOk();

        Mail::assertNothingQueued();
    }

    public function test_a_new_device_is_flagged_and_the_person_emailed(): void
    {
        Mail::fake();
        $u = $this->staffWithRoles(['admin']);
        $this->signIn($u, self::CHROME_MAC, '41.90.10.5')->assertOk();

        $this->signIn($u, self::FIREFOX_WIN, '102.68.1.9', ['CF-IPCountry' => 'NG'])->assertOk();

        Mail::assertQueued(NewSignInDeviceMail::class, fn ($m) => $m->hasTo($u->email) && $m->device === 'Firefox on Windows' && $m->country === 'NG');
        $this->assertDatabaseHas('activity_log', ['event' => 'suspicious_sign_in', 'subject_id' => $u->id]);
    }

    public function test_a_password_alone_never_makes_a_device_known(): void
    {
        Mail::fake();
        $g = new Google2FA();
        $secret = $g->generateSecretKey();
        $u = $this->staffWithRoles(['admin'], ['two_factor_enabled' => true, 'two_factor_secret' => encrypt($secret)]);
        // Known device from an earlier complete sign-in.
        $step = $this->signIn($u, self::CHROME_MAC, '41.90.10.5')->json();
        $this->withServerVariables(['REMOTE_ADDR' => '41.90.10.5'])->withHeaders(['User-Agent' => self::CHROME_MAC])
            ->postJson('/api/v1/admin/auth/2fa/verify', ['user_id' => $u->id, 'challenge' => $step['challenge'], 'code' => $g->getCurrentOtp($secret)])
            ->assertOk();

        // The attacker has the password, not the phone. 2FA is still asked for.
        $this->signIn($u, self::FIREFOX_WIN, '102.68.1.9')->assertOk()->assertJson(['requires_2fa' => true])->assertJsonMissing(['token']);
        Mail::assertQueued(NewSignInDeviceMail::class, 1);

        // Trying again from the same device is still a new device: it was never learnt.
        $this->signIn($u, self::FIREFOX_WIN, '102.68.1.9')->assertOk()->assertJson(['requires_2fa' => true]);
        Mail::assertQueued(NewSignInDeviceMail::class, 2);
        $this->assertDatabaseCount('login_devices', 1);
    }

    public function test_device_families_and_networks(): void
    {
        $this->assertSame('Chrome on macOS', \App\Services\Auth\LoginDevices::agentFamily(self::CHROME_MAC));
        $this->assertSame('Firefox on Windows', \App\Services\Auth\LoginDevices::agentFamily(self::FIREFOX_WIN));
        $this->assertSame('41.90.10.0/24', \App\Services\Auth\LoginDevices::network('41.90.10.200'));
        $this->assertSame('2001:db8:85a3::/48', \App\Services\Auth\LoginDevices::network('2001:db8:85a3:8d3:1319:8a2e:370:7348'));
    }
}
