<?php

namespace Tests\Feature;

use App\Models\Passkey;
use App\Models\User;
use App\Services\Auth\StepUp;
use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Passkeys as step-up (owner, 2026-10-21; App\Services\Auth\Passkeys).
 *
 * A software authenticator lives in this test: a P-256 key pair that answers
 * the hub's registration options with a "none" attestation and its assertion
 * options with a real ECDSA signature over authenticatorData ‖ SHA-256(clientDataJSON),
 * exactly as Touch ID or Face ID would. So the whole path is proved — options
 * minted, challenge bound to the session, response verified, counter kept —
 * without a browser.
 */
class PasskeysTest extends TestCase
{
    use RefreshDatabase;

    private const RP_ID  = 'hub.test';
    private const ORIGIN = 'https://hub.test';

    /** @var \OpenSSLAsymmetricKey */
    private $key;
    private string $x;
    private string $y;
    private string $credId;
    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        config(['security.passkeys.rp_id' => self::RP_ID, 'security.passkeys.origin' => self::ORIGIN]);

        $this->key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $d = openssl_pkey_get_details($this->key)['ec'];
        $this->x = str_pad($d['x'], 32, "\0", STR_PAD_LEFT);
        $this->y = str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
        $this->credId = random_bytes(16);
    }

    private function user(bool $twoFactor = false): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff', 'two_factor_enabled' => $twoFactor]);
        $u->assignRole(Role::findOrCreate('finance_manager', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    // ── the software authenticator ───────────────────────────────────────────

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private function registrationResponse(array $options): array
    {
        $clientData = json_encode(['type' => 'webauthn.create', 'challenge' => $options['challenge'], 'origin' => self::ORIGIN, 'crossOrigin' => false]);
        $coseKey = MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))        // kty: EC2
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))       // alg: ES256
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))       // crv: P-256
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($this->x))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($this->y));
        $authData = hash('sha256', self::RP_ID, true)
            . chr(0x45)                                   // UP | UV | AT
            . pack('N', ++$this->counter)
            . str_repeat("\0", 16)                        // aaguid
            . pack('n', strlen($this->credId)) . $this->credId
            . (string) $coseKey;
        $attestation = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        return [
            'id'    => self::b64url($this->credId),
            'rawId' => self::b64url($this->credId),
            'type'  => 'public-key',
            'authenticatorAttachment' => 'platform',
            'clientExtensionResults'  => [],
            'response' => [
                'clientDataJSON'    => self::b64url($clientData),
                'attestationObject' => self::b64url((string) $attestation),
                'transports'        => ['internal'],
            ],
        ];
    }

    private function assertionResponse(array $options, User $user, ?int $counter = null): array
    {
        $clientData = json_encode(['type' => 'webauthn.get', 'challenge' => $options['challenge'], 'origin' => self::ORIGIN, 'crossOrigin' => false]);
        $authData   = hash('sha256', self::RP_ID, true) . chr(0x05) . pack('N', $counter ?? ++$this->counter);
        openssl_sign($authData . hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id'    => self::b64url($this->credId),
            'rawId' => self::b64url($this->credId),
            'type'  => 'public-key',
            'clientExtensionResults' => [],
            'response' => [
                'clientDataJSON'    => self::b64url($clientData),
                'authenticatorData' => self::b64url($authData),
                'signature'         => self::b64url($signature),
                'userHandle'        => self::b64url((string) $user->uuid),
            ],
        ];
    }

    private function enrol(User $user, string $name = 'MacBook Touch ID'): Passkey
    {
        app(StepUp::class)->confirmFor($user, null);
        $options = $this->postJson('/api/v1/admin/profile/passkeys/options')->assertOk()->json('options');
        $this->assertSame(self::RP_ID, $options['rp']['id']);
        $this->assertSame('required', $options['authenticatorSelection']['userVerification']);
        $this->assertSame('none', $options['attestation']);

        $this->postJson('/api/v1/admin/profile/passkeys', ['name' => $name, 'credential' => $this->registrationResponse($options)])
            ->assertCreated()->assertJsonPath('passkey.name', $name);

        return Passkey::where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    // ── enrolment ────────────────────────────────────────────────────────────

    public function test_adding_a_passkey_needs_a_fresh_confirmation_first(): void
    {
        Sanctum::actingAs($this->user());
        $this->postJson('/api/v1/admin/profile/passkeys/options')->assertForbidden()->assertJsonPath('code', 'step_up_required');
    }

    public function test_the_device_registers_a_passkey_and_it_is_listed_without_its_key_material(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        $passkey = $this->enrol($user);

        $this->assertSame(self::b64url($this->credId), $passkey->credential_id);
        $this->assertSame(['internal'], $passkey->transports);
        $this->assertDatabaseHas('activity_log', ['event' => 'passkey_added', 'causer_id' => $user->id]);

        $row = $this->getJson('/api/v1/admin/profile/passkeys')->assertOk()->json('data.0');
        $this->assertSame('MacBook Touch ID', $row['name']);
        $this->assertArrayNotHasKey('public_key', $row);
        $this->assertArrayNotHasKey('credential_id', $row);
    }

    public function test_a_response_to_a_stale_or_foreign_challenge_is_refused(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        app(StepUp::class)->confirmFor($user, null);
        $options = $this->postJson('/api/v1/admin/profile/passkeys/options')->assertOk()->json('options');

        $forged = $options;
        $forged['challenge'] = self::b64url(random_bytes(32));
        $this->postJson('/api/v1/admin/profile/passkeys', ['name' => 'x', 'credential' => $this->registrationResponse($forged)])
            ->assertStatus(422);
        $this->assertSame(0, Passkey::count());

        // The challenge was spent by that attempt: the honest answer is now too late.
        $this->postJson('/api/v1/admin/profile/passkeys', ['name' => 'x', 'credential' => $this->registrationResponse($options)])
            ->assertStatus(422)->assertJsonPath('errors.credential.0', 'The passkey request expired. Start again.');
    }

    // ── step-up ──────────────────────────────────────────────────────────────

    public function test_the_challenge_names_the_passkey_first_once_one_is_enrolled(): void
    {
        $user = $this->user(twoFactor: true);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/admin/profile/passkeys/options')->assertForbidden()
            ->assertJsonPath('method', 'totp')->assertJsonPath('fallback', 'totp');

        $this->enrol($user);
        $this->travel(10)->minutes();      // the confirmation used to enrol has lapsed

        $this->postJson('/api/v1/admin/profile/passkeys/options')->assertForbidden()
            ->assertJsonPath('method', 'passkey')->assertJsonPath('fallback', 'totp');
    }

    public function test_a_signed_assertion_confirms_the_session_and_advances_the_counter(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        $passkey = $this->enrol($user);
        $this->travel(10)->minutes();
        $this->assertFalse(app(StepUp::class)->isFresh($user, null));

        $options = $this->postJson('/api/v1/admin/auth/step-up/passkey/options')->assertOk()->json('options');
        $this->assertSame(self::RP_ID, $options['rpId']);
        $this->assertSame(self::b64url($this->credId), $options['allowCredentials'][0]['id']);

        $this->postJson('/api/v1/admin/auth/step-up/passkey', ['credential' => $this->assertionResponse($options, $user)])
            ->assertOk()->assertJsonPath('passkey', 'MacBook Touch ID');

        $this->assertTrue(app(StepUp::class)->isFresh($user, null), 'the session is confirmed, as a correct code would confirm it');
        $passkey->refresh();
        $this->assertSame(2, $passkey->sign_count);
        $this->assertNotNull($passkey->last_used_at);
        $this->assertDatabaseHas('activity_log', ['event' => 'step_up_confirmed', 'causer_id' => $user->id]);

        // The confirmation opens the protected action without a code.
        $this->postJson('/api/v1/admin/profile/passkeys/options')->assertOk();
    }

    public function test_a_cloned_authenticator_with_a_stale_counter_is_refused(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        $this->enrol($user);
        $this->travel(10)->minutes();

        $options = $this->postJson('/api/v1/admin/auth/step-up/passkey/options')->assertOk()->json('options');
        $this->postJson('/api/v1/admin/auth/step-up/passkey', ['credential' => $this->assertionResponse($options, $user, counter: 1)])
            ->assertStatus(422);
        $this->assertFalse(app(StepUp::class)->isFresh($user, null));
        $this->assertDatabaseHas('activity_log', ['event' => 'step_up_failed', 'causer_id' => $user->id]);
    }

    public function test_another_persons_passkey_cannot_confirm_this_session(): void
    {
        $owner = $this->user();
        Sanctum::actingAs($owner);
        $this->enrol($owner);

        $other = $this->user();
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/admin/auth/step-up/passkey/options')->assertStatus(422);   // no passkey on this account
        $this->assertFalse(app(StepUp::class)->isFresh($other, null));
    }

    public function test_a_passkey_is_removed_after_confirming_and_the_challenge_falls_back(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        $passkey = $this->enrol($user);

        $this->deleteJson("/api/v1/admin/profile/passkeys/{$passkey->id}")->assertOk();
        $this->assertDatabaseMissing('passkeys', ['id' => $passkey->id]);
        $this->assertDatabaseHas('activity_log', ['event' => 'passkey_removed', 'causer_id' => $user->id]);

        $this->travel(10)->minutes();
        $this->postJson('/api/v1/admin/profile/passkeys/options')->assertForbidden()->assertJsonPath('method', 'password');
    }
}
