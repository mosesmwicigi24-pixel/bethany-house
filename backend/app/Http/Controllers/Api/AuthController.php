<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Customer;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class AuthController extends Controller
{
    // =========================================================================
    // CUSTOMER / PUBLIC AUTH
    // =========================================================================

    /**
     * Register a new user (customer)
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'phone'    => 'nullable|string|max:20',
            'language' => 'nullable|string|in:en,fr,pt',
            'currency' => 'nullable|string|in:KES,USD',
        ]);

        // Split 'name' into first_name / last_name to match the User model
        $nameParts = explode(' ', $validated['name'], 2);

        $user = User::create([
            'first_name' => $nameParts[0],
            'last_name'  => $nameParts[1] ?? '',
            'email'      => $validated['email'],
            'password'   => Hash::make($validated['password']),
            'phone'      => $validated['phone'] ?? null,
            'status'     => 'active',
        ]);

        $customer = Customer::create([
            'user_id'            => $user->id,
            'preferred_language' => $validated['language'] ?? 'en',
            'preferred_currency' => $validated['currency'] ?? 'USD',
        ]);

        $token = $user->createAuthToken('auth_token')->plainTextToken;

        // Phase 3 - welcome notification + audit log
        try {
            NotificationService::userWelcome($user->id, $user->first_name);
            ActivityLogService::auth('register', $user);
        } catch (\Exception) {}

        return response()->json([
            'message'  => 'Registration successful',
            'user'     => $user,
            'customer' => $customer,
            'token'    => $token,
        ], 201);
    }

    /**
     * Login - customer-facing storefront.
     * Does NOT enforce canAccessAdmin(); any active user may log in here.
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email'       => 'required|email',
            'password'    => 'required',
            'remember_me' => 'boolean',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['Your account has been deactivated.'],
            ]);
        }

        if ($user->two_factor_enabled) {
            return response()->json([
                'requires_2fa' => true,
                'user_id'      => $user->id,
            ]);
        }

        $tokenName = ($validated['remember_me'] ?? false) ? 'remember_token' : 'auth_token';
        $token     = $user->createAuthToken($tokenName)->plainTextToken;

        $user->load('customer');

        return response()->json([
            'message' => 'Login successful',
            'user'    => $user,
            'token'   => $token,
        ]);
    }

    /**
     * Logout - revokes the current Sanctum token.
     * Shared by both customer and admin sessions.
     */
    public function logout(Request $request)
    {
        // Phase 3 - audit log before token is revoked
        try {
            ActivityLogService::auth('logout', $request->user());
        } catch (\Exception) {}

        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get authenticated user - customer portal version.
     */
    public function user(Request $request)
    {
        $user = $request->user()->load('customer');

        return response()->json([
            'user' => $user,
        ]);
    }

    /**
     * Update user profile
     */
    public function updateProfile(Request $request)
    {
        $validated = $request->validate([
            'name'               => 'sometimes|string|max:255',
            'phone'              => 'nullable|string|max:20',
            'preferred_language' => 'nullable|string|in:en,fr,pt',
            'preferred_currency' => 'nullable|string|in:KES,USD',
        ]);

        $user = $request->user();

        if (!empty($validated['name'])) {
            $parts = explode(' ', $validated['name'], 2);
            $user->update([
                'first_name' => $parts[0],
                'last_name'  => $parts[1] ?? $user->last_name,
            ]);
        }

        if (array_key_exists('phone', $validated)) {
            $user->update(['phone' => $validated['phone']]);
        }

        if ($user->customer) {
            $user->customer->update([
                'preferred_language' => $validated['preferred_language'] ?? $user->customer->preferred_language,
                'preferred_currency' => $validated['preferred_currency'] ?? $user->customer->preferred_currency,
            ]);
        }

        $user->load('customer');

        return response()->json([
            'message' => 'Profile updated successfully',
            'user'    => $user,
        ]);
    }

    /**
     * Change password
     */
    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        try { ActivityLogService::auth('password_changed', $user); } catch (\Exception) {}

        return response()->json([
            'message' => 'Password changed successfully',
        ]);
    }

    /**
     * Forgot password - sends a reset link
     */
    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $status = Password::sendResetLink($validated);

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'message' => 'Password reset link sent to your email',
            ]);
        }

        throw ValidationException::withMessages([
            'email' => [__($status)],
        ]);
    }

    /**
     * Reset password - consumes the emailed token
     */
    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'token'    => 'required',
            'email'    => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset(
            $validated,
            function ($user, $password) {
                $user->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
                try { ActivityLogService::auth('password_reset_completed', $user); } catch (\Exception) {}
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Password reset successfully',
            ]);
        }

        throw ValidationException::withMessages([
            'email' => [__($status)],
        ]);
    }

    // =========================================================================
    // TWO-FACTOR AUTHENTICATION  (account settings, authenticated user)
    // =========================================================================

    /**
     * Generate a new 2FA secret and return the QR code URL.
     * Stores the secret in two_factor_secret_temp until verify2FA() confirms it.
     */
    public function enable2FA(Request $request)
    {
        $user      = $request->user();
        $google2fa = new Google2FA();

        $secretKey = $google2fa->generateSecretKey();
        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secretKey
        );

        $user->update([
            'two_factor_secret_temp'      => encrypt($secretKey),
            'two_factor_setup_started_at' => now(),
        ]);

        return response()->json([
            'secret_key'  => $secretKey,
            'qr_code_url' => $qrCodeUrl,
            'message'     => 'Scan the QR code with your authenticator app and verify with the code',
        ]);
    }

    /**
     * Confirm a TOTP code and activate 2FA on the account.
     * Promotes two_factor_secret_temp → two_factor_secret.
     */
    public function verify2FA(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $user      = $request->user();
        $google2fa = new Google2FA();

        $encryptedSecret = $user->two_factor_secret_temp ?? $user->two_factor_secret;

        if (!$encryptedSecret) {
            throw ValidationException::withMessages([
                'code' => ['No 2FA setup in progress. Please start the setup again.'],
            ]);
        }

        try {
            $secret = decrypt($encryptedSecret);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            throw ValidationException::withMessages([
                'code' => ['2FA configuration is invalid. Please start the setup again.'],
            ]);
        }

        if (!$google2fa->verifyKey($secret, $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => ['The verification code is invalid.'],
            ]);
        }

        $user->update([
            'two_factor_enabled'          => true,
            'two_factor_secret'           => $encryptedSecret,
            'two_factor_enabled_at'       => now(),
            'two_factor_secret_temp'      => null,
            'two_factor_setup_started_at' => null,
        ]);

        try { ActivityLogService::auth('two_factor_enabled', $user); } catch (\Exception) {}

        return response()->json([
            'message' => '2FA enabled successfully',
        ]);
    }

    /**
     * Disable 2FA - requires current password for safety.
     */
    public function disable2FA(Request $request)
    {
        $validated = $request->validate([
            'password' => 'required',
        ]);

        $user = $request->user();

        if (!Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The password is incorrect.'],
            ]);
        }

        $user->update([
            'two_factor_enabled'          => false,
            'two_factor_secret'           => null,
            'two_factor_secret_temp'      => null,
            'two_factor_setup_started_at' => null,
            'two_factor_enabled_at'       => null,
        ]);

        try { ActivityLogService::auth('two_factor_disabled', $user); } catch (\Exception) {}

        return response()->json([
            'message' => '2FA disabled successfully',
        ]);
    }

    // =========================================================================
    // REACT ADMIN AUTH
    // =========================================================================

    /**
     * Admin login - POST /api/v1/admin/auth/login
     *
     * Same flow as login() with two additional guards:
     *   1. canAccessAdmin() - only system/staff users may proceed.
     *   2. Returns flattened permissions + primary outlet so the React
     *      usePermissions() hook and outlet context work immediately.
     */
    public function adminLogin(Request $request)
    {
        $validated = $request->validate([
            'email'       => 'required|email',
            'password'    => 'required',
            'remember_me' => 'boolean',
        ]);

        $user = User::where('email', $validated['email'])->first();

        // Every refused admin login is on the trail — the attempted address,
        // the reason and (via ActivityLogService) the IP and device. Repeated
        // failures against one account, or from one place, are how a password
        // attack shows up. The password itself is never recorded.
        $refuse = function (string $reason, string $message) use ($validated, $user) {
            ActivityLogService::log('admin_login_failed', $user, [
                'attempted_email' => strtolower($validated['email']),
                'reason'          => $reason,
            // Subject = the targeted account; no causer — whoever typed the
            // password is exactly what is unknown.
            ], "Admin login refused ({$reason}): " . strtolower($validated['email']));

            throw ValidationException::withMessages(['email' => [$message]]);
        };

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            $refuse($user ? 'wrong_password' : 'unknown_account', 'The provided credentials are incorrect.');
        }

        if ($user->status !== 'active') {
            $refuse('account_deactivated', 'Your account has been deactivated.');
        }

        if (!$user->canAccessAdmin()) {
            $refuse('not_staff', 'Only staff and system users may access the admin panel.');
        }

        // 2FA checkpoint - client follows up with adminVerify2fa(). The
        // challenge proves the password step passed: without it the second
        // step accepted any user_id plus a 6-digit code, i.e. the code alone
        // was a login. One use, five minutes, bound to this account.
        if ($user->two_factor_enabled) {
            $challenge = Str::random(64);
            Cache::put(self::twoFactorChallengeKey($challenge), [
                'user_id'     => $user->id,
                'remember_me' => (bool) ($validated['remember_me'] ?? false),
                'attempts'    => 0,
            ], now()->addMinutes(5));

            return response()->json([
                'requires_2fa' => true,
                'user_id'      => $user->id,
                'challenge'    => $challenge,
            ]);
        }

        $tokenName = ($validated['remember_me'] ?? false) ? 'remember_token' : 'auth_token';
        $token     = $user->createAuthToken($tokenName)->plainTextToken;

        // Phase 3 - audit log
        try { ActivityLogService::auth('admin_login', $user); } catch (\Exception) {}

        return response()->json([
            'message' => 'Login successful',
            'user'    => $this->withPermissions($user),
            'token'   => $token,
        ]);
    }

    /**
     * Admin 2FA verification - POST /api/v1/admin/auth/2fa/verify
     *
     * Called after adminLogin() returns requires_2fa: true.
     * User has no token yet; identity proved by user_id + TOTP code.
     * On success a full Sanctum token is issued.
     */
    public function adminVerify2fa(Request $request)
    {
        $validated = $request->validate([
            'user_id'   => 'required|integer',
            'code'      => 'required|string|size:6',
            'challenge' => 'required|string|max:128',
        ]);

        // The password step's proof. Missing, expired, used, or issued for a
        // different account → start again from the password.
        $key     = self::twoFactorChallengeKey($validated['challenge']);
        $pending = Cache::get($key);
        if (!is_array($pending) || (int) $pending['user_id'] !== (int) $validated['user_id']) {
            return response()->json(['message' => 'This sign-in has expired. Enter your password again.'], 422);
        }

        $user = User::find($validated['user_id']);

        // Re-check the account: deactivated or demoted between the two steps
        // is refused, and nothing is minted.
        if (!$user || !$user->isActive() || !$user->canAccessAdmin()) {
            Cache::forget($key);
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if (!$user->two_factor_enabled || !$user->two_factor_secret) {
            Cache::forget($key);
            return response()->json(['message' => '2FA is not enabled for this account.'], 422);
        }

        // The Livewire screens stored the secret in plain text, the API
        // encrypted. This endpoint used to treat an undecryptable secret as
        // corrupt and RESET the account's 2FA — reachable before any proof of
        // identity, so anyone could switch off another person's 2FA. Read
        // both forms; never reset from here.
        $secret = self::readTwoFactorSecret((string) $user->two_factor_secret);
        if ($secret === null) {
            Cache::forget($key);
            ActivityLogService::log('admin_login_2fa_failed', $user, ['reason' => 'unreadable_secret'],
                'Admin 2FA secret unreadable: ' . strtolower((string) $user->email));
            return response()->json([
                'message' => 'Your two-step sign-in can’t be read. Ask an administrator to reset it.',
            ], 422);
        }

        $google2fa = new Google2FA();

        if (!$google2fa->verifyKey($secret, $validated['code'])) {
            // A wrong second factor means the password was right: the most
            // telling failure there is. Recorded against the account. Five
            // wrong codes end this sign-in.
            ActivityLogService::log('admin_login_2fa_failed', $user, ['reason' => 'invalid_code'],
                'Admin 2FA code refused: ' . strtolower((string) $user->email));
            $pending['attempts'] = (int) $pending['attempts'] + 1;
            $pending['attempts'] >= 5
                ? Cache::forget($key)
                : Cache::put($key, $pending, now()->addMinutes(5));
            throw ValidationException::withMessages([
                'code' => ['The verification code is invalid or has expired.'],
            ]);
        }

        Cache::forget($key);
        $token = $user->createAuthToken(!empty($pending['remember_me']) ? 'remember_token' : 'auth_token')->plainTextToken;

        // Phase 3 - audit log
        try { ActivityLogService::auth('admin_login_2fa', $user); } catch (\Exception) {}

        return response()->json([
            'message' => 'Verification successful',
            'user'    => $this->withPermissions($user),
            'token'   => $token,
        ]);
    }

    /**
     * Get authenticated admin user - GET /api/v1/admin/auth/me
     *
     * Called by the React RequireAuth component on every page refresh to
     * re-hydrate the Zustand auth store without a re-login.
     */
    public function adminMe(Request $request)
    {
        return response()->json([
            'user' => $this->withPermissions($request->user()),
        ]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Load roles → permissions on a user and append:
     *   $user->permissions  - flat array of permission name strings for
     *                          the React usePermissions() hook
     *   $user->outlet       - primary outlet or null, used by POS/production
     *                          modules to pre-select the outlet context
     */
    private function withPermissions(User $user): User
    {
        $user->load('roles.permissions');

        $user->permissions = $user->roles
            ->flatMap(fn ($role) => $role->permissions)
            ->pluck('name')
            ->unique()
            ->values();

        // primaryOutlet() returns null if the outlet_user pivot has no rows
        // for this user - handled gracefully by the React admin.
        $user->outlet = $user->primaryOutlet();

        return $user;
    }

    private static function twoFactorChallengeKey(string $challenge): string
    {
        return 'admin-2fa-challenge:' . hash('sha256', $challenge);
    }

    /** The TOTP secret in either stored form (API: encrypted; Livewire: plain base32), or null. */
    private static function readTwoFactorSecret(string $stored): ?string
    {
        try {
            return decrypt($stored);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return preg_match('/^[A-Z2-7]{16,}=*$/', $stored) ? $stored : null;
        }
    }
}
