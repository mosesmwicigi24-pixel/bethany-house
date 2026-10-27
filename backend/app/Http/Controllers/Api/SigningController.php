<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\SigningRequest;
use App\Models\StaffDocument;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use App\Support\SignedFiles;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Document signing (People C4 part 2). HR (hr.edit, own outlets) asks a
 * person to sign a staff document, a handbook article or a block of text.
 * The person reads it on the phone and signs; the hub writes a signing
 * record PDF and files it on the employee file (kind `signed`). Declining
 * needs a note; cancelling a pending request is HR's.
 */
class SigningController extends Controller
{
    // ── HR ────────────────────────────────────────────────────────────────

    /** GET /people/{user}/signing */
    public function index(Request $request, int $user): JsonResponse
    {
        $person = $this->visiblePerson($request->user(), $user);
        return response()->json(['data' => SigningRequest::with(['requester:id,first_name,last_name', 'signedDocument'])->where('user_id', $person->id)->orderByDesc('id')->get()->map(fn ($r) => $this->present($r))->values()]);
    }

    /** POST /people/{user}/signing {kind, staff_document_id | article_id | title+body, title?, due_at?} */
    public function store(Request $request, int $user): JsonResponse
    {
        $person = $this->visiblePerson($request->user(), $user);
        $v = $request->validate([
            'kind' => ['required', Rule::in(SigningRequest::KINDS)], 'staff_document_id' => 'nullable|integer', 'article_id' => 'nullable|integer',
            'title' => 'nullable|string|max:140', 'body' => 'nullable|string|max:50000', 'due_at' => 'nullable|date',
        ]);
        $data = ['user_id' => $person->id, 'requested_by' => $request->user()->id, 'kind' => $v['kind'], 'due_at' => $v['due_at'] ?? null, 'status' => 'pending'];
        if ($v['kind'] === 'document') {
            $doc = $person->staffDocuments()->current()->whereKey($v['staff_document_id'] ?? 0)->first();
            if (!$doc) return response()->json(['message' => 'Choose one of their documents.'], 422);
            $data += ['staff_document_id' => $doc->id, 'title' => ($v['title'] ?? null) ?: $doc->title, 'source_hash' => $this->hashOf($doc->disk ?: 'private', $doc->path)];
        } elseif ($v['kind'] === 'article') {
            $a = Article::published()->whereKey($v['article_id'] ?? 0)->first();
            if (!$a) return response()->json(['message' => 'Choose a published handbook article.'], 422);
            $data += ['article_id' => $a->id, 'title' => ($v['title'] ?? null) ?: $a->title, 'body' => $a->body, 'source_hash' => hash('sha256', $a->body)];
        } else {
            if (trim((string) ($v['title'] ?? '')) === '' || trim((string) ($v['body'] ?? '')) === '') return response()->json(['message' => 'Give the text a title and the words to sign.'], 422);
            $data += ['title' => $v['title'], 'body' => $v['body'], 'source_hash' => hash('sha256', $v['body'])];
        }
        $r = SigningRequest::create($data);
        ActivityLogService::log('signing_requested', $r, ['user_id' => $person->id, 'kind' => $r->kind, 'title' => $r->title], "{$request->user()->first_name} asked {$person->first_name} to sign \"{$r->title}\"");
        NotificationService::deliver($person, new InAppNotification(title: "Please sign: {$r->title}", body: ($r->due_at ? 'By ' . $r->due_at->format('D j M') . '. ' : '') . 'Read it and sign on your phone.', actionUrl: '/me/sign', icon: 'document', data: ['signing_id' => $r->id]));

        return response()->json(['message' => "{$person->first_name} has been asked to sign.", 'request' => $this->present($r->fresh(['requester']))], 201);
    }

    /** DELETE /people/{user}/signing/{id} — cancel a pending request. */
    public function cancel(Request $request, int $user, int $id): JsonResponse
    {
        $person = $this->visiblePerson($request->user(), $user);
        $r = SigningRequest::where('user_id', $person->id)->findOrFail($id);
        abort_unless($r->status === 'pending', 422, 'Only a pending request can be cancelled.');
        $r->update(['status' => 'cancelled', 'note' => 'Cancelled by ' . $request->user()->first_name]);
        ActivityLogService::log('signing_cancelled', $r, [], "{$request->user()->first_name} cancelled the signing request \"{$r->title}\"");
        return response()->json(['message' => 'Cancelled.']);
    }

    // ── The signer ────────────────────────────────────────────────────────

