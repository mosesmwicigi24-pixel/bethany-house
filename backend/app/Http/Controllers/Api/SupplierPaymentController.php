<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\SupplierPayment;
use App\Services\Procurement\SupplierPayables;
use App\Support\MakerChecker;
use App\Support\ReportingCurrency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Payments to suppliers against purchase orders (SupplierPayables holds the
 * rules). Not expenses: they never reach the P&L.
 */
class SupplierPaymentController extends Controller
{
    // GET /purchase-orders/{id}/payments
    public function index($id)
    {
        $po = PurchaseOrder::findOrFail($id);

        return response()->json([
            'summary'  => SupplierPayables::summary($po),
            'payments' => SupplierPayment::with(['recorder:id,first_name,last_name', 'voider:id,first_name,last_name'])
                ->where('purchase_order_id', $po->id)
                ->orderByDesc('paid_at')->orderByDesc('id')
                ->get(),
        ]);
    }

    // POST /purchase-orders/{id}/payments
    public function store(Request $request, $id)
    {
        $validated = $request->validate([
            'amount'            => 'required|numeric|gt:0|decimal:0,2',
            'method'            => 'required|in:' . implode(',', SupplierPayment::METHODS),
            'reference'         => 'nullable|string|max:120',
            'paid_at'           => 'nullable|date',
            'notes'             => 'nullable|string|max:2000',
            'client_request_id' => 'nullable|string|max:64',
        ]);

        $po      = PurchaseOrder::findOrFail($id);
        $payment = SupplierPayables::record($po, $validated, $request->user());

        return response()->json([
            'message' => "Payment {$payment->payment_number} recorded.",
            'payment' => $payment,
            'summary' => SupplierPayables::summary($po->fresh()),
        ], $payment->wasRecentlyCreated ? 201 : 200);
    }

    // POST /purchase-orders/{id}/payments/{paymentId}/void
    public function void(Request $request, $id, $paymentId)
    {
        $validated = $request->validate(['reason' => 'required|string|min:5|max:1000']);

        $payment = SupplierPayment::where('purchase_order_id', $id)->findOrFail($paymentId);

        // Whoever recorded the payment does not also undo it.
        MakerChecker::assertNotMaker($request->user(), 'supplier_payment.void', $payment, $payment->recorded_by);

        $payment = SupplierPayables::void($payment, $validated['reason'], $request->user());

        return response()->json([
            'message' => "Payment {$payment->payment_number} voided.",
            'payment' => $payment,
            'summary' => SupplierPayables::summary(PurchaseOrder::findOrFail($id)),
        ]);
    }

    /**
     * GET /purchase-orders/payables — what is owed to each supplier.
     *
     * Per supplier and currency: payable, paid and balance on approved
     * purchase orders, in the PO's currency, plus the balance in KES at
     * today's reporting rate (null when the currency has none). Credits
     * (overpaid) are shown, not netted away.
     */
    public function payables()
    {
        $rows = DB::select("
            SELECT po.supplier_id, s.name AS supplier, UPPER(po.currency_code) AS currency,
                   COUNT(*) AS orders,
                   SUM(COALESCE(po.supplier_invoice_amount, po.total_amount)) AS payable,
                   SUM(COALESCE(p.paid, 0)) AS paid
            FROM purchase_orders po
            JOIN suppliers s ON s.id = po.supplier_id
            LEFT JOIN (
                SELECT purchase_order_id, SUM(amount) AS paid
                FROM supplier_payments WHERE voided_at IS NULL
                GROUP BY purchase_order_id
            ) p ON p.purchase_order_id = po.id
            WHERE po.status IN ('" . implode("','", SupplierPayables::PAYABLE_STATUSES) . "')
            GROUP BY po.supplier_id, s.name, UPPER(po.currency_code)
            HAVING SUM(COALESCE(po.supplier_invoice_amount, po.total_amount)) - SUM(COALESCE(p.paid, 0)) <> 0
            ORDER BY s.name, currency
        ");

        $rates = ReportingCurrency::rates();
        $out = collect($rows)->map(function ($r) use ($rates) {
            $balance = round((float) $r->payable - (float) $r->paid, 2);
            $rate    = $rates[$r->currency] ?? null;

            return [
                'supplier_id' => (int) $r->supplier_id,
                'supplier'    => $r->supplier,
                'currency'    => $r->currency,
                'orders'      => (int) $r->orders,
                'payable'     => round((float) $r->payable, 2),
                'paid'        => round((float) $r->paid, 2),
                'balance'     => $balance,
                'balance_kes' => $rate === null ? null : round($balance * $rate, 2),
            ];
        })->values();

        return response()->json([
            'data'           => $out,
            'total_owed_kes' => round($out->sum(fn ($r) => max(0, (float) ($r['balance_kes'] ?? 0))), 2),
            'unconverted'    => $out->filter(fn ($r) => $r['balance_kes'] === null)->pluck('currency')->unique()->values(),
            'basis'          => 'Supplier invoice amount where recorded, else the PO total, on approved purchase orders; less payments not voided. KES at today\'s reporting rate.',
        ]);
    }
}
