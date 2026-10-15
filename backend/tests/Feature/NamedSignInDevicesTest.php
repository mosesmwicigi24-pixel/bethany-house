<?php

namespace Tests\Feature;

use App\Mail\NewSignInDeviceMail;
use App\Models\User;
use App\Services\Auth\Devices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SignsInForReal;
use Tests\TestCase;

/**
 * Named sign-in devices (owner, 2026-10-15): "I want to know Moses logged in
 * on Moses' laptop", not "Chrome on iOS". The device type comes from what the
 * browser reports (plus its touch screen, for an iPad posing as a Mac); the
 * name from the person or, winning, an administrator. A name never makes a
 * sign-in trusted: the new-device alarm is unchanged.
 */
class NamedSignInDevicesTest extends TestCase
{
    use RefreshDatabase, SignsInForReal;

    // Real user-agent strings, as the browsers send them.
    private const IPHONE_CHROME = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/126.0.6478.54 Mobile/15E148 Safari/604.1';
    private const IPAD_SAFARI   = 'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
    private const MAC_SAFARI    = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';
    private const MAC_CHROME    = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
    private const ANDROID_PHONE = 'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36';
    private const ANDROID_TAB   = 'Mozilla/5.0 (Linux; Android 13; SM-X200) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
    private const WINDOWS_EDGE  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Edg/126.0.0.0';
    private const CHROMEBOOK    = 'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
    private const LINUX_FIREFOX = 'Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0';