    /** GET /me/sign — pending first, then history. */
    public function mine(Request $request): JsonResponse
    {
        $rows = SigningRequest::with(['requester:id,first_name,last_name', 'signedDocument'])->where('user_id', $request->user()->id)->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->orderByDesc('id')->get();
        return response()->json(['data' => $rows->map(fn ($r) => $this->present($r))->values(), 'pending' => $rows->where('status', 'pending')->count()]);
    }

    /** GET /me/sign/{id} — the words, or a signed link to the document. */
    public function show(Request $request, int $id): JsonResponse
    {
        $r = SigningRequest::with(['requester:id,first_name,last_name', 'document', 'signedDocument'])->where('user_id', $request->user()->id)->findOrFail($id);
        $out = $this->present($r);
        $out['body'] = $r->body;
        if ($r->kind === 'document' && $r->document && !$r->document->removed_at) {
            $out['document_link'] = SignedFiles::issue($request, 'files.staff-document', ['document' => $r->document->id], $r->document->original_name ?: basename($r->document->path))->getData(true);
        }
        return response()->json(['request' => $out]);
    }

    /** POST /me/sign/{id}/sign {signature: PNG data URL} */
    public function sign(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $r = SigningRequest::with(['requester', 'document'])->where('user_id', $user->id)->findOrFail($id);
        abort_unless($r->status === 'pending', 422, 'This request is no longer open.');
        $v = $request->validate(['signature' => 'required|string|max:300000']);
        $png = $this->decodePng($v['signature']);
        if (!$png) return response()->json(['message' => 'Sign in the box to continue.', 'errors' => ['signature' => ['A signature is needed.']]], 422);
        if ($r->kind === 'document' && (!$r->document || $r->document->removed_at)) return response()->json(['message' => 'The document was removed; ask HR to send it again.'], 422);

        $now = now();
        $sigPath = "people/{$user->id}/signatures/signing-{$r->id}.png";
        Storage::disk('private')->put($sigPath, $png);
        $pdfPath = "people/{$user->id}/documents/signed-{$r->id}.pdf";
        Storage::disk('private')->put($pdfPath, $this->record($r, $user, $now, $request->ip(), $png));
        $doc = StaffDocument::create(['user_id' => $user->id, 'kind' => 'signed', 'title' => "Signed: {$r->title}", 'disk' => 'private', 'path' => $pdfPath, 'mime_type' => 'application/pdf',
            'size_bytes' => Storage::disk('private')->size($pdfPath), 'original_name' => "signed-{$r->id}.pdf", 'uploaded_by' => $r->requested_by ?? $user->id]);
        $r->update(['status' => 'signed', 'signed_at' => $now, 'signature_path' => $sigPath, 'signed_ip' => $request->ip(), 'signed_document_id' => $doc->id]);
        ActivityLogService::log('document_signed', $r, ['document_id' => $doc->id, 'kind' => $r->kind, 'source_hash' => $r->source_hash], "{$user->first_name} signed \"{$r->title}\"");
        if ($r->requester && $r->requester->status === 'active') {
            NotificationService::deliver($r->requester, new InAppNotification(title: "{$user->first_name} signed \"{$r->title}\"", body: 'The signing record is on their file.', actionUrl: "/people/{$user->id}", icon: 'document', data: ['signing_id' => $r->id]));
        }
        return response()->json(['message' => 'Signed. A copy is on your file.', 'request' => $this->present($r->fresh(['requester', 'signedDocument']))]);
    }

