<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use App\Services\ReceiptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Phase 5 - International Payment Approval
 *
 * Handles:
 *   POST   /admin/payments/{id}/upload-proof    Upload proof-of-payment file
 *   POST   /admin/payments/{id}/approve         Admin approves a payment
 *   POST   /admin/payments/{id}/reject          Admin rejects a payment (back to pending_review on re-upload)
 *   GET    /admin/payments/pending-approval      List all payments awaiting approval (admin inbox)
 *   GET    /admin/payments/{id}/proof            Serve proof file via signed URL
 */
class PaymentApprovalController extends Controller
{
    // Allowed proof file types
    const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
    ];

    const MAX_FILE_SIZE_KB = 10240; // 10 MB

    // =========================================================================
    // GET /admin/payments/pending-approval
    //
    // Admin inbox: all payments with requires_approval=true and
    // approval_status = pending_review, ordered oldest first.
    // =========================================================================

    public function pendingApprovals(Request $request)
    {
        $query = DB::table('payments as p')
            ->join('orders as o', 'p.order_id', '=', 'o.id')
            ->where('p.requires_approval', true)
            ->where('p.approval_status', 'pending_review');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('o.order_number', 'ILIKE', "%{$s}%")
                  ->orWhere('o.customer_first_name', 'ILIKE', "%{$s}%")
                  ->orWhere('o.customer_last_name',  'ILIKE', "%{$s}%")
                  ->orWhere('o.customer_email',      'ILIKE', "%{$s}%")
                  ->orWhere('u.first_name',           'ILIKE', "%{$s}%")
                  ->orWhere('u.last_name',            'ILIKE', "%{$s}%")
                  ->orWhere('u.email',                'ILIKE', "%{$s}%");
            });
        }

        $payments = $query
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->orderBy('p.created_at', 'desc')
            ->select(
                'p.id',
                'p.payment_number',
                'p.payment_method',
                'p.amount',
                'p.currency_code',
                'p.proof_of_payment_path',
                'p.proof_uploaded_at',
                'p.approval_status',
                'p.requires_approval',
                'p.created_at',
                'o.id as order_id',
                'o.order_number',
                DB::raw("COALESCE(NULLIF(TRIM(COALESCE(o.customer_first_name,'') || ' ' || COALESCE(o.customer_last_name,'')), ''), NULLIF(TRIM(COALESCE(u.first_name,'') || ' ' || COALESCE(u.last_name,'')), ''), o.customer_email, u.email, 'Unknown Customer') as customer_name"),
                DB::raw("COALESCE(o.customer_email, u.email) as customer_email"),
                DB::raw("COALESCE(o.customer_phone, u.phone) as customer_phone"),
                'o.customer_country_code',
                'o.order_type',
                'o.total_amount as order_total',
            )
            ->paginate((int) $request->get('per_page', 20));

        // Attach signed proof URLs
        $payments->getCollection()->transform(function ($p) {
            $p->proof_url = $p->proof_of_payment_path
                ? $this->buildProofUrl($p->id)
                : null;
            $p->waiting_hours = $p->created_at
                ? round((time() - strtotime($p->created_at)) / 3600, 1)
                : null;
            return $p;
        });

        $total = DB::table('payments')
            ->where('requires_approval', true)
            ->where('approval_status', 'pending_review')
            ->count();

        return response()->json([
            'data'          => $payments->items(),
            'meta'          => [
                'current_page' => $payments->currentPage(),
                'last_page'    => $payments->lastPage(),
                'total'        => $payments->total(),
            ],
            'pending_count' => $total,
        ]);
    }

    // =========================================================================
    // POST /admin/payments/{id}/upload-proof
    //
    // Staff uploads a proof-of-payment file (PDF / image, max 10 MB).
    // Sets approval_status = 'pending_review' and notifies admins.
    // Can be called multiple times (e.g. after rejection, to re-upload).
    // =========================================================================

    public function uploadProof(Request $request, $id)
    {
        $request->validate([
            'proof' => [
                'required',
                'file',
                'max:' . self::MAX_FILE_SIZE_KB,
                'mimetypes:' . implode(',', self::ALLOWED_MIME_TYPES),
            ],
        ]);

        $payment = Payment::with('order')->findOrFail($id);

        // Allow re-upload if pending or pending_review; block only if fully approved
        if ($payment->approval_status === 'approved') {
            return response()->json([
                'message' => 'This payment has already been approved. No further proof uploads are needed.',
                'hint'    => 'If you need to dispute this payment, please contact an administrator.',
            ], 422);
        }

        // If payment doesn't require approval, don't accept proof uploads
        if (!$payment->requires_approval) {
            return response()->json([
                'message' => 'This payment does not require proof of payment upload.',
            ], 422);
        }

        $file      = $request->file('proof');
        $extension = $file->getClientOriginalExtension();
        $year      = now()->year;
        $month     = now()->format('m');
        $filename  = Str::uuid() . '.' . $extension;
        $path      = "proofs/{$year}/{$month}/{$filename}";

        // Store in the private disk (not publicly accessible)
        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        // If there was a previous proof, delete it to save space
        if ($payment->proof_of_payment_path) {
            Storage::disk('local')->delete($payment->proof_of_payment_path);
        }

        $payment->update([
            'proof_of_payment_path' => $path,
            'proof_uploaded_at'     => now(),
            'requires_approval'     => true,
            'approval_status'       => 'pending_review',
            // Keep status as 'paid' on the payment record - it only becomes
            // effective after admin approval. The order payment_status is
            // NOT advanced to 'paid' yet.
        ]);

        // Notify admins that proof needs review (guard-safe: catch RoleDoesNotExist)
        try {
            NotificationService::paymentProofSubmitted(
                $payment->id,
                $payment->payment_number,
                $payment->order->order_number,
                $payment->order->id
            );
        } catch (\Spatie\Permission\Exceptions\RoleDoesNotExist $e) {
            // Role guard mismatch - notifications are non-critical, continue anyway
            \Illuminate\Support\Facades\Log::warning('NotificationService: role not found - ' . $e->getMessage());
        }

        try {
            ActivityLogService::log('payment_proof_uploaded', $payment->order, [
                'payment_id'     => $payment->id,
                'payment_number' => $payment->payment_number ?? null,
                'amount'         => (float) $payment->amount,
                'currency'       => $payment->currency_code,
                'method'         => $payment->payment_method,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'           => 'Proof uploaded. Awaiting admin approval.',
            'payment_id'        => $payment->id,
            'approval_status'   => 'pending_review',
            'proof_url'         => $this->buildProofUrl($payment->id),
        ]);
    }

    // =========================================================================
    // POST /admin/payments/{id}/approve
    //
    // Admin approves the payment. Marks the payment as fully effective,
    // re-syncs the order's payment_status, and unblocks receipt generation.
    // =========================================================================

    public function approve(Request $request, $id)
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $payment = Payment::with('order')->findOrFail($id);

        if (!$payment->requires_approval) {
            return response()->json(['message' => 'This payment does not require approval.'], 422);
        }

        if ($payment->approval_status === 'approved') {
            return response()->json(['message' => 'Payment is already approved.'], 422);
        }

        // Proof of payment is encouraged but not required — the admin may approve
        // without it at their own discretion (e.g. verbal/in-person confirmation).
        $approvedWithoutProof = empty($payment->proof_of_payment_path);

        DB::beginTransaction();
        try {
            $payment->update([
                'approval_status' => 'approved',
                'approved_by'     => $request->user()->id,
                'approved_at'     => now(),
                'approval_notes'  => $validated['notes'] ?? null,
                'status'          => 'paid',
                'paid_at'         => now(),
            ]);

            // Re-sync order payment_status based on all payments now being approved
            $order = $payment->order;
            $order->refresh();

            // Check if ANY remaining payments are still pending approval
            $stillPendingApproval = DB::table('payments')
                ->where('order_id', $order->id)
                ->where('requires_approval', true)
                ->where('approval_status', 'pending_review')
                ->exists();

            if (!$stillPendingApproval) {
                // All approval-required payments are now approved - re-sync properly
                $order->syncPaymentStatus();
                $order->refresh();

                // Quote-originated invoice? Issue a receipt for the approved
                // payment and commit reserved stock once fully paid. No-op
                // otherwise; best-effort so a receipt hiccup can't undo approval.
                try {
                    ReceiptService::onPaymentSettled($order, $payment, $request->user()->id);
                } catch (\Throwable $e) {
                    Log::warning('Receipt/commit after payment approval failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                }

                // Advance order status now that the payment block is lifted.
                // Payment approval means money is confirmed - NOT that fulfilment
                // is done. Advance to 'confirmed' so staff can proceed to ship/fulfil.
                // 'completed' is reserved for explicit staff action after delivery.
                if (in_array($order->payment_status, ['paid'])) {
                    // Fully paid and all approvals cleared → confirmed (ready to fulfil)
                    $order->update([
                        'status'       => 'confirmed',
                        'completed_at' => null,
                    ]);
                } elseif (in_array($order->status, ['pending', 'processing'])) {
                    // Partially paid or deposit → keep processing
                    $order->update(['status' => 'processing']);
                }
            }

            // Append note to order
            $note = "Payment {$payment->payment_number} approved by " . $request->user()->first_name . '.';
            if ($validated['notes']) {
                $note .= ' Note: ' . $validated['notes'];
            }
            $order->update(['notes' => ($order->notes ? $order->notes . "\n\n" : '') . $note]);

            DB::commit();

            // Notify the staff member who recorded the payment that it has been approved
            // (they can now generate the receipt). Use the order's created_by or cashier.
            $notifyUserId = $order->created_by ?? $order->cashier_id ?? null;
            NotificationService::paymentApproved(
                $payment->id,
                $payment->payment_number,
                $order->id,
                $order->order_number,
                $notifyUserId
            );

            ActivityLogService::log('payment_approved', $order, [
                'payment_id'            => $payment->id,
                'payment_number'        => $payment->payment_number ?? null,
                'amount'                => (float) $payment->amount,
                'currency'              => $order->currency_code,
                'method'                => $payment->payment_method,
                'notes'                 => $validated['notes'] ?? null,
                'approved_without_proof'=> $approvedWithoutProof,
                'new_order_status'      => $order->fresh()->status,
            ]);

            return response()->json([
                'message'        => 'Payment approved.',
                'payment_status' => $order->fresh()->payment_status,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to approve payment.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // POST /admin/payments/{id}/reject
    //
    // Admin rejects the payment proof. Sets approval_status = 'rejected'.
    // Staff can re-upload a new proof which resets status to pending_review.
    // =========================================================================

    public function reject(Request $request, $id)
    {
        $validated = $request->validate([
            'notes' => 'required|string|max:1000',
        ]);

        $payment = Payment::with('order')->findOrFail($id);

        if (!$payment->requires_approval) {
            return response()->json(['message' => 'This payment does not require approval.'], 422);
        }

        if ($payment->approval_status === 'approved') {
            return response()->json(['message' => 'Cannot reject an already approved payment.'], 422);
        }

        DB::beginTransaction();
        try {
            $payment->update([
                'approval_status' => 'rejected',
                'approved_by'     => $request->user()->id,
                'approved_at'     => now(),
                'approval_notes'  => $validated['notes'],
                'status'          => 'pending', // revert payment status
            ]);

            // Append rejection note to order
            $note = "Payment {$payment->payment_number} proof rejected. Reason: " . $validated['notes'];
            $payment->order->update([
                'notes' => ($payment->order->notes ? $payment->order->notes . "\n\n" : '') . $note,
            ]);

            ActivityLogService::log('payment_rejected', $payment->order, [
                'payment_id'     => $payment->id,
                'payment_number' => $payment->payment_number ?? null,
                'amount'         => (float) $payment->amount,
                'currency'       => $payment->order->currency_code,
                'method'         => $payment->payment_method,
                'reason'         => $validated['notes'],
            ]);

            DB::commit();

            // Notify the staff member who uploaded the proof that it was rejected
            $notifyUserId = $payment->order->created_by ?? $payment->order->cashier_id ?? null;
            NotificationService::paymentRejected(
                $payment->id,
                $payment->payment_number,
                $payment->order->id,
                $payment->order->order_number,
                $notifyUserId,
                $validated['notes']
            );

            return response()->json([
                'message' => 'Payment proof rejected. Staff has been notified to re-upload.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to reject payment.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // GET /admin/payments/{id}/proof
    //
    // Returns a short-lived signed URL to download the proof file.
    // Only accessible to admin users - enforced via route middleware.
    // =========================================================================

    public function serveProof($id)
    {
        $payment = Payment::findOrFail($id);

        if (!$payment->proof_of_payment_path) {
            return response()->json(['message' => 'No proof of payment on file.'], 404);
        }

        if (!Storage::disk('local')->exists($payment->proof_of_payment_path)) {
            return response()->json(['message' => 'Proof file not found on storage.'], 404);
        }

        // Always stream the raw file bytes so the frontend can load them
        // directly as a blob URL - works with any storage driver and avoids
        // the JSON-wrapping / signed-URL approach that breaks image rendering.
        $content  = Storage::disk('local')->get($payment->proof_of_payment_path);
        $mime     = Storage::disk('local')->mimeType($payment->proof_of_payment_path) ?: 'application/octet-stream';
        $filename = basename($payment->proof_of_payment_path);

        return response($content, 200)
            ->header('Content-Type',        $mime)
            ->header('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->header('Cache-Control',       'private, max-age=300');
    }

    // =========================================================================
    // GET /admin/payments/cash-report
    //
    // Returns all cash payments within a date range for daily reconciliation.
    // Filterable by outlet_id and date range (defaults to today).
    // =========================================================================

    public function cashReport(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
            'outlet_id'  => 'nullable|integer|exists:outlets,id',
        ]);

        $start = isset($validated['start_date'])
            ? \Carbon\Carbon::parse($validated['start_date'])->startOfDay()
            : now()->startOfDay();

        $end = isset($validated['end_date'])
            ? \Carbon\Carbon::parse($validated['end_date'])->endOfDay()
            : now()->endOfDay();

        $query = DB::table('payments as p')
            ->join('orders as o', 'p.order_id', '=', 'o.id')
            ->leftJoin('outlets as out', 'o.outlet_id', '=', 'out.id')
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->where('p.payment_method', 'cash')
            ->where('p.status', 'paid')
            ->whereBetween('p.created_at', [$start, $end]);

        if (!empty($validated['outlet_id'])) {
            $query->where('o.outlet_id', $validated['outlet_id']);
        }

        $payments = $query
            ->orderBy('p.created_at', 'asc')
            ->select(
                'p.id',
                'p.payment_number',
                'p.amount',
                'p.currency_code',
                'p.cash_received',
                'p.change_given',
                'p.created_at as paid_at',
                'o.id as order_id',
                'o.order_number',
                'o.outlet_id',
                'out.name as outlet_name',
                DB::raw("COALESCE(NULLIF(TRIM(COALESCE(o.customer_first_name,'') || ' ' || COALESCE(o.customer_last_name,'')), ''), o.customer_email, u.email, 'Walk-in') as customer_name"),
                DB::raw("COALESCE(o.customer_phone, u.phone) as customer_phone"),
                'o.total_amount as order_total',
            )
            ->get();

        $summary = [
            'total_cash_collected' => round($payments->sum('amount'), 2),
            'total_change_given'   => round($payments->sum('change_given'), 2),
            'transaction_count'    => $payments->count(),
            'currency_code'        => $payments->first()?->currency_code ?? 'KES',
            'start_date'           => $start->toDateString(),
            'end_date'             => $end->toDateString(),
        ];

        return response()->json([
            'summary'  => $summary,
            'payments' => $payments,
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function buildProofUrl(int $paymentId): string
    {
        return url("/api/v1/admin/payments/{$paymentId}/proof");
    }
}