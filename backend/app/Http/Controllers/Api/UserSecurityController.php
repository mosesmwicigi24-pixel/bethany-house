<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Auth\AccountLockout;
use App\Services\Auth\StaffAuthority;
use App\Services\Auth\TerminalPin;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Acting on ANOTHER person's sign-in (role plan §12.4, Phase 4C):
 * unlock their account, reset their two-step sign-in, see and end their
 * sessions (system_admin: Tier 2–3; super_admin: anyone else — see
 * StaffAuthority), and reset a clerk's terminal PIN (their outlet manager).
 * Every action is on the audit trail.
 */
class UserSecurityController extends Controller
{
    public function __construct(private StaffAuthority $authority) {}

    /** POST /api/v1/admin/users/{id}/unlock */
    public function unlock(Request $request, int $id, AccountLockout $lockout)
    {
        $target = $this->administrable($request, $id);
        $lockout->unlock($target, $request->user(), 'console');

        return response()->json(['message' => 'Account unlocked.', 'user' => $this->lockState($target->fresh())]);
    }

    /** POST /api/v1/admin/users/{id}/2fa/reset — step-up route. */
    public function resetTwoFactor(Request $request, int $id, TwoFactor $twoFactor)
    {
        $validated = $request->validate(['reason' => 'nullable|string|max:255']);
        $target = $this->administrable($request, $id);

        $twoFactor->reset($target, $request->user(), $validated['reason'] ?? null);

        return response()->json([
            'message' => 'Two-step sign-in reset. Every session on the account has ended.'
                . ($twoFactor->requiredFor($target) ? ' They will set it up again at their next sign-in.' : ''),
        ]);
    }

    /** GET /api/v1/admin/users/{id}/sessions */
    public function sessions(Request $request, int $id)
    {
        $target = $this->administrable($request, $id);

        $rows = $target->tokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn ($t) => [
                'id'          => (string) $t->id,
                'ip'          => $t->ip_address ?? '-',
                'agent'       => $t->user_agent ? \App\Services\Auth\LoginDevices::agentFamily($t->user_agent) : ($t->name ?? 'Admin panel'),
                'signed_in'   => $t->created_at,
                'last_used'   => $t->last_used_at ?? $t->created_at,
                'last_active' => $t->last_active_at,
                'locked'      => $t->locked_at !== null,
            ]);

        return response()->json(['data' => $rows]);
    }

    /** POST /api/v1/admin/users/{id}/sessions/revoke-all */
    public function revokeAll(Request $request, int $id)
    {
        $target  = $this->administrable($request, $id);
        $revoked = $target->tokens()->delete();

        ActivityLogService::log('sessions_revoked_by_admin', $target, ['count' => $revoked],
            "All sessions ended for {$target->email} ({$revoked})", $request->user());

        return response()->json(['message' => "Ended {$revoked} session(s).", 'revoked_count' => $revoked]);
    }

    /** POST /api/v1/admin/users/{id}/sessions/{tokenId}/revoke */
    public function revoke(Request $request, int $id, int $tokenId)
    {
        $target  = $this->administrable($request, $id);
        $deleted = $target->tokens()->whereKey($tokenId)->delete();
        if (!$deleted) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        ActivityLogService::log('session_revoked_by_admin', $target, ['token_id' => $tokenId],
            "Session #{$tokenId} ended for {$target->email}", $request->user());

        return response()->json(['message' => 'Session ended.']);
    }

    /**
     * POST /api/v1/admin/users/{id}/terminal-pin/reset — an outlet manager
     * clears a clerk's PIN at an outlet they both work (the clerk sets a new
     * one); super_admin may for any clerk. Nobody learns the PIN.
     */
    public function resetTerminalPin(Request $request, int $id, TerminalPin $pins)
    {
        $actor  = $request->user();
        $target = User::findOrFail($id);

        $sharesOutlet = DB::table('outlet_user as a')
            ->join('outlet_user as b', 'a.outlet_id', '=', 'b.outlet_id')
            ->where('a.user_id', $actor->id)
            ->where('b.user_id', $target->id)
            ->exists();

        $allowed = (int) $actor->id !== (int) $target->id
            && $target->hasRole('pos_clerk')
            && ($actor->hasRole('super_admin') || ($actor->hasRole('outlet_manager') && $sharesOutlet));

        if (!$allowed) {
            ActivityLogService::log('terminal_pin_reset_denied', $target, [], "Terminal PIN reset refused for {$target->email}", $actor);
            return response()->json(['message' => 'Only the outlet manager of this clerk’s outlet can reset their PIN.'], 403);
        }

        $pins->clear($target);
        ActivityLogService::log('terminal_pin_reset', $target, [], "Terminal PIN reset for {$target->email}", $actor);

        return response()->json(['message' => 'PIN cleared. The clerk sets a new one from their profile.']);
    }

    private function administrable(Request $request, int $id): User
    {
        $actor  = $request->user();
        $target = User::findOrFail($id);

        if (!$this->authority->mayAdminister($actor, $target)) {
            ActivityLogService::log('account_action_denied', $target, ['path' => $request->path()],
                "Refused: {$request->method()} {$request->path()}", $actor);
            abort(response()->json(['message' => $this->authority->refusal($actor, $target)], 403));
        }

        return $target;
    }

    private function lockState(User $u): array
    {
        return [
            'id'           => $u->id,
            'locked_at'    => $u->locked_at,
            'locked_until' => $u->locked_until,
            'lock_reason'  => $u->lock_reason,
        ];
    }
}
