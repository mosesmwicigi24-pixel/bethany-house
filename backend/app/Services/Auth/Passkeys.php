<?php

namespace App\Services\Auth;

use App\Models\Passkey;
use App\Models\User;
use Cose\Algorithm\Manager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\RS256;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Passkeys — Touch ID, Face ID, a security key — as the way a person confirms
 * it's them (owner, 2026-10-21). WebAuthn, through web-auth/webauthn-lib.
 *
 * Two ceremonies, each in two steps: the server mints OPTIONS with a random
 * challenge (held in the cache for a few minutes against this session), the
 * browser has the device sign them, the server CHECKS the signed response.
 *   - register: a new credential for the signed-in person (Profile → Passkeys).
 *     Adding one is privileged and sits behind step-up.
 *   - assert: prove possession of a registered credential; App\Services\Auth\StepUp
 *     then stamps the session as confirmed, exactly as a correct code would.
 *
 * The relying-party id is the hub's host (security.passkeys.rp_id, else the
 * request host): a credential registered on hub.bethanyhouse.co.ke answers
 * only there. Attestation is not asked for ("none"): we trust the device the
 * person enrols, not a vendor certificate chain. User verification is
 * REQUIRED, so the device asks for the fingerprint, face or PIN every time.
 */
class Passkeys
{
    private const CHALLENGE_MINUTES = 5;

    public function rpId(Request $request): string
    {
        return (string) (config('security.passkeys.rp_id') ?: $request->getHost());
    }

    public function origin(Request $request): string
    {
        $configured = config('security.passkeys.origin');

        return (string) ($configured ?: $request->getSchemeAndHttpHost());
    }

    // ── register ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> the options for navigator.credentials.create() */
    public function registrationOptions(User $user, Request $request): array
    {
        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create((string) config('security.passkeys.rp_name', 'Bethany House Hub'), $this->rpId($request)),
            PublicKeyCredentialUserEntity::create((string) $user->email, $this->userHandle($user), trim($user->first_name . ' ' . $user->last_name) ?: (string) $user->email),
            random_bytes(32),
            [
                PublicKeyCredentialParameters::create('public-key', ES256::identifier()),
                PublicKeyCredentialParameters::create('public-key', RS256::identifier()),
            ],
            AuthenticatorSelectionCriteria::create(
                null,
                AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            Passkey::where('user_id', $user->id)->get()->map(fn (Passkey $p) => $this->source($p)->getPublicKeyCredentialDescriptor())->all(),
            self::CHALLENGE_MINUTES * 60 * 1000,
        );

        $json = $this->serializer()->serialize($options, 'json');
        Cache::put($this->key('register', $user, $request), $json, now()->addMinutes(self::CHALLENGE_MINUTES));

        return json_decode($json, true);
    }

