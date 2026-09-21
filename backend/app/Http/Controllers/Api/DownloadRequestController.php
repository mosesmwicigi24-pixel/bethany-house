<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\DownloadApprovalRequestMail;
use App\Models\DownloadApprover;
use App\Models\DownloadRequest;
use App\Models\User;
use App\Notifications\DownloadApprovalRequiredNotification;
use App\Notifications\DownloadDecisionNotification;
use App\Services\ActivityLogService;
use App\Services\Downloads\DownloadPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Download approval workflow (see App\Http\Middleware\DownloadGate).
 *
 *   staff:      ask (turn a HELD attempt into a request, with a reason) · cancel ·
 *               see their own requests · take an approved download (single-use link)
 *   approvers:  the owner account + managers he delegates to — approve / deny,
 *               never their own request
 *   owner only: every download ever recorded · the archived copy of a file ·
 *               who else may approve
 *
 * Every transition is written to the audit trail by name here, and as a
 * before/after diff by the AuditObserver (DownloadRequest is observed).
 */
class DownloadRequestController extends Controller
{
    public function __construct(private DownloadPolicy $policy) {}

    /** GET /admin/downloads/capabilities — what the console may show this person. */
    public function capabilities(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json([
            'can_approve' => $this->policy->canApprove($user),
            'is_owner'    => $this->policy->isOwner($user),
            'enforcing'   => (bool) config('audit.downloads.enforce', false),
            'pending'     => $this->policy->canApprove($user)
                ? DownloadRequest::where('status', DownloadRequest::PENDING)->where('user_id', '!=', $user->id)->count()
                : 0,
        ]);
    }

    /** GET /admin/downloads/mine */
    public function mine(Request $request): JsonResponse
    {
        $this->expireStale();

        return response()->json(
            DownloadRequest::with('decider:id,first_name,last_name')
                ->where('user_id', $request->user()->id)
                ->whereIn('status', [DownloadRequest::PENDING, DownloadRequest::APPROVED, DownloadRequest::DENIED,
                                     DownloadRequest::DOWNLOADED, DownloadRequest::EXPIRED, DownloadRequest::CANCELLED])
                ->whereNotNull('reason')     // requests they made, not every exempt receipt they printed
                ->latest()
                ->paginate(min((int) $request->get('per_page', 20), 100))
        );
    }

    /** POST /admin/downloads/requests  {held, reason} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'held'   => 'required|uuid',
            'reason' => 'required|string|min:5|max:1000',
        ]);
        $user = $request->user();

        $dr = DownloadRequest::where('uuid', $data['held'])->where('user_id', $user->id)->first();
        if (!$dr || $dr->status !== DownloadRequest::HELD) {
            return response()->json(['message' => 'That download is no longer waiting to be requested. Try the download again.'], 422);
        }
        if ($dr->created_at->lt(now()->subHours((int) config('audit.downloads.request_ttl_hours', 24)))) {
            $dr->forceFill(['status' => DownloadRequest::EXPIRED])->save();
            return response()->json(['message' => 'That attempt is too old. Try the download again.'], 422);
        }

        $dr->forceFill(['status' => DownloadRequest::PENDING, 'reason' => trim($data['reason'])])->save();

        ActivityLogService::log('download_requested', $dr, [
            'what' => $dr->label, 'path' => $dr->path, 'filters' => $dr->payload, 'reason' => $dr->reason,
        ], "Asked to download {$dr->label}", $user);

        $this->tellApprovers($dr, $user);

        return response()->json(['message' => 'Sent for approval.', 'request' => $dr->fresh()], 201);
    }

    /** POST /admin/downloads/requests/{uuid}/cancel */
    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $dr = $this->own($request, $uuid);
        if (!in_array($dr->status, [DownloadRequest::PENDING, DownloadRequest::APPROVED, DownloadRequest::HELD], true)) {
            return response()->json(['message' => "This request is already {$dr->status}."], 422);
        }
        $dr->forceFill(['status' => DownloadRequest::CANCELLED, 'token_hash' => null])->save();
        ActivityLogService::log('download_request_cancelled', $dr, [], "Cancelled request to download {$dr->label}", $request->user());

