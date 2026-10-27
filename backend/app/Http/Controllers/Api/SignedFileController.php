<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DownloadRequest;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\Payment;
use App\Models\ShipmentAttachment;
use App\Models\User;
use App\Services\Downloads\DownloadPolicy;
use App\Services\Downloads\DownloadRecorder;
use App\Support\SignedFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The far end of a signed attachment link (App\Support\SignedFiles).
 *
 * Every route here sits behind `signed:relative`: an expired, altered or
 * unsigned link is a 403 before this code runs. Nothing else is checked —
 * the parent-record decision was made by the issuer, for the staff member
 * whose id is signed into `u`.
 */
class SignedFileController extends Controller
{
    public function paymentProof(Request $request, int $payment)
    {
        $path = (string) Payment::whereKey($payment)->value('proof_of_payment_path');
        return SignedFiles::stream($request, 'local', $path);
    }

    public function shipmentAttachment(Request $request, int $attachment)
    {
        $row = ShipmentAttachment::find($attachment);
        return $row
            ? SignedFiles::stream($request, 'local', (string) $row->path, $row->original_name)
            : response()->json(['message' => 'File not found.'], 404);
    }

    /** A kiosk punch photo (People C2): private disk, issued by KioskController::photoLink to attendance.view_team. */
    public function attendancePhoto(Request $request, int $entry, string $which)
    {
        $row = \App\Models\TimeEntry::find($entry);
        $path = $row ? ($which === 'in' ? $row->clock_in_photo_path : $row->clock_out_photo_path) : null;
        if (!$path) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        return SignedFiles::stream($request, 'private', (string) $path, "punch-{$entry}-{$which}.jpg");
    }

    /** A photo on an Updates post (People C3): private disk, issued by UpdatesController::photoLink to whoever may see the post. */
    public function updatePhoto(Request $request, int $post)
    {
        $path = \App\Models\Post::withTrashed()->find($post)?->photo_path;
        if (!$path) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        return SignedFiles::stream($request, 'private', (string) $path, basename($path));
    }

    /** A photo or the signature on a form submission (People C4): private disk, issued by FormsController::fileLink. */
    public function formFile(Request $request, int $submission, string $key)
    {
        $row = \App\Models\FormSubmission::find($submission);
        $path = $row ? ($key === 'signature' ? $row->signature_path : ($row->photos[$key] ?? null)) : null;
        if (!$path) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        return SignedFiles::stream($request, 'private', (string) $path, basename($path));
    }

    public function channelAttachment(Request $request)
    {
        // The issuer validated the path's shape; the signature pins it.
        return SignedFiles::stream($request, 'local', (string) $request->query('path', ''));
    }

    /**
     * Receipts are an EXEMPT download (config audit.downloads.exempt): recorded
     * for the owner's digest, never held. DownloadGate used to see the bytes
     * leave the issuer; they now leave here, so this records them, against the
     * staff member the link was issued to.
     */
    public function expenseReceipt(Request $request, DownloadRecorder $recorder, int $expense)
    {
        $path     = (string) Expense::whereKey($expense)->value('receipt_path');
        $response = SignedFiles::stream($request, 'private', $path);

        return $this->recordExempt($request, $recorder, $response, 'ExpenseController@downloadReceipt', ['expense' => $expense]);
    }

    /** Expense evidence (2026-10-16): exempt like the receipt, recorded the same way. */
    public function expenseAttachment(Request $request, DownloadRecorder $recorder, int $attachment)
    {
        $row = ExpenseAttachment::find($attachment);
        if (!$row) {
            return response()->json(['message' => 'File not found.'], 404);
        }
        $response = SignedFiles::stream($request, $row->disk ?: 'private', (string) $row->path, $row->original_name);

        return $this->recordExempt($request, $recorder, $response, 'ExpenseController@attachmentLink', ['attachment' => $attachment]);
    }

    public function staffDocument(Request $request, DownloadRecorder $recorder, int $document)
    {
        $row = \App\Models\StaffDocument::whereNull('removed_at')->find($document);
        if (!$row) {
            return response()->json(['message' => 'File not found.'], 404);
        }
        $response = SignedFiles::stream($request, $row->disk ?: 'private', (string) $row->path, $row->original_name);

        return $this->recordExempt($request, $recorder, $response, 'PeopleController@documentLink', ['document' => $document]);
    }

    private function recordExempt(Request $request, DownloadRecorder $recorder, $response, string $action, array $context)
    {
        // If the fetch also carried a staff bearer token, DownloadGate records
        // it (this action is listed exempt) — recording here too would double it.
        if (auth('sanctum')->user()) {
            return $response;
        }

        $user = User::find((int) $request->query('u'));
        if ($user && $response->isSuccessful()) {
            // The signature is a (short-lived) credential: not kept in the ledger.
            $request->query->remove('signature');
            $request->query->remove('expires');
            try {
                $dr = $recorder->open($request, $user, $action, DownloadPolicy::EXEMPT, DownloadRequest::DOWNLOADED);
                return $recorder->finalize($dr, $request, $response);
            } catch (\Throwable $e) {
                Log::error('exempt receipt download recording failed', ['error' => $e->getMessage()] + $context);
            }
        }

        return $response;
    }
}
