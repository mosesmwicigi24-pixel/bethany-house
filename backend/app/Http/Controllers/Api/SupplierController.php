<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\PurchaseOrder;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class SupplierController extends Controller
{
    /**
     * Get all suppliers
     */
    public function index(Request $request)
    {
        $query = Supplier::query();

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%")
                    ->orWhere('company_code', 'LIKE', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by country
        if ($request->has('country')) {
            $query->where('country', $request->country);
        }

        // Filter by supplier type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Include statistics
        if ($request->get('with_stats', false)) {
            $query->withCount('purchaseOrders')
                ->withSum('purchaseOrders as total_purchased', 'total');
        }

        $sortBy = $request->get('sort_by', 'name');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 20);
        $suppliers = $query->paginate($perPage);

        return response()->json($suppliers);
    }

    /**
     * Get single supplier
     */
    public function show($id)
    {
        $supplier = Supplier::findOrFail($id);

        $stats = [
            'total_orders' => PurchaseOrder::where('supplier_id', $supplier->id)->count(),
            'pending_orders' => PurchaseOrder::where('supplier_id', $supplier->id)
                ->whereIn('status', ['draft', 'pending_approval', 'approved', 'ordered'])
                ->count(),
            'total_value' => PurchaseOrder::where('supplier_id', $supplier->id)
                ->where('status', '!=', 'cancelled')
                ->sum('total_amount'),                      // ← was 'total'
            'last_order_date' => PurchaseOrder::where('supplier_id', $supplier->id)
                ->latest()
                ->value('created_at'),                     // ← was ->first()?->created_at
        ];

        $recentOrders = PurchaseOrder::where('supplier_id', $supplier->id)
            ->with('items')
            ->latest()
            ->limit(10)
            ->get();

        return response()->json([
            'supplier'      => $supplier,
            'stats'         => $stats,
            'recent_orders' => $recentOrders,
        ]);
    }

    /**
     * Create supplier
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_code' => 'nullable|string|unique:suppliers,company_code|max:50',
            'name' => 'required|string|max:255',
            'type' => 'required|in:manufacturer,wholesaler,distributor,other',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|unique:suppliers,email|max:255',
            'phone' => 'nullable|string|max:20',
            'alternate_phone' => 'nullable|string|max:20',
            'website' => 'nullable|url|max:255',
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'tax_number' => 'nullable|string|max:50',
            'registration_number' => 'nullable|string|max:50',
            'payment_terms' => 'nullable|string',
            'credit_limit' => 'nullable|numeric|min:0',
            'currency' => 'nullable|in:KES,USD',
            'bank_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:100',
            'bank_account_name' => 'nullable|string|max:255',
            'bank_swift_code' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'rating' => 'nullable|numeric|min:0|max:5',
            'status' => 'sometimes|in:active,inactive,suspended',
        ]);

        // Generate company code if not provided
        if (!isset($validated['company_code'])) {
            $validated['company_code'] = 'SUP-' . str_pad(Supplier::count() + 1, 6, '0', STR_PAD_LEFT);

            while (Supplier::where('company_code', $validated['company_code'])->exists()) {
                $validated['company_code'] = 'SUP-' . strtoupper(Str::random(6));
            }
        }

        $supplier = Supplier::create($validated);

        try {
            ActivityLogService::log('supplier_created', null, [
                'supplier_id'  => $supplier->id,
                'company_code' => $supplier->company_code,
                'name'         => $supplier->name,
                'type'         => $supplier->type,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Supplier created successfully',
            'supplier' => $supplier,
        ], 201);
    }

    /**
     * Update supplier
     */
    public function update(Request $request, $id)
    {
        $supplier = Supplier::findOrFail($id);

        $validated = $request->validate([
            'company_code' => ['sometimes', 'string', 'max:50', Rule::unique('suppliers')->ignore($supplier->id)],
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:manufacturer,wholesaler,distributor,other',
            'contact_person' => 'nullable|string|max:255',
            'email' => ['nullable', 'email', 'max:255', Rule::unique('suppliers')->ignore($supplier->id)],
            'phone' => 'nullable|string|max:20',
            'alternate_phone' => 'nullable|string|max:20',
            'website' => 'nullable|url|max:255',
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'tax_number' => 'nullable|string|max:50',
            'registration_number' => 'nullable|string|max:50',
            'payment_terms' => 'nullable|string',
            'credit_limit' => 'nullable|numeric|min:0',
            'currency' => 'nullable|in:KES,USD',
            'bank_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:100',
            'bank_account_name' => 'nullable|string|max:255',
            'bank_swift_code' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'rating' => 'nullable|numeric|min:0|max:5',
            'status' => 'sometimes|in:active,inactive,suspended',
        ]);

        $supplier->update($validated);

        try {
            ActivityLogService::log('supplier_updated', null, [
                'supplier_id'  => $supplier->id,
                'company_code' => $supplier->company_code,
                'changes'      => array_keys($validated),
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Supplier updated successfully',
            'supplier' => $supplier,
        ]);
    }

    /**
     * Delete supplier
     */
    public function destroy($id)
    {
        $supplier = Supplier::withCount('purchaseOrders')->findOrFail($id);

        // Check if supplier has purchase orders
        if ($supplier->purchase_orders_count > 0) {
            return response()->json([
                'message' => 'Cannot delete supplier with existing purchase orders. Consider deactivating instead.',
            ], 422);
        }

        $supplier->delete();

        try {
            ActivityLogService::log('supplier_deleted', null, [
                'supplier_id'  => $id,
                'company_code' => $supplier->company_code,
                'name'         => $supplier->name,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Supplier deleted successfully',
        ]);
    }

    /**
     * Get supplier purchase orders
     */
    public function purchaseOrders(Request $request, $id)
    {
        $supplier = Supplier::findOrFail($id);

        $query = PurchaseOrder::where('supplier_id', $supplier->id)
            ->with('items');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Date range
        if ($request->has('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $orders = $query->latest()->paginate(20);

        return response()->json($orders);
    }

    /**
     * Get supplier performance metrics
     */
    public function performance($id)
    {
        $supplier = Supplier::findOrFail($id);

        // ── Delivery performance ──────────────────────────────────────────────────
        $deliveryData = DB::table('purchase_orders')
            ->join(
                DB::raw('(
                SELECT purchase_order_id, MIN(received_date) AS first_received_date
                FROM goods_received_notes
                GROUP BY purchase_order_id
            ) grn'),
                'purchase_orders.id',
                '=',
                'grn.purchase_order_id'
            )
            ->where('purchase_orders.supplier_id', $supplier->id)
            ->whereNotNull('purchase_orders.expected_delivery_date')
            ->selectRaw("
            (grn.first_received_date::date - purchase_orders.expected_delivery_date::date)::integer AS days_difference
        ")
            ->get();

        $totalDeliveries  = $deliveryData->count();
        $avgVariance      = $totalDeliveries > 0 ? round($deliveryData->avg('days_difference'), 2) : 0;
        $onTimeDeliveries = $deliveryData->where('days_difference', '<=', 0)->count();
        $lateDeliveries   = $deliveryData->where('days_difference', '>', 0)->count();

        // ── Quality metrics ───────────────────────────────────────────────────────
        $qualityData = DB::table('grn_items')
            ->join('goods_received_notes', 'grn_items.grn_id', '=', 'goods_received_notes.id')
            ->join('purchase_orders', 'goods_received_notes.purchase_order_id', '=', 'purchase_orders.id')
            ->where('purchase_orders.supplier_id', $supplier->id)
            ->selectRaw('
            COALESCE(SUM(grn_items.quantity_received), 0) AS total_received,
            COALESCE(SUM(grn_items.quantity_rejected), 0) AS total_rejected
        ')
            ->first();

        $totalReceived = (float) ($qualityData->total_received ?? 0);
        $totalRejected = (float) ($qualityData->total_rejected ?? 0);
        $qualityRate   = $totalReceived > 0
            ? round((($totalReceived - $totalRejected) / $totalReceived) * 100, 2)
            : 0;

        return response()->json([
            'avg_delivery_variance' => $avgVariance,
            'on_time_deliveries'    => $onTimeDeliveries,
            'late_deliveries'       => $lateDeliveries,
            'on_time_rate'          => $totalDeliveries > 0
                ? round(($onTimeDeliveries / $totalDeliveries) * 100, 2)
                : 0,
            'quality_rate'          => $qualityRate,
            'total_received'        => $totalReceived,
            'total_rejected'        => $totalRejected,
        ]);
    }

    /**
     * Update supplier rating
     */
    public function updateRating(Request $request, $id)
    {
        $validated = $request->validate([
            'rating' => 'required|numeric|min:0|max:5',
            'review' => 'nullable|string',
        ]);

        $supplier = Supplier::findOrFail($id);

        $supplier->update([
            'rating' => $validated['rating'],
        ]);

        // Optionally store review
        if (isset($validated['review'])) {
            DB::table('supplier_reviews')->insert([
                'supplier_id' => $supplier->id,
                'rating' => $validated['rating'],
                'review' => $validated['review'],
                'reviewed_by' => $request->user()->id,
                'created_at' => now(),
            ]);
        }

        try {
            ActivityLogService::log('supplier_rating_updated', null, [
                'supplier_id'  => $supplier->id,
                'company_code' => $supplier->company_code,
                'new_rating'   => $validated['rating'],
                'has_review'   => isset($validated['review']),
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Supplier rating updated successfully',
            'supplier' => $supplier,
        ]);
    }

    /**
     * Get supplier contact history
     */
    public function contactHistory($id)
    {
        $supplier = Supplier::findOrFail($id);

        $history = DB::table('supplier_contacts')
            ->where('supplier_id', $supplier->id)
            ->orderBy('contact_date', 'desc')
            ->get();

        return response()->json($history);
    }

    /**
     * Add contact record
     */
    public function addContact(Request $request, $id)
    {
        $validated = $request->validate([
            'contact_type' => 'required|in:email,phone,meeting,other',
            'subject' => 'required|string|max:255',
            'notes' => 'required|string',
            'contact_date' => 'required|date',
        ]);

        $supplier = Supplier::findOrFail($id);

        DB::table('supplier_contacts')->insert([
            'supplier_id' => $supplier->id,
            'contact_type' => $validated['contact_type'],
            'subject' => $validated['subject'],
            'notes' => $validated['notes'],
            'contact_date' => $validated['contact_date'],
            'contacted_by' => $request->user()->id,
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Contact record added successfully',
        ]);
    }

    /**
     * Get supplier documents
     */
    public function documents($id)
    {
        $supplier = Supplier::findOrFail($id);

        $documents = DB::table('supplier_documents')
            ->where('supplier_id', $supplier->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($documents);
    }

    /**
     * Upload supplier document
     */
    public function uploadDocument(Request $request, $id)
    {
        $validated = $request->validate([
            'document' => 'required|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240',
            'document_type' => 'required|in:contract,certificate,tax_document,other',
            'description' => 'nullable|string',
        ]);

        $supplier = Supplier::findOrFail($id);

        $path = $request->file('document')->store('suppliers/' . $supplier->id, 'private');

        DB::table('supplier_documents')->insert([
            'supplier_id' => $supplier->id,
            'document_type' => $validated['document_type'],
            'file_path' => $path,
            'file_name' => $request->file('document')->getClientOriginalName(),
            'description' => $validated['description'] ?? null,
            'uploaded_by' => $request->user()->id,
            'created_at' => now(),
        ]);

        try {
            ActivityLogService::log('supplier_document_uploaded', null, [
                'supplier_id'   => $supplier->id,
                'company_code'  => $supplier->company_code,
                'document_type' => $validated['document_type'],
                'file_name'     => $request->file('document')->getClientOriginalName(),
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Document uploaded successfully',
            'path' => $path,
        ]);
    }

    /**
     * Export suppliers
     */
    public function export(Request $request)
    {
        $query = Supplier::query();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $suppliers = $query->get();

        // TODO: Generate Excel/CSV export
        // For now, return JSON data

        return response()->json([
            'message' => 'Export ready',
            'data' => $suppliers,
        ]);
    }
}