    /** POST /me/sign/{id}/decline {note} */
    public function decline(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $r = SigningRequest::with('requester')->where('user_id', $user->id)->findOrFail($id);
        abort_unless($r->status === 'pending', 422, 'This request is no longer open.');
        $v = $request->validate(['note' => 'required|string|min:3|max:1000']);
        $r->update(['status' => 'declined', 'note' => trim($v['note'])]);
        ActivityLogService::log('signing_declined', $r, ['note' => $r->note], "{$user->first_name} declined to sign \"{$r->title}\"");
        if ($r->requester && $r->requester->status === 'active') {
            NotificationService::deliver($r->requester, new InAppNotification(title: "{$user->first_name} declined to sign \"{$r->title}\"", body: $r->note, actionUrl: "/people/{$user->id}", icon: 'document', data: ['signing_id' => $r->id]));
        }
        return response()->json(['message' => 'Declined. HR has been told why.', 'request' => $this->present($r->fresh(['requester']))]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** hr.edit sees people in own outlets (all with outlets.all_access) — the same rule as the employee file. */
    private function visiblePerson(User $viewer, int $id): User
    {
        $person = User::where('user_type', 'staff')->findOrFail($id);
        if (!$viewer->can('outlets.all_access')) {
            $mine = DB::table('outlet_user')->where('user_id', $viewer->id)->pluck('outlet_id');
            abort_unless(DB::table('outlet_user')->where('user_id', $person->id)->whereIn('outlet_id', $mine)->exists(), 403, 'Not in your outlets.');
        }
        return $person;
    }

    private function hashOf(string $disk, string $path): ?string
    {
        $store = Storage::disk($disk);
        return $store->exists($path) ? hash('sha256', (string) $store->get($path)) : null;
    }

    private function decodePng(string $dataUrl): ?string
    {
        if (!str_starts_with($dataUrl, 'data:image/png;base64,')) return null;
        $bytes = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);
        if ($bytes === false || strlen($bytes) > 220_000) return null;
        $info = @getimagesizefromstring($bytes);
        return $info && ($info['mime'] ?? '') === 'image/png' ? $bytes : null;
    }

    /** The one-page signing record. */
    private function record(SigningRequest $r, User $signer, \Carbon\Carbon $at, ?string $ip, string $png): string
    {
        $e = fn ($s) => e((string) $s);
        $source = match ($r->kind) {
            'document' => 'Staff document "' . $e($r->document?->title) . '" (' . $e($r->document?->original_name) . '), SHA-256 ' . $e($r->source_hash),
            'article'  => 'Handbook article "' . $e($r->title) . '", text SHA-256 ' . $e($r->source_hash),
            default    => 'Text as shown below, SHA-256 ' . $e($r->source_hash),
        };
        $body = $r->kind === 'document' ? '' : '<h3>What was signed</h3><div class="body">' . nl2br($e($r->body)) . '</div>';
        $html = '<html><head><meta charset="utf-8"><style>body{font-family:Helvetica,Arial,sans-serif;font-size:12px;color:#222;margin:36px}h1{font-size:18px;margin:0 0 4px}h3{font-size:12px;margin:18px 0 6px;text-transform:uppercase;color:#666}table{border-collapse:collapse}td{padding:3px 10px 3px 0;vertical-align:top}.body{white-space:pre-wrap;border:1px solid #ddd;padding:10px;font-size:11px}.sig{border:1px solid #999;padding:6px;display:inline-block}.muted{color:#777;font-size:10px}</style></head><body>'
            . '<h1>Signing record</h1><div class="muted">Bethany House Hub · signing request #' . $r->id . '</div>'
            . '<table><tr><td>Title</td><td><b>' . $e($r->title) . '</b></td></tr><tr><td>Signed by</td><td>' . $e(trim("{$signer->first_name} {$signer->last_name}")) . ' (' . $e($signer->email) . ')</td></tr>'
            . '<tr><td>Signed at</td><td>' . $e($at->format('j F Y, H:i')) . ' (Nairobi)</td></tr><tr><td>From</td><td>' . $e($ip ?: 'unknown') . '</td></tr>'
            . '<tr><td>Requested by</td><td>' . $e($r->requester ? trim("{$r->requester->first_name} {$r->requester->last_name}") : '—') . ' on ' . $e($r->created_at->format('j F Y')) . '</td></tr>'
            . '<tr><td>Source</td><td>' . $source . '</td></tr></table>'
            . '<h3>Signature</h3><div class="sig"><img src="data:image/png;base64,' . base64_encode($png) . '" style="height:70px"></div>'
            . $body . '<p class="muted" style="margin-top:18px">Written by the Hub the moment the signature was given; the hash lets the signed text or file be checked against this record later.</p></body></html>';
        return Pdf::loadHTML($html)->setPaper('A4', 'portrait')->setOption('defaultFont', 'Helvetica')->output();
    }

    private function present(SigningRequest $r): array
    {
        return ['id' => $r->id, 'kind' => $r->kind, 'title' => $r->title, 'status' => $r->status, 'due_at' => $r->due_at?->toIso8601String(), 'signed_at' => $r->signed_at?->toIso8601String(),
            'note' => $r->note, 'requested_by' => $r->requester ? trim("{$r->requester->first_name} {$r->requester->last_name}") : null, 'created_at' => $r->created_at?->toIso8601String(),
            'staff_document_id' => $r->staff_document_id, 'article_id' => $r->article_id, 'signed_document_id' => $r->signed_document_id];
    }
}
