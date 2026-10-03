<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DownloadRequest;
use App\Models\Expense;
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
                $dr = $recorder->open($request, $user, 'ExpenseController@downloadReceipt', DownloadPolicy::EXEMPT, DownloadRequest::DOWNLOADED);
                return $recorder->finalize($dr, $request, $response);
            } catch (\Throwable $e) {
                Log::error('exempt receipt download recording failed', ['error' => $e->getMessage(), 'expense' => $expense]);
            }
        }

        return $response;
    }
}
