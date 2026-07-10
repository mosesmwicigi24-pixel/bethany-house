<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SalesDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invoices — the middle of the quotation → invoice → receipt lifecycle. An invoice
 * is the INVOICE row in sales_documents (documentable = the Order it bills, parent
 * = the QUO it derives from). This surfaces them as their own section, each linked
 * back to its quotation and forward to its order.
 */
class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 25), 100);

        $query = SalesDocument::where('type', SalesDocument::INVOICE)
            ->with(['documentable', 'parent'])
            ->latest('id');

        if ($request->filled('search')) {
            $s = trim((string) $request->string('search'));
            $query->where('number', 'ILIKE', "%{$s}%");
        }
        // status = issued | paid (the document's own state).
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $docs = $query->paginate($perPage);

        $docs->getCollection()->transform(fn (SalesDocument $doc) => $this->present($doc));

        return response()->json($docs);
    }

    public function show(int $id): JsonResponse
    {
        $doc = SalesDocument::where('type', SalesDocument::INVOICE)
            ->with(['documentable.items', 'parent'])
            ->findOrFail($id);

        return response()->json(['invoice' => $this->present($doc)]);
    }

    /** Flatten an invoice document into a row with its order + quotation links. */
    private function present(SalesDocument $doc): array
    {
        $order = $doc->documentable instanceof Order ? $doc->documentable : null;
        $customer = $order
            ? trim(($order->customer_first_name ?? '') . ' ' . ($order->customer_last_name ?? ''))
            : null;

        return [
            'id'             => $doc->id,
            'invoice_number' => $doc->number,
            'issued_at'      => optional($doc->issued_at)->toIso8601String(),
            'due_date'       => optional($doc->due_date)->toDateString(),
            'amount'         => (float) $doc->amount,
            'currency_code'  => $doc->currency_code,
            'doc_status'     => $doc->status,   // issued | paid | void
            'order'          => $order ? [
                'id'             => $order->id,
                'order_number'   => $order->order_number,
                'payment_status' => $order->payment_status,   // pending | partial | paid …
                'pay_token'      => $order->payment_token,
            ] : null,
            'customer_name'  => $customer ?: null,
            'quotation'      => $doc->parent ? [
                'number'          => $doc->parent->number,
                'quotation_id'    => $doc->parent->documentable_id,
                'sales_doc_id'    => $doc->parent->id,
            ] : null,
        ];
    }
}
