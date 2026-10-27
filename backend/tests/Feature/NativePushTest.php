<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\ApnsSender;
use App\Services\Push\FcmSender;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsInForReal;
use Tests\TestCase;

/**
 * Native apps Step 2: APNs and FCM registrations beside the existing Web
 * Push and Expo ones. Delivery is exercised against faked HTTP so no real
 * provider is touched; credentials in these tests are throwaway test keys.
 */
class NativePushTest extends TestCase
{
    use RefreshDatabase, SignsInForReal;

    private const SUBSCRIBE   = '/api/v1/admin/push/subscribe-device';
    private const UNSUBSCRIBE = '/api/v1/admin/push/unsubscribe-device';

    private function apnsConfigured(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        config([
            'push.apns.key_id'      => 'TESTKEY123',
            'push.apns.team_id'     => 'TEAM123456',
            'push.apns.private_key' => $pem,
            'push.apns.bundle_id'   => 'ke.co.bethanyhouse.hub',
            'push.apns.environment' => 'sandbox',
        ]);
    }

    private function fcmConfigured(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        config([
            'push.fcm.project_id'      => 'bethany-test',
            'push.fcm.service_account' => json_encode([
                'client_email' => 'push@bethany-test.iam.gserviceaccount.com',
                'private_key'  => $pem,
                'token_uri'    => 'https://oauth2.googleapis.com/token',
            ]),
        ]);
    }

    // ── Registration ─────────────────────────────────────────────────────────