    /** Check the browser's response and keep the credential. */
    public function register(User $user, Request $request, array $credential, string $name): Passkey
    {
        $json = Cache::pull($this->key('register', $user, $request));
        if (!$json) {
            throw ValidationException::withMessages(['credential' => ['The passkey request expired. Start again.']]);
        }
        $options = $this->serializer()->deserialize($json, PublicKeyCredentialCreationOptions::class, 'json');

        $pk = $this->credential($credential);
        if (!$pk->response instanceof AuthenticatorAttestationResponse) {
            throw ValidationException::withMessages(['credential' => ['That is not a registration response.']]);
        }

        try {
            $record = AuthenticatorAttestationResponseValidator::create($this->ceremonies($request)->creationCeremony())
                ->check($pk->response, $options, $this->rpId($request));
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['credential' => ['The device\'s answer could not be verified: ' . $e->getMessage()]]);
        }
        return Passkey::create([
            'user_id'       => $user->id,
            'name'          => $name,
            'credential_id' => self::b64url($record->publicKeyCredentialId),
            'public_key'    => $this->serializer()->serialize($record, 'json'),
            'transports'    => $record->transports,
            'sign_count'    => $record->counter,
            'aaguid'        => (string) $record->aaguid,
        ]);
    }

    // ── assert ───────────────────────────────────────────────────────────────

    public function has(User $user): bool
    {
        return Passkey::where('user_id', $user->id)->exists();
    }

    /** @return array<string, mixed> the options for navigator.credentials.get() */
    public function assertionOptions(User $user, Request $request): array
    {
        $keys = Passkey::where('user_id', $user->id)->get();
        if ($keys->isEmpty()) {
            throw ValidationException::withMessages(['credential' => ['No passkey is registered on this account.']]);
        }
        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $this->rpId($request),
            $keys->map(fn (Passkey $p) => $this->source($p)->getPublicKeyCredentialDescriptor())->all(),
            AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            self::CHALLENGE_MINUTES * 60 * 1000,
        );

        $json = $this->serializer()->serialize($options, 'json');
        Cache::put($this->key('assert', $user, $request), $json, now()->addMinutes(self::CHALLENGE_MINUTES));

        return json_decode($json, true);
    }

    /** Check the signed assertion; the passkey it used is returned, its counter advanced. */
    public function assert(User $user, Request $request, array $credential): Passkey
    {
        $json = Cache::pull($this->key('assert', $user, $request));
        if (!$json) {
            throw ValidationException::withMessages(['credential' => ['The passkey request expired. Start again.']]);
        }
        $options = $this->serializer()->deserialize($json, PublicKeyCredentialRequestOptions::class, 'json');

        $pk = $this->credential($credential);
        if (!$pk->response instanceof AuthenticatorAssertionResponse) {
            throw ValidationException::withMessages(['credential' => ['That is not a sign-in response.']]);
        }

        $passkey = Passkey::where('user_id', $user->id)->where('credential_id', self::b64url($pk->rawId))->first();
        if (!$passkey) {
            throw ValidationException::withMessages(['credential' => ['That passkey is not registered on this account.']]);
        }

        try {
            $record = AuthenticatorAssertionResponseValidator::create($this->ceremonies($request)->requestCeremony())
                ->check($this->source($passkey), $pk->response, $options, $this->rpId($request), $this->userHandle($user));
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['credential' => ['The device\'s answer could not be verified: ' . $e->getMessage()]]);
        }
        $passkey->forceFill([
            'public_key'   => $this->serializer()->serialize($record, 'json'),
            'sign_count'   => $record->counter,
            'last_used_at' => now(),
        ])->save();

        return $passkey;
    }

    // ── plumbing ─────────────────────────────────────────────────────────────

    private function ceremonies(Request $request): CeremonyStepManagerFactory
    {
        $f = new CeremonyStepManagerFactory();
        $f->setAllowedOrigins([$this->origin($request)]);
        $f->setAlgorithmManager(Manager::create()->add(ES256::create(), RS256::create()));
        $f->setAttestationStatementSupportManager($this->attestations());

        return $f;
    }

    private function attestations(): AttestationStatementSupportManager
    {
        return AttestationStatementSupportManager::create([NoneAttestationStatementSupport::create()]);
    }

    private function serializer(): SerializerInterface
    {
        return (new WebauthnSerializerFactory($this->attestations()))->create();
    }

    private function credential(array $credential): PublicKeyCredential
    {
        try {
            return $this->serializer()->deserialize(json_encode($credential), PublicKeyCredential::class, 'json');
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['credential' => ['The browser\'s answer could not be read.']]);
        }
    }

    /** The stored credential record (public key, aaguid, counter, trust path) back from its JSON. */
    private function source(Passkey $p): CredentialRecord
    {
        return $this->serializer()->deserialize($p->public_key, CredentialRecord::class, 'json');
    }

    /** The user handle the authenticator stores: the account's uuid, never the email. */
    private function userHandle(User $user): string
    {
        return (string) ($user->uuid ?: ('user:' . $user->id));
    }

    /** One challenge per ceremony per session (token), so another tab or device cannot answer it. */
    private function key(string $ceremony, User $user, Request $request): string
    {
        $token = $user->currentAccessToken();
        $session = $token && isset($token->id) ? 'token:' . $token->id : 'user:' . $user->id;

        return "passkey:{$ceremony}:{$session}";
    }

    public static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
