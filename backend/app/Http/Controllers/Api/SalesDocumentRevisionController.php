<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentRevision;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SalesDocument;
use App\Services\Documents\SalesDocumentRevisions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * An order's issued sales documents and their revisions (owner, 2026-10-16):
 * the invoice copy's history, and the owner's correction of a payment's
 * details (its receipt follows). The order is found through its own viewer
 * scope, so nobody reads the history of a sale they could not open.
 */
class SalesDocumentRevisionController extends Controller
{
    /** GET /api/v1/admin/orders/{id}/document-revisions — the invoice copy and every payment's history. */
    public function index(int $id): JsonResponse
    {
        $order   = Order::findOrFail($id);
        $invoice = SalesDocument::where('type', SalesDocument::INVOICE)
            ->where('documentable_type', Order::class)->where('documentable_id', $order->id)->first();
        $paymentIds = Payment::where('order_id', $order->id)->pluck('id');

        return response()->json([
            'invoice' => $invoice ? [
                'number'    => $invoice->number,
                'status'    => $invoice->status,
                'revision'  => (int) $invoice->revision,
                'revisions' => DocumentRevision::listFor(DocumentRevision::INVOICE, $invoice->id),
            ] : null,
            'payments' => $paymentIds->mapWithKeys(fn ($pid) => [
                $pid => DocumentRevision::listFor(DocumentRevision::PAYMENT, (int) $pid),
            ])->filter(fn ($rows) => $rows !== [])->all(),
        ]);
    }

    /**
     * PUT /api/v1/admin/orders/{id}/payments/{paymentId}/revise — the owner
     * corrects a payment's reference, phone, method or date, with a reason.
     * The amount is never changed here: void it and record it again.
     */
    public function revisePayment(Request $request, int $id, int $paymentId): JsonResponse
    {
        if (!$request->user()?->hasRole('super_admin', 'sanctum')) {
            return response()->json(['message' => 'Only the owner (super admin) corrects a recorded payment.'], 403);
        }
        $order   = Order::findOrFail($id);
        $payment = Payment::where('order_id', $order->id)->findOrFail($paymentId);

        $methods = DB::table('payment_methods')->pluck('code')->all();
        $validated = $request->validate([
            'reason'             => 'required|string|min:5|max:500',
            'provider_reference' => 'nullable|string|max:100',
            'phone_number'       => 'nullable|string|max:20',
            'payment_method'     => ['sometimes', 'string', \Illuminate\Validation\Rule::in($methods)],
            'paid_at'            => 'nullable|date',
            'amount'             => 'prohibited',
        ], ['amount.prohibited' => 'The amount of a recorded payment is not edited: void it and record it again.']);

        $fields = array_intersect_key($validated, array_flip(['provider_reference', 'phone_number', 'payment_method', 'paid_at']));
        $fresh  = SalesDocumentRevisions::revisePayment($payment, $fields, trim($validated['reason']), $request->user());

        return response()->json(['message' => 'Payment details corrected.', 'payment' => $fresh]);
    }
}
