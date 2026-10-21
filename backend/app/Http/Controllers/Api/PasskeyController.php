<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Passkey;
use App\Services\ActivityLogService;
use App\Services\Auth\Passkeys;
use App\Services\Auth\StepUp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Passkeys (owner, 2026-10-21): the person's own list under Profile, and the
 * passkey form of step-up. See App\Services\Auth\Passkeys.
 */
class PasskeyController extends Controller
{
    public function __construct(private Passkeys $passkeys, private StepUp $stepUp)
    {
    }

    /** GET /profile/passkeys */
    public function index(Request $request)
    {
        return response()->json([
            'data' => Passkey::where('user_id', $request->user()->id)->orderBy('id')->get()->map->present()->values(),
        ]);
    }

    /** POST /profile/passkeys/options — behind step-up: adding a credential is privileged. */
    public function registrationOptions(Request $request)
    {
        return response()->json(['options' => $this->passkeys->registrationOptions($request->user(), $request)]);
    }

    /** POST /profile/passkeys — the browser's registration response. */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:80',
            'credential' => 'required|array',
        ]);
        $user = $request->user();
        if (Passkey::where('user_id', $user->id)->count() >= 5) {
            return response()->json(['message' => 'Five passkeys is the most one account keeps. Remove one first.'], 422);
        }

        $passkey = $this->passkeys->register($user, $request, $validated['credential'], trim($validated['name']));
        ActivityLogService::log('passkey_added', $user, ['passkey_id' => $passkey->id, 'name' => $passkey->name, 'aaguid' => $passkey->aaguid],
            "Passkey added: {$passkey->name}", $user);

        return response()->json(['message' => 'Passkey added.', 'passkey' => $passkey->present()], 201);
    }

    /** DELETE /profile/passkeys/{id} — behind step-up. */
    public function destroy(Request $request, int $id)
    {
        $user    = $request->user();
        $passkey = Passkey::where('user_id', $user->id)->findOrFail($id);
        $passkey->delete();
        ActivityLogService::log('passkey_removed', $user, ['passkey_id' => $id, 'name' => $passkey->name], "Passkey removed: {$passkey->name}", $user);

        return response()->json(['message' => 'Passkey removed.']);
    }

    // ── step-up with a passkey ───────────────────────────────────────────────

    /** POST /auth/step-up/passkey/options */
    public function stepUpOptions(Request $request)
    {
        return response()->json(['options' => $this->passkeys->assertionOptions($request->user(), $request)]);
    }

    /** POST /auth/step-up/passkey — the signed assertion confirms the session, as a correct code would. */
    public function stepUp(Request $request)
    {
        $validated = $request->validate(['credential' => 'required|array']);
        $user = $request->user();

        $key = 'step-up-attempts:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Too many attempts. Wait a few minutes and try again.',
                'retry_after_seconds' => RateLimiter::availableIn($key)], 429);
        }

        try {
            $passkey = $this->passkeys->assert($user, $request, $validated['credential']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            RateLimiter::hit($key, 300);
            ActivityLogService::log('step_up_failed', $user, ['method' => 'passkey'], 'Step-up refused (passkey)', $user);
            throw $e;
        }

        RateLimiter::clear($key);
        $until = $this->stepUp->confirmFor($user, $user->currentAccessToken());
        ActivityLogService::log('step_up_confirmed', $user, ['method' => 'passkey', 'passkey_id' => $passkey->id, 'name' => $passkey->name],
            "Step-up confirmed (passkey: {$passkey->name})", $user);

        return response()->json(['confirmed_until' => $until->toIso8601String(), 'passkey' => $passkey->name]);
    }
}