    public function test_an_apple_device_registers_as_apns_and_an_android_one_as_fcm(): void
    {
        $u = $this->staffWithRoles(['admin']);
        $t = $this->bearerFor($u);

        $this->withBearer($t, 'POST', self::SUBSCRIBE, ['platform' => 'ios', 'token' => str_repeat('ab', 32), 'model' => 'iPhone15,2'],
            ['X-Device-Id' => 'device-0123456789abcdef'])
            ->assertCreated()->assertJsonPath('token_type', 'apns');
        $this->withBearer($t, 'POST', self::SUBSCRIBE, ['platform' => 'android', 'token' => 'fcm_token_ABC-123:xyz'])
            ->assertCreated()->assertJsonPath('token_type', 'fcm');

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $u->id, 'token_type' => 'apns', 'platform' => 'ios', 'device_model' => 'iPhone15,2',
            'device_id' => 'device-0123456789abcdef', 'endpoint' => 'apns:' . str_repeat('ab', 32), 'is_active' => true,
        ]);
        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $u->id, 'token_type' => 'fcm', 'platform' => 'android', 'device_token' => 'fcm_token_ABC-123:xyz',
        ]);
    }

    public function test_re_registering_the_same_token_moves_it_to_the_signed_in_person(): void
    {
        $a = $this->staffWithRoles(['admin']);
        $b = $this->staffWithRoles(['admin']);
        $token = str_repeat('cd', 32);

        $this->withBearer($this->bearerFor($a), 'POST', self::SUBSCRIBE, ['platform' => 'ipados', 'token' => $token])->assertCreated();
        $this->withBearer($this->bearerFor($b), 'POST', self::SUBSCRIBE, ['platform' => 'ipados', 'token' => $token])->assertCreated();

        $this->assertSame(1, PushSubscription::where('device_token', $token)->count());
        $this->assertSame($b->id, PushSubscription::where('device_token', $token)->first()->user_id);
    }

    public function test_registration_validates_platform_and_token(): void
    {
        $this->postJson(self::SUBSCRIBE, ['platform' => 'ios', 'token' => str_repeat('ab', 32)])->assertStatus(401);

        $t = $this->bearerFor($this->staffWithRoles(['admin']));
        $this->withBearer($t, 'POST', self::SUBSCRIBE, ['platform' => 'windows', 'token' => str_repeat('ab', 32)])->assertStatus(422);
        $this->withBearer($t, 'POST', self::SUBSCRIBE, ['platform' => 'ios', 'token' => 'bad token with spaces'])->assertStatus(422);
        $this->withBearer($t, 'POST', self::SUBSCRIBE, ['platform' => 'ios', 'token' => 'short'])->assertStatus(422);
    }

    public function test_unsubscribe_removes_only_that_persons_registration(): void
    {
        $a = $this->staffWithRoles(['admin']);
        $b = $this->staffWithRoles(['admin']);
        $this->withBearer($this->bearerFor($a), 'POST', self::SUBSCRIBE, ['platform' => 'android', 'token' => 'fcm-token-A-0001'])->assertCreated();
        $this->withBearer($this->bearerFor($b), 'POST', self::SUBSCRIBE, ['platform' => 'android', 'token' => 'fcm-token-B-0002'])->assertCreated();

        $this->withBearer($this->bearerFor($a), 'POST', self::UNSUBSCRIBE, ['token' => 'fcm-token-B-0002'])->assertOk();
        $this->assertSame(1, PushSubscription::where('device_token', 'fcm-token-B-0002')->count(), 'not theirs; untouched');

        $this->withBearer($this->bearerFor($a), 'POST', self::UNSUBSCRIBE, ['token' => 'fcm-token-A-0001'])->assertOk();
        $this->assertSame(0, PushSubscription::where('device_token', 'fcm-token-A-0001')->count());
    }

    // ── Delivery ─────────────────────────────────────────────────────────────

    public function test_unconfigured_senders_skip_delivery_without_calling_out(): void
    {
        config(['push.apns.key_id' => null, 'push.fcm.project_id' => null]);
        Http::fake();
        $u = User::factory()->create();
        PushSubscription::create(['user_id' => $u->id, 'endpoint' => 'apns:x', 'p256dh' => '', 'auth' => '', 'device_token' => 'x', 'token_type' => 'apns', 'platform' => 'ios']);
        PushSubscription::create(['user_id' => $u->id, 'endpoint' => 'fcm:y', 'p256dh' => '', 'auth' => '', 'device_token' => 'y', 'token_type' => 'fcm', 'platform' => 'android']);

        WebPushService::send($u->id, 'Hi', 'there', '/me');

        Http::assertNothingSent();
        $this->assertSame(2, PushSubscription::active()->count());
    }

    public function test_browser_push_is_skipped_when_no_vapid_pair_is_configured(): void
    {
        config(['webpush.vapid.public_key' => '', 'webpush.vapid.private_key' => '']);
        Http::fake();
        $u = User::factory()->create();
        PushSubscription::create(['user_id' => $u->id, 'endpoint' => 'https://push.example/abc', 'p256dh' => 'k', 'auth' => 'a', 'token_type' => 'web']);

        WebPushService::send($u->id, 'Hi', 'there', '/me');

        Http::assertNothingSent();
        $this->assertTrue(PushSubscription::first()->is_active, 'a missing key is not a dead subscription');
    }

    public function test_webpush_vapid_command_prints_a_usable_pair(): void
    {
        $this->artisan('webpush:vapid')
            ->expectsOutputToContain('VAPID_PUBLIC_KEY=')
            ->expectsOutputToContain('VAPID_PRIVATE_KEY=')
            ->assertSuccessful();
    }

    public function test_apns_delivery_posts_to_apple_with_the_provider_jwt_and_topic(): void
    {
        $this->apnsConfigured();
        Cache::flush();
        Http::fake(['api.sandbox.push.apple.com/*' => Http::response('', 200)]);
        $u = User::factory()->create();
        PushSubscription::create(['user_id' => $u->id, 'endpoint' => 'apns:' . str_repeat('ef', 32), 'p256dh' => '', 'auth' => '', 'device_token' => str_repeat('ef', 32), 'token_type' => 'apns', 'platform' => 'ios']);

        WebPushService::send($u->id, 'Leave approved', 'Your leave was approved', '/me/leave', 'calendar', ['leave_id' => 7]);

        Http::assertSent(function ($request) {
            $jwt = substr($request->header('Authorization')[0], 7);
            [$h, $c] = explode('.', $jwt);
            $header = json_decode(base64_decode(strtr($h, '-_', '+/')), true);
            $claims = json_decode(base64_decode(strtr($c, '-_', '+/')), true);

            return str_ends_with($request->url(), '/3/device/' . str_repeat('ef', 32))
                && $request->header('apns-topic')[0] === 'ke.co.bethanyhouse.hub'
                && $request->header('apns-push-type')[0] === 'alert'
                && $header['alg'] === 'ES256' && $header['kid'] === 'TESTKEY123'
                && $claims['iss'] === 'TEAM123456'
                && $request['aps']['alert']['title'] === 'Leave approved'
                && $request['url'] === '/me/leave'
                && $request['data']['leave_id'] === 7;
        });
    }

    public function test_apns_bad_device_token_marks_the_registration_inactive(): void
    {
        $this->apnsConfigured();
        Cache::flush();
        Http::fake(['api.sandbox.push.apple.com/*' => Http::response(['reason' => 'BadDeviceToken'], 400)]);
        $u = User::factory()->create();
        $sub = PushSubscription::create(['user_id' => $u->id, 'endpoint' => 'apns:dead', 'p256dh' => '', 'auth' => '', 'device_token' => 'dead', 'token_type' => 'apns', 'platform' => 'ios']);

        app(ApnsSender::class)->send($sub, 'T', 'B');

        $this->assertFalse($sub->fresh()->is_active);
    }

    public function test_fcm_delivery_exchanges_a_service_account_jwt_then_posts_the_message(): void
    {
        $this->fcmConfigured();
        Cache::flush();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'fcm.googleapis.com/*'        => Http::response(['name' => 'projects/bethany-test/messages/1']),
        ]);
        $u = User::factory()->create();
        PushSubscription::create(['user_id' => $u->id, 'endpoint' => 'fcm:reg1', 'p256dh' => '', 'auth' => '', 'device_token' => 'reg1', 'token_type' => 'fcm', 'platform' => 'android']);

        WebPushService::send($u->id, 'Shift tomorrow', '08:00 at Sonalux', '/me/shifts', 'calendar', ['shift_id' => 3]);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com/token')
            && $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer');
        Http::assertSent(fn ($r) => $r->url() === 'https://fcm.googleapis.com/v1/projects/bethany-test/messages:send'
            && $r->header('Authorization')[0] === 'Bearer ya29.test'
            && $r['message']['token'] === 'reg1'
            && $r['message']['notification']['title'] === 'Shift tomorrow'
            && $r['message']['data']['url'] === '/me/shifts'
            && $r['message']['data']['shift_id'] === '3'
            && $r['message']['android']['notification']['channel_id'] === 'default');
    }

    public function test_fcm_unregistered_token_marks_the_registration_inactive(): void
    {
        $this->fcmConfigured();
        Cache::flush();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test']),
            'fcm.googleapis.com/*'        => Http::response(['error' => [
                'status'  => 'NOT_FOUND',
                'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']],
            ]], 404),
        ]);
        $u = User::factory()->create();
        $sub = PushSubscription::create(['user_id' => $u->id, 'endpoint' => 'fcm:gone', 'p256dh' => '', 'auth' => '', 'device_token' => 'gone', 'token_type' => 'fcm', 'platform' => 'android']);

        app(FcmSender::class)->send($sub, 'T', 'B');

        $this->assertFalse($sub->fresh()->is_active);
    }

    public function test_a_provider_outage_never_throws_into_the_caller(): void
    {
        $this->apnsConfigured();
        $this->fcmConfigured();
        Cache::flush();
        Http::fake(fn () => throw new \RuntimeException('connection refused'));
        $u = User::factory()->create();
        PushSubscription::create(['user_id' => $u->id, 'endpoint' => 'apns:a', 'p256dh' => '', 'auth' => '', 'device_token' => 'a', 'token_type' => 'apns', 'platform' => 'ios']);
        PushSubscription::create(['user_id' => $u->id, 'endpoint' => 'fcm:b', 'p256dh' => '', 'auth' => '', 'device_token' => 'b', 'token_type' => 'fcm', 'platform' => 'android']);

        WebPushService::send($u->id, 'T', 'B');

        $this->assertSame(2, PushSubscription::active()->count(), 'an outage is not a stale token');
    }
}