        return response()->json(['message' => 'Cancelled.']);
    }

    /**
     * POST /admin/downloads/requests/{uuid}/token — a single-use link for an
     * approved request. The console replays the original request with it.
     */
    public function token(Request $request, string $uuid): JsonResponse
    {
        $this->expireStale();
        $dr = $this->own($request, $uuid);
        if ($dr->status !== DownloadRequest::APPROVED) {
            return response()->json(['message' => "This request is {$dr->status}."], 422);
        }

        $token = Str::random(48);
        $dr->forceFill([
            'token_hash'       => hash('sha256', $token),
            'token_expires_at' => now()->addMinutes((int) config('audit.downloads.token_ttl_minutes', 30)),
        ])->save();

        return response()->json([
            'token'   => $token,
            'header'  => \App\Http\Middleware\DownloadGate::TOKEN_HEADER,
            'method'  => $dr->method,
            'path'    => $dr->path,
            'payload' => $dr->payload,
            'label'   => $dr->label,
        ]);
    }

    /** GET /admin/downloads/requests — approvers: what is waiting (and recent decisions). */
    public function index(Request $request): JsonResponse
    {
        $this->assertApprover($request->user());
        $this->expireStale();

        $q = DownloadRequest::with(['user:id,first_name,last_name,email', 'decider:id,first_name,last_name'])
            ->whereNotNull('reason')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')),
                   fn ($q) => $q->whereIn('status', [DownloadRequest::PENDING, DownloadRequest::APPROVED, DownloadRequest::DENIED, DownloadRequest::DOWNLOADED]))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest();

        return response()->json($q->paginate(min((int) $request->get('per_page', 30), 100)));
    }

    /** POST /admin/downloads/requests/{uuid}/approve  {note?} */
    public function approve(Request $request, string $uuid): JsonResponse
    {
        return $this->decide($request, $uuid, true);
    }

    /** POST /admin/downloads/requests/{uuid}/deny  {note} */
    public function deny(Request $request, string $uuid): JsonResponse
    {
        $request->validate(['note' => 'required|string|min:3|max:1000']);
        return $this->decide($request, $uuid, false);
    }

    /** GET /admin/downloads/all — owner: every download recorded, of every kind. */
    public function all(Request $request): JsonResponse
    {
        $this->assertOwner($request->user());

        $q = DownloadRequest::with(['user:id,first_name,last_name,email', 'decider:id,first_name,last_name'])
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->input('category')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', (int) $request->input('user_id')))
            ->when($request->filled('uuid'), fn ($q) => $q->where('uuid', $request->input('uuid')))
            ->latest();

        return response()->json($q->paginate(min((int) $request->get('per_page', 30), 100)));
    }

    /** GET /admin/downloads/{uuid}/archive — owner: the file exactly as it left. */
    public function archive(Request $request, string $uuid)
    {
        $this->assertOwner($request->user());
        $dr = DownloadRequest::where('uuid', $uuid)->firstOrFail();
        $disk = Storage::disk(config('audit.downloads.archive_disk', 'local'));
        if (!$dr->archive_path || !$disk->exists($dr->archive_path)) {
            return response()->json(['message' => 'No archived copy (backups are not copied; archives are kept 90 days).'], 404);
        }

        ActivityLogService::log('download_archive_opened', $dr, [], "Owner opened the archived copy of {$dr->export_id}", $request->user());

        return $disk->download($dr->archive_path, $dr->file_name ?: $dr->export_id, [
            'Content-Type' => $dr->content_type ?: 'application/octet-stream',
        ]);
    }

    /** GET /admin/downloads/approvers */
    public function approvers(Request $request): JsonResponse
    {
        $this->assertApprover($request->user());
        $owner = $this->policy->owner();

        return response()->json([
            'owner'     => $owner?->only(['id', 'first_name', 'last_name', 'email']),
            'delegates' => DownloadApprover::with(['user:id,first_name,last_name,email', 'assignedBy:id,first_name,last_name'])->get(),
        ]);
    }

    /** POST /admin/downloads/approvers {user_id} — owner only */
    public function addApprover(Request $request): JsonResponse
    {
        $this->assertOwner($request->user());
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);

        $user = User::findOrFail($data['user_id']);
        if (!$user->canAccessAdmin() || $user->status !== 'active') {
            return response()->json(['message' => 'Only an active staff member can approve downloads.'], 422);
        }
        if ($this->policy->isOwner($user)) {
            return response()->json(['message' => 'The owner approves already.'], 422);
        }

        $row = DownloadApprover::firstOrCreate(['user_id' => $user->id], ['assigned_by' => $request->user()->id]);
        ActivityLogService::log('download_approver_added', $user, [], "Download approval delegated to {$user->email}", $request->user());

        return response()->json(['message' => 'Added.', 'approver' => $row->load('user:id,first_name,last_name,email')], 201);
    }

    /** DELETE /admin/downloads/approvers/{userId} — owner only */
    public function removeApprover(Request $request, int $userId): JsonResponse
    {
        $this->assertOwner($request->user());
        $deleted = DownloadApprover::where('user_id', $userId)->delete();
        if ($deleted) {
            ActivityLogService::log('download_approver_removed', User::find($userId), [], "Download approval withdrawn from user #{$userId}", $request->user());
        }

        return response()->json(['message' => $deleted ? 'Removed.' : 'Was not an approver.']);
    }

    // ── internals ─────────────────────────────────────────────────────────────

    private function decide(Request $request, string $uuid, bool $approve): JsonResponse
    {
        $approver = $request->user();
        $this->assertApprover($approver);
        $this->expireStale();

        $dr = DownloadRequest::where('uuid', $uuid)->firstOrFail();
        if (!$this->policy->mayDecide($approver, $dr)) {
            return response()->json(['message' => 'You cannot decide your own download request.'], 403);
        }
        if ($dr->status !== DownloadRequest::PENDING) {
            return response()->json(['message' => "This request is already {$dr->status}."], 422);
        }

        $dr->forceFill([
            'status'        => $approve ? DownloadRequest::APPROVED : DownloadRequest::DENIED,
            'decided_by'    => $approver->id,
            'decided_at'    => now(),
            'decision_note' => $request->filled('note') ? trim((string) $request->input('note')) : null,
        ])->save();

        ActivityLogService::log($approve ? 'download_approved' : 'download_denied', $dr, [
            'what' => $dr->label, 'requested_by' => $dr->user_id, 'note' => $dr->decision_note,
        ], ($approve ? 'Approved' : 'Denied') . " download of {$dr->label} for user #{$dr->user_id}", $approver);

        try {
            $dr->user?->notify(new DownloadDecisionNotification($dr, $approver));
        } catch (\Throwable $e) {
            Log::warning('download decision notification failed', ['error' => $e->getMessage()]);
        }

        return response()->json(['message' => $approve ? 'Approved.' : 'Denied.', 'request' => $dr->fresh(['decider'])]);
    }

    private function tellApprovers(DownloadRequest $dr, User $requester): void
    {
        foreach ($this->policy->approvers() as $approver) {
            if ($approver->id === $requester->id) {
                continue;
            }
            try {
                $approver->notify(new DownloadApprovalRequiredNotification($dr, $requester));
            } catch (\Throwable $e) {
                Log::warning('download approval notification failed', ['approver' => $approver->id, 'error' => $e->getMessage()]);
            }
        }

        // The owner hears by email too — he is the approver who is least often
        // looking at the hub when the request comes in.
        $to = (string) config('audit.owner_email');
        if ($to !== '') {
            try {
                Mail::to($to)->queue(new DownloadApprovalRequestMail($dr->load('user')));
            } catch (\Throwable $e) {
                Log::warning('download approval email failed', ['error' => $e->getMessage()]);
            }
        }
    }

    /** Requests and links do not live forever. */
    private function expireStale(): void
    {
        $cutoff = now()->subHours((int) config('audit.downloads.request_ttl_hours', 24));
        DownloadRequest::whereIn('status', [DownloadRequest::PENDING, DownloadRequest::APPROVED, DownloadRequest::HELD])
            ->where('updated_at', '<', $cutoff)
            ->get()
            ->each(function (DownloadRequest $dr) {
                $dr->forceFill(['status' => DownloadRequest::EXPIRED, 'token_hash' => null])->save();
                ActivityLogService::log('download_request_expired', $dr, [], "Download request {$dr->uuid} expired");
            });
    }

    private function own(Request $request, string $uuid): DownloadRequest
    {
        return DownloadRequest::where('uuid', $uuid)->where('user_id', $request->user()->id)->firstOrFail();
    }

    private function assertApprover(?User $user): void
    {
        abort_unless($this->policy->canApprove($user), 403, 'Only the owner and the managers he delegates to approve downloads.');
    }

    private function assertOwner(?User $user): void
    {
        abort_unless($this->policy->isOwner($user), 403, 'Only the owner can do this.');
    }
}