    private const LAPTOP_KEY = 'a3f1c2d4-5b6e-4f70-8a91-b2c3d4e5f601';
    private const PHONE_KEY  = 'b4e2d3c5-6c7f-4081-9ba2-c3d4e5f60712';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function signIn(User $u, string $agent, string $ip, ?string $key, int $touch = 0)
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $headers = ['User-Agent' => $agent, 'X-Device-Touch' => (string) $touch];
        if ($key !== null) {
            $headers['X-Device-Id'] = $key;
        }

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->withHeaders($headers)
            ->postJson('/api/v1/admin/auth/login', ['email' => $u->email, 'password' => 'password']);
    }

    private function deviceId(string $key): int
    {
        return (int) DB::table('devices')->where('key_hash', hash('sha256', $key))->value('id');
    }

    public function test_the_device_type_is_named_from_what_the_browser_reports(): void
    {
        $cases = [
            [self::IPHONE_CHROME, 5, 'iPhone · Chrome'],
            [self::IPAD_SAFARI,   5, 'iPad · Safari'],
            // iPadOS Safari asks for the desktop site as a Mac: its touch screen gives it away.
            [self::MAC_SAFARI,    5, 'iPad · Safari'],
            [self::MAC_SAFARI,    0, 'Mac · Safari'],
            [self::MAC_CHROME,    0, 'Mac · Chrome'],
            [self::ANDROID_PHONE, 5, 'Android phone · Chrome'],
            [self::ANDROID_TAB,   5, 'Android tablet · Chrome'],
            [self::WINDOWS_EDGE,  0, 'Windows PC · Edge'],
            // A Windows laptop with a touch screen is still a Windows PC.
            [self::WINDOWS_EDGE, 10, 'Windows PC · Edge'],
            [self::CHROMEBOOK,    0, 'Chromebook · Chrome'],
            [self::LINUX_FIREFOX, 0, 'Linux computer · Firefox'],
            ['',                  0, 'Unknown device · Unknown browser'],
        ];
        foreach ($cases as [$ua, $touch, $expected]) {
            $this->assertSame($expected, Devices::facts($ua, $touch), $ua ?: '(empty agent)');
        }
    }

    public function test_a_sign_in_registers_the_device_and_the_console_asks_for_a_name(): void
    {
        Mail::fake();
        $u = $this->staffWithRoles(['admin'], ['first_name' => 'Moses']);

        $res = $this->signIn($u, self::MAC_CHROME, '41.90.10.5', self::LAPTOP_KEY)->assertOk();

        $res->assertJsonPath('user.current_device.label', 'Mac · Chrome')
            ->assertJsonPath('user.current_device.needs_name', true);
        $id = $this->deviceId(self::LAPTOP_KEY);
        $this->assertGreaterThan(0, $id);
        // The key itself is never stored.
        $this->assertDatabaseMissing('devices', ['key_hash' => self::LAPTOP_KEY]);
        $this->assertDatabaseHas('device_user', ['device_id' => $id, 'user_id' => $u->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $u->id, 'device_id' => $id]);
        $this->assertDatabaseHas('login_devices', ['user_id' => $u->id, 'device_id' => $id]);

        // The person names it; /me says so from then on.
        $token = $res->json('token');
        $this->withBearer($token, 'PUT', "/api/v1/admin/profile/devices/{$id}", ['name' => "Moses' MacBook"])->assertOk()
            ->assertJsonPath('device.label', "Moses' MacBook (Mac · Chrome)");
        $this->withBearer($token, 'GET', '/api/v1/admin/auth/me')->assertOk()
            ->assertJsonPath('user.current_device.name', "Moses' MacBook")
            ->assertJsonPath('user.current_device.needs_name', false)
            ->assertJsonPath('user.current_device.name_source', 'self');
        $this->assertDatabaseHas('activity_log', ['event' => 'device_named', 'subject_id' => $u->id, 'causer_id' => $u->id]);

        $list = $this->withBearer($token, 'GET', '/api/v1/admin/profile/devices')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertTrue($list[0]['is_current']);
        $this->assertSame(1, $list[0]['active_sessions']);
    }

    public function test_the_alarm_email_names_a_known_device_on_a_new_network_and_says_what_a_new_one_is(): void
    {
        Mail::fake();
        $u = $this->staffWithRoles(['admin'], ['first_name' => 'Moses', 'last_name' => 'Mwicigi']);
        $token = $this->signIn($u, self::MAC_CHROME, '41.90.10.5', self::LAPTOP_KEY)->assertOk()->json('token');
        $id = $this->deviceId(self::LAPTOP_KEY);
        $this->withBearer($token, 'PUT', "/api/v1/admin/profile/devices/{$id}", ['name' => "Moses' MacBook"])->assertOk();

        // The same laptop at home: a network not seen before. Still flagged — a key is not proof — but named.
        $this->signIn($u, self::MAC_CHROME, '105.160.114.77', self::LAPTOP_KEY)->assertOk();
        Mail::assertQueued(NewSignInDeviceMail::class, fn ($m) => $m->knownDevice
            && $m->device === "Moses' MacBook (Mac · Chrome)"
            && $m->account === "Moses Mwicigi ({$u->email})");
        $this->assertDatabaseHas('activity_log', ['event' => 'suspicious_sign_in', 'subject_id' => $u->id]);
        $flag = DB::table('activity_log')->where('event', 'suspicious_sign_in')->latest('id')->value('properties');
        $this->assertStringContainsString('new_network', (string) $flag);

        // A phone never seen before: the facts, and "not yet named".
        $this->signIn($u, self::IPHONE_CHROME, '102.68.1.9', self::PHONE_KEY, 5)->assertOk();
        Mail::assertQueued(NewSignInDeviceMail::class, function ($m) {
            if ($m->knownDevice || $m->device !== 'iPhone · Chrome') {
                return false;
            }
            $html = $m->render();
            $this->assertStringContainsString('not yet named', $html);
            $this->assertStringContainsString('Moses Mwicigi', $html);
            $this->assertStringContainsString('a device we have not seen you sign in from before', $html);

            return true;
        });
    }

    public function test_a_key_from_a_device_the_account_never_signed_in_on_is_not_named_in_the_email(): void
    {
        Mail::fake();
        $moses = $this->staffWithRoles(['admin']);
        $clerk = $this->staffWithRoles(['pos_clerk']);
        $this->signIn($moses, self::MAC_CHROME, '41.90.10.5', self::PHONE_KEY)->assertOk();
        $this->signIn($clerk, self::MAC_CHROME, '41.90.10.5', self::LAPTOP_KEY)->assertOk();
        DB::table('devices')->where('id', $this->deviceId(self::LAPTOP_KEY))->update(['name' => 'Till 1', 'name_source' => 'admin']);

        // Someone with Moses's password on the clerk's till, from elsewhere: the till's name is not his to borrow.
        $this->signIn($moses, self::MAC_CHROME, '102.68.1.9', self::LAPTOP_KEY)->assertOk();
        Mail::assertQueued(NewSignInDeviceMail::class, fn ($m) => $m->hasTo($moses->email) && !$m->knownDevice && $m->device === 'Mac · Chrome');
    }

    public function test_an_administrators_name_wins_and_the_person_cannot_change_it(): void
    {
        $owner = $this->staffWithRoles(['super_admin'], ['first_name' => 'Moses', 'last_name' => 'Mwicigi']);
        $clerk = $this->staffWithRoles(['pos_clerk']);
        $clerkToken = $this->signIn($clerk, self::ANDROID_TAB, '41.90.10.5', self::LAPTOP_KEY, 5)->assertOk()->json('token');
        $id = $this->deviceId(self::LAPTOP_KEY);

        $ownerToken = $this->bearerFor($owner);
        $this->withBearer($ownerToken, 'PUT', "/api/v1/admin/users/{$clerk->id}/devices/{$id}", ['name' => 'Till 1 — Nairobi shop'])
            ->assertOk()->assertJsonPath('device.label', 'Till 1 — Nairobi shop (Android tablet · Chrome)');

        $this->withBearer($clerkToken, 'PUT', "/api/v1/admin/profile/devices/{$id}", ['name' => 'My tablet'])->assertForbidden();
        $this->assertDatabaseHas('devices', ['id' => $id, 'name' => 'Till 1 — Nairobi shop', 'name_source' => 'admin', 'named_by' => $owner->id]);

        $row = $this->withBearer($ownerToken, 'GET', "/api/v1/admin/users/{$clerk->id}/devices")->assertOk()->json('data.0');
        $this->assertSame('Moses Mwicigi', $row['named_by']);
        $this->assertSame('admin', $row['name_source']);
        $this->assertDatabaseHas('activity_log', ['event' => 'device_named', 'subject_id' => $clerk->id, 'causer_id' => $owner->id]);
    }

    public function test_removing_a_device_ends_its_sessions_and_its_next_sign_in_raises_the_alarm_again(): void
    {
        Mail::fake();
        $owner = $this->staffWithRoles(['super_admin']);
        $u     = $this->staffWithRoles(['admin']);
        $other = $this->staffWithRoles(['pos_clerk']);
        $laptop = $this->signIn($u, self::MAC_CHROME, '41.90.10.5', self::LAPTOP_KEY)->assertOk()->json('token');
        $phone  = $this->signIn($u, self::IPHONE_CHROME, '41.90.10.5', self::PHONE_KEY, 5)->assertOk()->json('token');
        // A shared machine: someone else signs in on the same phone.
        $this->signIn($other, self::IPHONE_CHROME, '41.90.10.5', self::PHONE_KEY, 5)->assertOk();
        $before = Mail::queued(NewSignInDeviceMail::class, fn ($m) => $m->hasTo($u->email))->count();
        $phoneId = $this->deviceId(self::PHONE_KEY);

        $this->withBearer($this->bearerFor($owner), 'DELETE', "/api/v1/admin/users/{$u->id}/devices/{$phoneId}")
            ->assertOk()->assertJson(['message' => 'Device removed. 1 session(s) on it ended.']);

        $this->withBearer($phone, 'GET', '/api/v1/admin/auth/me')->assertStatus(401);
        $this->withBearer($laptop, 'GET', '/api/v1/admin/auth/me')->assertOk();
        $this->assertDatabaseMissing('device_user', ['device_id' => $phoneId, 'user_id' => $u->id]);
        // The other person's link and session on the shared phone are untouched.
        $this->assertDatabaseHas('device_user', ['device_id' => $phoneId, 'user_id' => $other->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $other->id, 'device_id' => $phoneId]);
        $this->assertDatabaseHas('activity_log', ['event' => 'device_removed', 'subject_id' => $u->id, 'causer_id' => $owner->id]);

        // The phone signs in again: the alarm sounds, as for any new device.
        $this->signIn($u, self::IPHONE_CHROME, '41.90.10.5', self::PHONE_KEY, 5)->assertOk();
        $after = Mail::queued(NewSignInDeviceMail::class, fn ($m) => $m->hasTo($u->email));
        $this->assertCount($before + 1, $after);
        $this->assertFalse($after->last()->knownDevice, 'a removed device is a stranger again');
    }

    public function test_who_may_see_name_and_remove_devices(): void
    {
        $sys   = $this->systemAdmin();
        $om    = $this->staffWithRoles(['outlet_manager']);
        $clerk = $this->staffWithRoles(['pos_clerk']);
        $fm    = $this->staffWithRoles(['finance_manager']);
        $clerkToken = $this->signIn($clerk, self::ANDROID_PHONE, '41.90.10.5', self::PHONE_KEY, 5)->assertOk()->json('token');
        $this->signIn($fm, self::MAC_CHROME, '41.90.10.5', self::LAPTOP_KEY)->assertOk();
        $clerkDevice = $this->deviceId(self::PHONE_KEY);
        $fmDevice    = $this->deviceId(self::LAPTOP_KEY);

        // A system admin manages a clerk's devices, not a Tier 1 manager's (StaffAuthority).
        $t = $this->bearerFor($sys);
        $this->withBearer($t, 'GET', "/api/v1/admin/users/{$clerk->id}/devices")->assertOk()->assertJsonCount(1, 'data');
        $this->withBearer($t, 'GET', "/api/v1/admin/users/{$fm->id}/devices")->assertForbidden();
        $this->withBearer($t, 'PUT', "/api/v1/admin/users/{$fm->id}/devices/{$fmDevice}", ['name' => 'x laptop'])->assertForbidden();
        $this->withBearer($t, 'DELETE', "/api/v1/admin/users/{$fm->id}/devices/{$fmDevice}")->assertForbidden();

        // An outlet manager may not manage anyone's sign-in.
        $this->withBearer($this->bearerFor($om), 'GET', "/api/v1/admin/users/{$clerk->id}/devices")->assertForbidden();

        // A device must be one the person signed in on.
        $this->withBearer($t, 'PUT', "/api/v1/admin/users/{$clerk->id}/devices/{$fmDevice}", ['name' => 'Till 2'])->assertNotFound();
        $this->withBearer($clerkToken, 'PUT', "/api/v1/admin/profile/devices/{$fmDevice}", ['name' => 'Mine now'])->assertNotFound();
        $this->assertDatabaseHas('devices', ['id' => $fmDevice, 'name' => null]);

        // Nobody removes the device they are using from under themselves.
        $this->withBearer($clerkToken, 'DELETE', "/api/v1/admin/profile/devices/{$clerkDevice}")->assertStatus(422);
    }

    public function test_without_a_device_key_sign_in_works_as_before(): void
    {
        Mail::fake();
        $u = $this->staffWithRoles(['admin']);

        // An older console (no key) and a malformed key are both "no device".
        $this->signIn($u, self::MAC_CHROME, '41.90.10.5', null)->assertOk()->assertJsonPath('user.current_device', null);
        $this->signIn($u, self::MAC_CHROME, '41.90.10.5', 'short')->assertOk()->assertJsonPath('user.current_device', null);
        $this->signIn($u, self::MAC_CHROME, '41.90.10.5', str_repeat('x', 16) . '<script>')->assertOk();

        $this->assertDatabaseCount('devices', 0);
        $this->assertDatabaseHas('login_devices', ['user_id' => $u->id, 'device_id' => null]);
        Mail::assertNothingQueued();
    }
}
