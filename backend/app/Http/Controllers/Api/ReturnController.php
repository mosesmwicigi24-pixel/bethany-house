<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReturnController extends Controller
{
    /**
     * Request return (Customer)
     */
    public function request(Request $request, $orderId)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.order_item_id' => 'required|exists:order_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.reason' => 'required|string|max:500',
            'return_method' => 'required|in:pickup,ship_back',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Verify order belongs to customer
        $order = DB::table('orders')
            ->where('id', $orderId)
            ->where('customer_id', $request->user()->customer->id ?? null)
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        // Check if order can be returned
        if (!in_array($order->status, ['delivered', 'completed'])) {
            return response()->json([
                'message' => 'Only delivered orders can be returned'
            ], 422);
        }

        // Check return window (e.g., 30 days)
        $returnWindowDays = 30;
        $deliveredDate = $order->completed_at ?? $order->updated_at;
        if (now()->diffInDays($deliveredDate) > $returnWindowDays) {
            return response()->json([
                'message' => "Return period of {$returnWindowDays} days has expired"
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Generate return number
            $returnNumber = 'RET-' . date('Ymd') . '-' . strtoupper(\Str::random(6));

            // Create return
            $returnId = DB::table('order_returns')->insertGetId([
                'return_number' => $returnNumber,
                'order_id' => $orderId,
                'customer_id' => $request->user()->customer->id,
                'status' => 'pending',
                'return_method' => $validated['return_method'],
                'customer_notes' => $validated['notes'] ?? null,
                'requested_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Add return items
            $totalAmount = 0;
            foreach ($validated['items'] as $item) {
                $orderItem = DB::table('order_items')->find($item['order_item_id']);

                if (!$orderItem || $orderItem->order_id != $orderId) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Invalid order item'
                    ], 422);
                }

                if ($item['quantity'] > $orderItem->quantity) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Return quantity exceeds ordered quantity'
                    ], 422);
                }

                $itemTotal = ($orderItem->price * $item['quantity']);
                $totalAmount += $itemTotal;

                DB::table('return_items')->insert([
                    'return_id' => $returnId,
                    'order_item_id' => $item['order_item_id'],
                    'variant_id' => $orderItem->variant_id,
                    'quantity' => $item['quantity'],
                    'price' => $orderItem->price,
                    'subtotal' => $itemTotal,
                    'reason' => $item['reason'],
                    'created_at' => now(),
                ]);
            }

            // Update return total
            DB::table('order_returns')
                ->where('id', $returnId)
                ->update(['total_amount' => $totalAmount]);

            DB::commit();

            $return = DB::table('order_returns')->find($returnId);

            try {
                ActivityLogService::log('return_requested', null, [
                    'return_id'     => $returnId,
                    'return_number' => $returnNumber,
                    'order_id'      => $orderId,
                    'customer_id'   => $request->user()->customer->id ?? null,
                    'item_count'    => count($validated['items']),
                    'total_amount'  => $totalAmount,
                    'return_method' => $validated['return_method'],
                ]);
            } catch (\Exception) {}

            try {
                NotificationService::returnRequested(
                    $returnId,
                    $returnNumber,
                    (int) $orderId,
                    $order->order_number,
                    $request->user()->id
                );
            } catch (\Exception) {}

            return response()->json([
                'message' => 'Return request submitted successfully',
                'return' => $return,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to submit return request',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get customer returns
     */
    public function customerReturns(Request $request)
    {
        $returns = DB::table('order_returns')
            ->join('orders', 'order_returns.order_id', '=', 'orders.id')
            ->where('order_returns.customer_id', $request->user()->customer->id ?? null)
            ->select(
                'order_returns.*',
                'orders.order_number'
            )
            ->orderBy('order_returns.created_at', 'desc')
            ->paginate(20);

        return response()->json($returns);
    }

    /**
     * Get customer return details
     */
    public function customerReturnDetails($id, Request $request)
    {
        $return = DB::table('order_returns')
            ->join('orders', 'order_returns.order_id', '=', 'orders.id')
            ->where('order_returns.id', $id)
            ->where('order_returns.customer_id', $request->user()->customer->id ?? null)
            ->select('order_returns.*', 'orders.order_number')
            ->first();

        if (!$return) {
            return response()->json(['message' => 'Return not found'], 404);
        }

        // Get return items
        $items = DB::table('return_items')
            ->join('order_items', 'return_items.order_item_id', '=', 'order_items.id')
            ->join('product_variants', 'return_items.variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->where('return_items.return_id', $id)
            ->select(
                'return_items.*',
                'products.name_en as product_name',
                'product_variants.sku'
            )
            ->get();

        return response()->json([
            'return' => $return,
            'items' => $items,
        ]);
    }

    /**
     * Cancel return request (Customer)
     */
    public function cancelRequest($id, Request $request)
    {
        $return = DB::table('order_returns')
            ->where('id', $id)
            ->where('customer_id', $request->user()->customer->id ?? null)
            ->first();

        if (!$return) {
            return response()->json(['message' => 'Return not found'], 404);
        }

        if ($return->status !== 'pending') {
            return response()->json([
                'message' => 'Can only cancel pending returns'
            ], 422);
        }

        DB::table('order_returns')
            ->where('id', $id)
            ->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'updated_at' => now(),
            ]);

        try {
            ActivityLogService::log('return_cancelled', null, [
                'return_id'     => $id,
                'return_number' => $return->return_number ?? null,
                'order_id'      => $return->order_id,
                'customer_id'   => $return->customer_id,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Return request cancelled',
        ]);
    }

    /**
     * Get all returns (Admin)
     */
    public function index(Request $request)
    {
        $query = DB::table('order_returns')
            ->join('orders', 'order_returns.order_id', '=', 'orders.id')
            ->join('customers', 'order_returns.customer_id', '=', 'customers.id')
            ->join('users', 'customers.user_id', '=', 'users.id')
            ->select(
                'order_returns.*',
                'orders.order_number',
                'users.name as customer_name',
                'users.email as customer_email'
            );

        // Filter by status
        if ($request->has('status')) {
            $query->where('order_returns.status', $request->status);
        }

        // Filter by date
        if ($request->has('start_date')) {
            $query->whereDate('order_returns.requested_at', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->whereDate('order_returns.requested_at', '<=', $request->end_date);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_returns.return_number', 'LIKE', "%{$search}%")
                  ->orWhere('orders.order_number', 'LIKE', "%{$search}%")
                  ->orWhere('users.name', 'LIKE', "%{$search}%");
            });
        }

        $sortBy = $request->get('sort_by', 'requested_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy("order_returns.{$sortBy}", $sortOrder);

        $perPage = $request->get('per_page', 20);
        $returns = $query->paginate($perPage);

        return response()->json($returns);
    }

    /**
     * Get return details (Admin)
     */
    public function show($id)
    {
        $return = DB::table('order_returns')
            ->join('orders', 'order_returns.order_id', '=', 'orders.id')
            ->join('customers', 'order_returns.customer_id', '=', 'customers.id')
            ->join('users', 'customers.user_id', '=', 'users.id')
            ->where('order_returns.id', $id)
            ->select(
                'order_returns.*',
                'orders.order_number',
                'orders.shipping_address',
                'users.name as customer_name',
                'users.email as customer_email',
                'users.phone as customer_phone'
            )
            ->first();

        if (!$return) {
            return response()->json(['message' => 'Return not found'], 404);
        }

        // Get items
        $items = DB::table('return_items')
            ->join('order_items', 'return_items.order_item_id', '=', 'order_items.id')
            ->join('product_variants', 'return_items.variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->where('return_items.return_id', $id)
            ->select(
                'return_items.*',
                'products.name_en as product_name',
                'product_variants.sku',
                'order_items.quantity as ordered_quantity'
            )
            ->get();

        return response()->json([
            'return' => $return,
            'items' => $items,
        ]);
    }

    /**
     * Update return status (Admin)
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,approved,rejected,received,inspected,completed',
            'notes' => 'nullable|string|max:1000',
        ]);

        $return = DB::table('order_returns')->find($id);

        if (!$return) {
            return response()->json(['message' => 'Return not found'], 404);
        }

        $updateData = [
            'status' => $validated['status'],
            'updated_at' => now(),
        ];

        if (isset($validated['notes'])) {
            $updateData['admin_notes'] = $validated['notes'];
        }

        // Set timestamps for status changes
        if ($validated['status'] === 'approved') {
            $updateData['approved_at'] = now();
            $updateData['approved_by'] = $request->user()->id;
        } elseif ($validated['status'] === 'rejected') {
            $updateData['rejected_at'] = now();
            $updateData['rejected_by'] = $request->user()->id;
        } elseif ($validated['status'] === 'received') {
            $updateData['received_at'] = now();
        } elseif ($validated['status'] === 'completed') {
            $updateData['completed_at'] = now();
        }

        DB::table('order_returns')
            ->where('id', $id)
            ->update($updateData);

        return response()->json([
            'message' => 'Return status updated successfully',
        ]);
    }

    /**
     * Approve return (Admin)
     */
    public function approve(Request $request, $id)
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $return = DB::table('order_returns')->find($id);

        if (!$return) {
            return response()->json(['message' => 'Return not found'], 404);
        }

        if ($return->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending returns can be approved'
            ], 422);
        }

        DB::table('order_returns')
            ->where('id', $id)
            ->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $request->user()->id,
                'admin_notes' => $validated['notes'] ?? null,
                'updated_at' => now(),
            ]);

        // TODO: Send approval email to customer

        // Notify order owner and log
        try {
            $order = DB::table('orders')->find($return->order_id);
            ActivityLogService::log('return_approved', null, [
                'return_id' => $id,
                'order_id'  => $return->order_id,
                'notes'     => $validated['notes'] ?? null,
            ]);
            NotificationService::returnApproved(
                $id,
                $return->return_number,
                (int) $return->order_id,
                $order?->order_number ?? '',
                $order?->user_id ?? null
            );
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Return approved successfully',
        ]);
    }

    /**
     * Reject return (Admin)
     */
    public function reject(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $return = DB::table('order_returns')->find($id);

        if (!$return) {
            return response()->json(['message' => 'Return not found'], 404);
        }

        if ($return->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending returns can be rejected'
            ], 422);
        }

        DB::table('order_returns')
            ->where('id', $id)
            ->update([
                'status' => 'rejected',
                'rejected_at' => now(),
                'rejected_by' => $request->user()->id,
                'rejection_reason' => $validated['reason'],
                'updated_at' => now(),
            ]);

        // TODO: Send rejection email to customer

        // Notify order owner and log
        try {
            $order = DB::table('orders')->find($return->order_id);
            ActivityLogService::log('return_rejected', null, [
                'return_id' => $id,
                'order_id'  => $return->order_id,
                'reason'    => $validated['reason'],
            ]);
            NotificationService::returnRejected(
                $id,
                $return->return_number,
                (int) $return->order_id,
                $order?->order_number ?? '',
                $order?->user_id ?? null,
                $validated['reason']
            );
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Return rejected',
        ]);
    }

    /**
     * Process refund (Admin)
     */
    public function processRefund(Request $request, $id)
    {
        $validated = $request->validate([
            'refund_amount' => 'required|numeric|min:0',
            'refund_method' => 'required|in:original_payment,store_credit,bank_transfer',
            'notes' => 'nullable|string',
        ]);

        $return = DB::table('order_returns')->find($id);

        if (!$return) {
            return response()->json(['message' => 'Return not found'], 404);
        }

        if (!in_array($return->status, ['received', 'inspected'])) {
            return response()->json([
                'message' => 'Return must be received/inspected before refund'
            ], 422);
        }

        if ($validated['refund_amount'] > $return->total_amount) {
            return response()->json([
                'message' => 'Refund amount exceeds return total'
            ], 422);
        }

        // Never refund more than was actually collected on the order. Without this
        // a refund on an unpaid/part-paid order pays out money that never came in.
        // (Store credit isn't a cash-out against payments, so it's exempt.)
        $order = \App\Models\Order::find($return->order_id);
        if ($validated['refund_method'] !== 'store_credit') {
            $collected = $order ? $order->totalPaid() : 0.0;
            if ((float) $validated['refund_amount'] > $collected + 0.01) {
                return response()->json([
                    'message'    => 'Refund exceeds the amount collected on this order.',
                    'refundable' => round(max(0, $collected), 2),
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            // Update return
            DB::table('order_returns')
                ->where('id', $id)
                ->update([
                    'status' => 'completed',
                    'refund_amount' => $validated['refund_amount'],
                    'refund_method' => $validated['refund_method'],
                    'refund_notes' => $validated['notes'] ?? null,
                    'refunded_at' => now(),
                    'refunded_by' => $request->user()->id,
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);

            // Restore inventory
            $items = DB::table('return_items')
                ->where('return_id', $id)
                ->get();

            foreach ($items as $item) {
                // Increment inventory (return to warehouse)
                DB::table('inventories')
                    ->where('variant_id', $item->variant_id)
                    ->where('location_type', 'warehouse')
                    ->increment('quantity', $item->quantity);

                // Log inventory transaction
                $inventory = DB::table('inventories')
                    ->where('variant_id', $item->variant_id)
                    ->where('location_type', 'warehouse')
                    ->first();

                if ($inventory) {
                    DB::table('inventory_transactions')->insert([
                        'inventory_id' => $inventory->id,
                        'type' => 'return',
                        'quantity' => $item->quantity,
                        'reference_type' => 'order_return',
                        'reference_id' => $return->id,
                        'notes' => "Return #{$return->return_number}",
                        'performed_by' => $request->user()->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // For an original-payment refund, allocate the payout onto the settled
            // payments (latest first, accumulating refund_amount) so the order's
            // net collected (Order::totalPaid) actually drops, then reconcile its
            // payment_status. Mirrors OrderController::refund. store_credit /
            // bank_transfer don't reverse the card/mobile settlement here.
            if ($order && $validated['refund_method'] === 'original_payment' && (float) $validated['refund_amount'] > 0) {
                $remaining = (float) $validated['refund_amount'];
                foreach (
                    \App\Models\Payment::where('order_id', $order->id)->where('status', 'paid')->orderByDesc('id')->get()
                    as $payment
                ) {
                    if ($remaining <= 0.01) {
                        break;
                    }
                    $lineRefundable = (float) $payment->amount - (float) $payment->refund_amount;
                    if ($lineRefundable <= 0) {
                        continue;
                    }
                    $take = min($lineRefundable, $remaining);
                    $payment->update([
                        'refund_amount' => (float) $payment->refund_amount + $take,
                        'refunded_at'   => now(),
                    ]);
                    $remaining -= $take;
                }
                $order->syncPaymentStatus();
            }

            // TODO: Process actual refund via payment gateway

            DB::commit();

            try {
                ActivityLogService::log('return_refund_processed', null, [
                    'return_id'     => $id,
                    'return_number' => $return->return_number ?? null,
                    'order_id'      => $return->order_id,
                    'refund_amount' => $validated['refund_amount'],
                    'refund_method' => $validated['refund_method'],
                ]);
            } catch (\Exception) {}

            try {
                $refundOrder = DB::table('orders')->find($return->order_id);
                NotificationService::refundProcessed(
                    $id,
                    $return->return_number ?? "RET-{$id}",
                    (int) $return->order_id,
                    $refundOrder?->order_number ?? '',
                    (float) $validated['refund_amount'],
                    $validated['refund_method'],
                    $refundOrder?->currency_code ?? 'KES',
                    $refundOrder?->user_id ?? null
                );
            } catch (\Exception) {}

            return response()->json([
                'message' => 'Refund processed successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to process refund',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}