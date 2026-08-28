<?php

namespace App\Http\Controllers\Api;

use App\Enums\DataScope;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SalesDocument;
use App\Services\DataScopeResolver;
use Illuminate\Database\Eloquent\Builder;
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
    /**
     * Bound an invoice query to the caller's data scope.
     *
     * Scoped HERE, not with a global ViewerScope on SalesDocument: that model
     * is also the document LEDGER — ReceiptService and the quotation lifecycle
     * read it to derive the next document number, and a global scope under a
     * narrow session would silently compute wrong sequences. The invoice
     * SCREENS are the only reads that should follow the viewer, so only they
     * do. Own-scope keys on created_by — the user shown as "served by".
     */
    private function scopeToViewer(Builder $query, Request $request): Builder
    {
        $scope = DataScopeResolver::for($request->user(), 'orders.view');

        if ($scope !== DataScope::All) {
            // Outlet scope collapses to Own here: sales_documents carries no
            // outlet column. No outlet-scoped role holds orders.view today; if
            // one ever does, this errs on showing less, never more.
            $query->where('created_by', $request->user()->id);
        }

        return $query;
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 25), 100);

        $query = $this->scopeToViewer(
            SalesDocument::query(), $request
        )->where('type', SalesDocument::INVOICE)
            ->with([
                // documentable is a morphTo, so the creator has to be loaded
                // per type — Quotation has no such relation and a plain
                // 'documentable.creator' would blow up on one.
                'documentable' => fn ($m) => $m->morphWith([
                    Order::class => ['creator:id,first_name,last_name'],
                ]),
                'parent',
                'creator:id,first_name,last_name',
            ])
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

    public function show(int $id, Request $request): JsonResponse
    {
        $doc = $this->scopeToViewer(SalesDocument::query(), $request)
            ->where('type', SalesDocument::INVOICE)
            ->with([
                'documentable' => fn ($m) => $m->morphWith([
                    Order::class => ['items', 'creator:id,first_name,last_name'],
                ]),
                'parent',
                'creator:id,first_name,last_name',
            ])
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
            // Who issued it. Falls back to whoever raised the underlying order
            // for documents created before created_by was recorded on them.
            'served_by'      => $doc->creator?->name ?: ($order?->creator?->name ?: null),
            'quotation'      => $doc->parent ? [
                'number'          => $doc->parent->number,
                'quotation_id'    => $doc->parent->documentable_id,
                'sales_doc_id'    => $doc->parent->id,
            ] : null,
        ];
    }
}
