<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash, Storage};
use Illuminate\Validation\Rule;
use Spatie\Permission\PermissionRegistrar;

class ProfileController extends Controller
{
    /**
     * GET /api/v1/admin/profile
     * Get the authenticated user's full profile.
     */
    public function show(Request $request)
    {
        $user = User::with(['roles', 'outlets'])->findOrFail($request->user()->id);
        $user->outlet = $user->primaryOutlet();

        // Active sessions count
        $sessionsCount = DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)
            ->count();

        $stats = [
            'active_sessions_count' => $sessionsCount,
        ];

        // Role-specific stats
        try {
            if ($user->hasAnyRole(['pos_clerk', 'outlet_manager', 'admin', 'super_admin'])) {
                $stats['orders_processed'] = DB::table('orders')
                    ->where('created_by', $user->id)
                    ->count();
            }
        } catch (\Exception) {}

        return response()->json([
            'user'  => $user,
            'stats' => $stats,
        ]);
    }

    /**
     * PUT /api/v1/admin/profile
     * Update own profile - name, phone, language, currency.
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'first_name'         => 'sometimes|string|max:255',
            'last_name'          => 'sometimes|string|max:255',
            'phone'              => 'sometimes|nullable|string|max:30',
            'preferred_language' => 'sometimes|string|max:10',
            'preferred_currency' => 'sometimes|string|max:10',
        ]);

        $user->update($validated);

        $user->load(['roles', 'outlets']);
        $user->outlet = $user->primaryOutlet();

        $this->logActivity($request, 'profile_updated', 'Updated own profile');

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user'    => $user,
        ]);
    }

    /**
     * POST /api/v1/admin/profile/password
     * Change own password - requires current password verification.
     */
    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password'      => 'required|string',
            'password'              => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'The current password is incorrect.',
                'errors'  => ['current_password' => ['The current password is incorrect.']],
            ], 422);
        }

        $user->update(['password' => Hash::make($validated['password'])]);

        // Revoke all OTHER tokens except the current one
        $currentToken = $request->bearerToken();
        DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)
            ->where('token', '!=', hash('sha256', $currentToken))
            ->delete();

        $this->logActivity($request, 'password_changed', 'Changed own password');

        return response()->json(['message' => 'Password changed successfully. Other sessions have been terminated.']);
    }

    /**
     * POST /api/v1/admin/profile/avatar
     * Upload own avatar.
     */
    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        $user = $request->user();

        // Remove old avatar
        $old = DB::table('users')->where('id', $user->id)->value('avatar_url');
        if ($old) {
            app(ImageService::class)->delete($old, 'public');
        }

        $result = app(ImageService::class)->process(
            $request->file('avatar'),
            "avatars/{$user->id}",
            'avatar'
        );
        $url = $result['url'];

        // Store in users table if column exists, else settings
        try {
            DB::table('users')->where('id', $user->id)->update(['avatar_url' => $url]);
        } catch (\Exception) {
            // avatar_url column may not exist yet - ignore
        }

        return response()->json(['message' => 'Avatar uploaded.', 'url' => $url]);
    }

    /**
     * GET /api/v1/admin/profile/sessions
     * List all active Sanctum tokens for the current user.
     */
    public function sessions(Request $request)
    {
        $currentToken = hash('sha256', $request->bearerToken() ?? '');

        $tokens = DB::table('personal_access_tokens')
            ->where('tokenable_id', $request->user()->id)
            ->orderBy('last_used_at', 'desc')
            ->get()
            ->map(fn ($t) => [
                'id'         => (string) $t->id,
                'ip'         => $t->ip_address ?? '-',
                'agent'      => $this->parseAgent($t->name ?? ''),
                'last_used'  => $t->last_used_at ?? $t->created_at,
                'is_current' => $t->token === $currentToken,
            ]);

        return response()->json(['data' => $tokens]);
    }

    /**
     * POST /api/v1/admin/profile/sessions/{tokenId}/revoke
     * Revoke a specific session.
     */
    public function revokeSession(Request $request, string $tokenId)
    {
        $deleted = DB::table('personal_access_tokens')
            ->where('tokenable_id', $request->user()->id)
            ->where('id', $tokenId)
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        $this->logActivity($request, 'session_revoked', "Revoked session #{$tokenId}");

        return response()->json(['message' => 'Session revoked successfully.']);
    }

    /**
     * POST /api/v1/admin/profile/sessions/revoke-all
     * Revoke all sessions except the current one.
     */
    public function revokeAllSessions(Request $request)
    {
        $currentToken = hash('sha256', $request->bearerToken() ?? '');

        $deleted = DB::table('personal_access_tokens')
            ->where('tokenable_id', $request->user()->id)
            ->where('token', '!=', $currentToken)
            ->delete();

        $this->logActivity($request, 'sessions_revoked', "Revoked all other sessions ({$deleted} total)");

        return response()->json([
            'message'      => "All other sessions revoked ({$deleted} sessions terminated).",
            'revoked_count'=> $deleted,
        ]);
    }

    /**
     * GET /api/v1/admin/profile/activity
     * Get current user's own activity log.
     */
    public function activity(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 20), 100);

        try {
            $logs = DB::table('activity_log')
                ->where('user_id', $request->user()->id)
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json($logs);
        } catch (\Exception) {
            return response()->json(['data' => [], 'meta' => []]);
        }
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function parseAgent(string $name): string
    {
        // Token name is set at login time - can store browser info there
        // Fall back to a readable label
        if (str_contains(strtolower($name), 'mobile')) return 'Mobile device';
        if (str_contains(strtolower($name), 'chrome'))  return 'Chrome browser';
        if (str_contains(strtolower($name), 'firefox')) return 'Firefox browser';
        if (str_contains(strtolower($name), 'safari'))  return 'Safari browser';
        return $name ?: 'Admin panel';
    }

    private function logActivity(Request $request, string $action, string $description): void
    {
        try {
            DB::table('activity_log')->insert([
                'user_id'     => $request->user()->id,
                'action'      => $action,
                'description' => $description,
                'ip_address'  => $request->ip(),
                'created_at'  => now(),
            ]);
        } catch (\Exception) {}
    }
}