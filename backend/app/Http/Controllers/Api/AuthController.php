<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Customer;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
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

        $token = $user->createToken('auth_token')->plainTextToken;

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
        $token     = $user->createToken($tokenName)->plainTextToken;

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

        if (!$user->canAccessAdmin()) {
            throw ValidationException::withMessages([
                'email' => ['Only staff and system users may access the admin panel.'],
            ]);
        }

        // 2FA checkpoint - client follows up with adminVerify2fa()
        if ($user->two_factor_enabled) {
            return response()->json([
                'requires_2fa' => true,
                'user_id'      => $user->id,
            ]);
        }

        $tokenName = ($validated['remember_me'] ?? false) ? 'remember_token' : 'auth_token';
        $token     = $user->createToken($tokenName)->plainTextToken;

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
            'user_id' => 'required|integer|exists:users,id',
            'code'    => 'required|string|size:6',
        ]);

        $user = User::findOrFail($validated['user_id']);

        if (!$user->two_factor_enabled || !$user->two_factor_secret) {
            return response()->json([
                'message' => '2FA is not enabled for this account.',
            ], 422);
        }

        // Re-check admin gate - prevents a demoted/deactivated account from
        // completing verification after the first step already passed.
        if (!$user->canAccessAdmin()) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        // Gracefully handle a secret encrypted with a different APP_KEY
        // or a corrupted value - auto-reset 2FA so the user can re-enable.
        try {
            $secret = decrypt($user->two_factor_secret);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            $user->update([
                'two_factor_enabled'          => false,
                'two_factor_secret'           => null,
                'two_factor_secret_temp'      => null,
                'two_factor_setup_started_at' => null,
                'two_factor_enabled_at'       => null,
            ]);

            return response()->json([
                'message' => '2FA configuration is invalid and has been reset. Please log in and re-enable 2FA in your security settings.',
            ], 422);
        }

        $google2fa = new Google2FA();

        if (!$google2fa->verifyKey($secret, $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => ['The verification code is invalid or has expired.'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

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
}