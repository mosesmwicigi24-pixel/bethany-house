<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\GoodsReceivedNote;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\OrderShipment;
use App\Models\InventoryTransfer;
use App\Services\PdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * DocumentPdfController
 *
 * GET  /api/v1/admin/pdf/purchase-orders/{id}
 * GET  /api/v1/admin/pdf/grn/{id}
 * GET  /api/v1/admin/pdf/purchase-returns/{id}
 * GET  /api/v1/admin/pdf/orders/{id}
 * GET  /api/v1/admin/pdf/orders/{id}/invoice
 * GET  /api/v1/admin/pdf/shipments/{id}
 * GET  /api/v1/admin/pdf/returns/{id}
 * GET  /api/v1/admin/pdf/production-orders/{id}
 * GET  /api/v1/admin/pdf/stock-transfers/{id}
 * GET  /api/v1/admin/pdf/stock-adjustments/{id}
 * GET  /api/v1/admin/pdf/expenses/{id}
 *
 * All return application/pdf with Content-Disposition: attachment.
 */
class DocumentPdfController extends Controller
{
    // ─── DomPDF helper ───────────────────────────────────────────────────────

    private function makePdf(string $html, string $filename): Response
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filename);

        $pdf = Pdf::loadHTML($html)
            ->setPaper('A4', 'portrait')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isRemoteEnabled', true)
            ->setOption('defaultFont', 'Helvetica');

        return response($pdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$safe}.pdf\"",
            'Cache-Control'       => 'no-cache, no-store',
        ]);
    }

    // ─── Purchase Order ───────────────────────────────────────────────────────

    public function purchaseOrder(int $id): Response
    {
        $po = PurchaseOrder::with([
            'supplier:id,name,company_code,email,phone,address_line_1,city',
            'items.product:id,sku',
            'items.product.translations' => fn($q) => $q->where('language_code','en')->select('product_id','name'),
            'items.material:id,name,unit_of_measure',
            'createdBy:id,first_name,last_name',
        ])->findOrFail($id);

        $data                = $po->toArray();
        $sup = $po->supplier;
        $data['supplier'] = $sup ? [
            'id'           => $sup->id,
            'name'         => $sup->name,
            'company_code' => $sup->company_code,
            'email'        => $sup->email,
            'phone'        => $sup->phone,
            'address'      => trim(($sup->address_line_1 ?? '') . ($sup->city ? ', ' . $sup->city : '')),
        ] : null;
        $data['items']       = $po->items->map(function($item) {
            $arr              = $item->toArray();
            $arr['product']   = $item->product ? ['name' => $item->product->translations->first()?->name ?? $item->product->sku, 'sku' => $item->product->sku] : null;
            $arr['material']  = $item->material ? ['name' => $item->material->name, 'code' => $item->material->unit_of_measure ?? ''] : null;
            return $arr;
        })->toArray();

        $html = PdfService::purchaseOrder($data);
        return $this->makePdf($html, 'PO-' . ($po->po_number ?? $id));
    }

    // ─── GRN ─────────────────────────────────────────────────────────────────

    public function grn(int $id): Response
    {
        $grn = GoodsReceivedNote::with([
            'purchaseOrder:id,po_number',
            'purchaseOrder.supplier:id,name,company_code',
            'receivedBy:id,first_name,last_name',
            'items.purchaseOrderItem.product:id,sku',
            'items.purchaseOrderItem.product.translations' => fn($q) => $q->where('language_code','en')->select('product_id','name'),
            'items.purchaseOrderItem.material:id,name',
        ])->findOrFail($id);

        $data = $grn->toArray();
        $data['purchase_order'] = [
            'id'         => $grn->purchaseOrder?->id,
            'po_number'  => $grn->purchaseOrder?->po_number,
            'supplier'   => $grn->purchaseOrder?->supplier?->toArray(),
        ];
        $data['received_by'] = $grn->receivedBy?->toArray();
        $data['items'] = $grn->items->map(function($item) {
            $poi = $item->purchaseOrderItem;
            return array_merge($item->toArray(), [
                'purchase_order_item' => $poi ? array_merge($poi->toArray(), [
                    'product'  => $poi->product  ? ['name' => $poi->product->translations->first()?->name ?? $poi->product->sku, 'sku' => $poi->product->sku] : null,
                    'material' => $poi->material ? ['name' => $poi->material->name] : null,
                ]) : null,
            ]);
        })->toArray();

        $html = PdfService::grn($data);
        return $this->makePdf($html, 'GRN-' . ($grn->grn_number ?? $id));
    }

    // ─── Purchase Return ──────────────────────────────────────────────────────

    public function purchaseReturn(int $id): Response
    {
        $pr = PurchaseReturn::with([
            'purchaseOrder:id,po_number',
            'purchaseOrder.supplier:id,name',
            'supplier:id,name',
            'returnItems.purchaseOrderItem.product:id,sku',
            'returnItems.purchaseOrderItem.product.translations' => fn($q) => $q->where('language_code','en')->select('product_id','name'),
            'returnItems.purchaseOrderItem.material:id,name',
        ])->findOrFail($id);

        $data                  = $pr->toArray();
        $data['po_number']     = $pr->purchaseOrder?->po_number;
        $data['supplier_name'] = $pr->supplier?->name ?? $pr->purchaseOrder?->supplier?->name;
        $data['items']         = $pr->returnItems->map(function ($item) {
            $poi  = $item->purchaseOrderItem;
            $desc = $poi?->product?->translations?->first()?->name
                 ?? $poi?->product?->sku
                 ?? $poi?->material?->name
                 ?? $poi?->description
                 ?? '—';
            return [
                'description' => $desc,
                'sku'         => $poi?->product?->sku ?? '—',
                'unit_price'  => $poi?->unit_price ?? 0,
                'quantity'    => $item->quantity ?? 0,
                'reason'      => $item->reason ?? '—',
            ];
        })->toArray();

        $html = PdfService::purchaseReturn($data);
        return $this->makePdf($html, 'PR-' . ($pr->return_number ?? $id));
    }

    // ─── Sales Order ─────────────────────────────────────────────────────────

    public function order(int $id, bool $isInvoice = false): Response
    {
        $order = Order::with([
            'user:id,first_name,last_name,email,phone',
            'items',
            'items.variant:id,sku,variant_name',
            'outlet:id,name',
            'payments',
        ])->findOrFail($id);

        $data                  = $order->toArray();
        $guestName = trim(($order->customer_first_name ?? '') . ' ' . ($order->customer_last_name ?? ''));
        $data['customer_name'] = $order->user
            ? trim($order->user->first_name . ' ' . $order->user->last_name)
            : ($guestName ?: 'Walk-in Customer');
        $data['customer_email'] = $order->user?->email ?? $order->customer_email;
        $data['customer_phone'] = $order->user?->phone ?? $order->customer_phone;
        $data['outlet_name']    = $order->outlet?->name;

        $html = PdfService::order($data, $isInvoice);
        $prefix = $isInvoice ? 'Invoice' : 'Order';
        return $this->makePdf($html, "{$prefix}-{$order->order_number}");
    }

    public function invoice(int $id): Response
    {
        return $this->order($id, true);
    }

    // ─── Shipment ─────────────────────────────────────────────────────────────

    public function shipment(int $id): Response
    {
        $shipment = OrderShipment::with([
            'order:id,order_number,customer_first_name,customer_last_name,customer_email,customer_phone',
            'tracking',
        ])->findOrFail($id);

        $data          = $shipment->toArray();
        $data['order'] = $shipment->order ? array_merge($shipment->order->toArray(), [
            'customer_name' => trim(($shipment->order->customer_first_name ?? '') . ' ' . ($shipment->order->customer_last_name ?? '')) ?: null,
        ]) : null;
        $data['tracking'] = $shipment->tracking?->toArray() ?? [];

        $html = PdfService::shipment($data);
        return $this->makePdf($html, 'Shipment-' . ($shipment->shipment_number ?? $id));
    }

    // ─── Order Return ─────────────────────────────────────────────────────────

    public function orderReturn(int $id): Response
    {
        $ret = OrderReturn::with([
            'order:id,order_number,customer_first_name,customer_last_name,customer_email',
            'order.user:id,first_name,last_name,email',
            'items',
        ])->findOrFail($id);

        $data                  = $ret->toArray();
        $data['order']         = $ret->order?->toArray();
        $retGuestName = trim(($ret->order?->customer_first_name ?? '') . ' ' . ($ret->order?->customer_last_name ?? ''));
        $data['customer_name'] = $ret->order?->user
            ? trim($ret->order->user->first_name . ' ' . $ret->order->user->last_name)
            : ($retGuestName ?: '—');
        $data['customer_email'] = $ret->order?->user?->email ?? $ret->order?->customer_email ?? '';
        $data['customer_email'] = $ret->order?->user?->email ?? '';

        $html = PdfService::orderReturn($data);
        return $this->makePdf($html, 'Return-' . ($ret->return_number ?? $id));
    }

    // ─── Production Order ─────────────────────────────────────────────────────

    public function productionOrder(int $id): Response
    {
        $po = ProductionOrder::with([
            'product:id,sku',
            'product.translations' => fn($q) => $q->where('language_code','en')->select('product_id','name'),
            'variant:id,variant_name,sku',
            'outlet:id,name',
            'tasks.stage:id,name',
            'tasks.assignedTo:id,first_name,last_name',
            'materialAllocations.material:id,name,unit_of_measure',
            'createdBy:id,first_name,last_name',
        ])->findOrFail($id);

        $data                 = $po->toArray();
        $data['product_name'] = $po->product?->translations->first()?->name ?? $po->product?->sku ?? '—';
        $data['sku']          = $po->product?->sku ?? '';
        $data['variant_name'] = $po->variant?->variant_name ?? '';
        $data['outlet']       = $po->outlet?->toArray();
        $data['created_by']   = $po->createdBy?->toArray();
        $data['tasks']        = $po->tasks->map(fn($t) => array_merge($t->toArray(), [
            'stage'       => $t->stage?->toArray(),
            'assigned_to' => $t->assignedTo?->toArray(),
        ]))->toArray();
        $data['material_allocations'] = $po->materialAllocations->map(fn($m) => array_merge($m->toArray(), [
            'material' => $m->material?->toArray(),
        ]))->toArray();

        $html = PdfService::productionOrder($data);
        return $this->makePdf($html, 'Production-' . ($po->order_number ?? $id));
    }

    // ─── Stock Transfer ───────────────────────────────────────────────────────

    public function stockTransfer(int $id): Response
    {
        $transfer = InventoryTransfer::with([
            'fromOutlet:id,name',
            'toOutlet:id,name',
            'requestedBy:id,first_name,last_name',
            'approvedBy:id,first_name,last_name',
            'items.product:id,sku',
            'items.product.translations' => fn ($q) => $q->where('language_code', 'en')->select('product_id', 'name'),
            'items.variant:id,sku,variant_name',
        ])->findOrFail($id);

        $requestedBy = $transfer->requestedBy;

        $data = $transfer->toArray();
        $data['from_outlet'] = $transfer->fromOutlet?->toArray();
        $data['to_outlet']   = $transfer->toOutlet?->toArray();
        $data['created_by']  = $requestedBy ? [
            'first_name' => $requestedBy->first_name,
            'last_name'  => $requestedBy->last_name,
        ] : null;
        $data['items'] = $transfer->items->map(function ($item) {
            $productName = $item->product?->translations?->first()?->name
                        ?? $item->product?->sku
                        ?? '—';
            $sku = $item->variant?->sku ?? $item->product?->sku ?? '—';
            return [
                'product_name' => $productName,
                'sku'          => $sku,
                'quantity'     => $item->quantity_requested ?? 0,
                'notes'        => null,
            ];
        })->toArray();

        $html = PdfService::stockTransfer($data);
        return $this->makePdf($html, 'Transfer-' . ($transfer->transfer_number ?? $id));
    }

    // ─── Stock Adjustment ─────────────────────────────────────────────────────

    public function stockAdjustment(int $id): Response
    {
        $adj = \App\Models\InventoryTransaction::with([
            'inventoryItem.product:id,sku',
            'inventoryItem.product.translations' => fn ($q) => $q->where('language_code', 'en')->select('product_id', 'name'),
            'inventoryItem.variant:id,sku,variant_name',
            'inventoryItem.outlet:id,name',
            'createdBy:id,first_name,last_name',
            'approvedBy:id,first_name,last_name',
        ])->findOrFail($id);

        $item        = $adj->inventoryItem;
        $productName = $item?->product?->translations?->first()?->name
                    ?? $item?->product?->sku
                    ?? '—';
        $sku         = $item?->variant?->sku ?? $item?->product?->sku ?? '—';
        $outlet      = $item?->outlet;

        // Build a normalised data array that PdfService::stockAdjustment() expects
        $data = [
            'id'               => $adj->id,
            'adjustment_number'=> $adj->reference_number ?? "ADJ-{$adj->id}",
            'reference'        => $adj->reference_number,
            'status'           => $adj->status ?? 'approved',
            'reason'           => $adj->reason_code ?? $adj->transaction_type ?? '—',
            'reason_code'      => $adj->reason_code ?? $adj->transaction_type,
            'notes'            => $adj->notes,
            'created_at'       => $adj->created_at,
            'approved_at'      => $adj->approved_at,
            'outlet'           => $outlet ? ['id' => $outlet->id, 'name' => $outlet->name] : null,
            'approved_by'      => $adj->approvedBy ? [
                'first_name' => $adj->approvedBy->first_name,
                'last_name'  => $adj->approvedBy->last_name,
            ] : null,
            // PdfService iterates $adj['items'] — wrap the single inventory item as one row
            'items' => $item ? [[
                'product_name'      => $productName,
                'sku'               => $sku,
                'adjustment_type'   => ucfirst(str_replace('_', ' ', $adj->transaction_type ?? '—')),
                'type'              => ucfirst(str_replace('_', ' ', $adj->transaction_type ?? '—')),
                'quantity_before'   => $adj->quantity_before ?? 0,
                'quantity'          => $adj->quantity_change  ?? 0,
                'quantity_adjusted' => $adj->quantity_change  ?? 0,
            ]] : [],
        ];

        $html = PdfService::stockAdjustment($data);
        return $this->makePdf($html, 'Adjustment-' . ($adj->reference_number ?? $id));
    }

    // ─── Expense ──────────────────────────────────────────────────────────────

    public function expense(int $id): Response
    {
        $exp = Expense::with([
            'category:id,name',
            'submittedBy:id,first_name,last_name,email',
            'lineItems.category:id,name',
            'approvals',
        ])->findOrFail($id);

        $data                = $exp->toArray();
        $data['category']    = $exp->category?->toArray();
        $data['submitted_by'] = $exp->submittedBy?->toArray();
        $data['line_items']  = $exp->lineItems?->toArray() ?? [];

        $html = PdfService::expense($data);
        return $this->makePdf($html, 'Expense-' . ($exp->expense_number ?? $exp->reference_number ?? $id));
    }
}