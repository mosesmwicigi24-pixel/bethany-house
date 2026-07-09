<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductSerial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only lookup for per-item serials (Phase 1): list/filter and single-item
 * history. Selling/dispatch transitions come in later phases.
 */
class ProductSerialController extends Controller
{
    /** Default days on the shelf before a unit is considered "aged". */
    private const AGING_DAYS = 90;

    /**
     * GET /admin/product-serials
     * Filters: status, product_id, production_order_id, search, aged (in-stock
     * units older than aging_days), plus summary counts by status.
     */
    public function index(Request $request): JsonResponse
    {
        $agingDays  = (int) $request->get('aging_days', self::AGING_DAYS);
        $agedCutoff = now()->subDays(max(1, $agingDays));

        $query = ProductSerial::query()
            ->with([
                'product:id,sku',
                'product.translations:product_id,name',
                'productionOrder:id,order_number,status',
                'outlet:id,name',
                'order:id,order_number',
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', (int) $request->product_id);
        }
        if ($request->filled('production_order_id')) {
            $query->where('production_order_id', (int) $request->production_order_id);
        }
        if ($request->filled('search')) {
            $query->where('serial_number', 'ILIKE', '%' . $request->search . '%');
        }
        if ($request->boolean('aged')) {
            $query->where('status', ProductSerial::IN_STOCK)->where('stocked_at', '<', $agedCutoff);
        }

        // Status summary (respecting the non-search filters).
        $summary = (clone $query)
            ->reorder()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        // How many units are aging on the shelf (regardless of the aged filter).
        $agedCount = ProductSerial::where('status', ProductSerial::IN_STOCK)
            ->where('stocked_at', '<', $agedCutoff)
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', (int) $request->product_id))
            ->count();

        $serials = $query->orderByDesc('id')->paginate((int) $request->get('per_page', 30));

        $serials->getCollection()->transform(fn (ProductSerial $s) => $this->format($s, $agedCutoff));

        return response()->json([
            'data'    => $serials->items(),
            'meta'    => [
                'current_page' => $serials->currentPage(),
                'last_page'    => $serials->lastPage(),
                'total'        => $serials->total(),
                'per_page'     => $serials->perPage(),
            ],
            'summary'     => $summary,
            'aged_count'  => $agedCount,
            'aging_days'  => $agingDays,
        ]);
    }

    /**
     * POST /admin/product-serials/reconcile
     * Compare a physical count (scanned serial numbers) against system stock.
     */
    public function reconcile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id'    => 'required|integer|exists:products,id',
            'outlet_id'     => 'nullable|integer|exists:outlets,id',
            'serials'       => 'required|array',
            'serials.*'     => 'string|max:120',
            'flag_missing'  => 'sometimes|boolean',
        ]);

        $report = \App\Services\ProductSerialService::reconcile(
            $validated['product_id'],
            $validated['outlet_id'] ?? null,
            $validated['serials'],
            (bool) ($validated['flag_missing'] ?? false),
        );

        return response()->json([
            'message'         => count($report['missing']) > 0
                ? count($report['missing']) . ' unit(s) unaccounted for.'
                : 'All in-stock units are accounted for.',
            'matched_count'   => count($report['matched']),
            'missing'         => $report['missing'],
            'unexpected'      => $report['unexpected'],
            'flagged_missing' => (bool) ($validated['flag_missing'] ?? false),
        ]);
    }

    /**
     * GET /admin/product-serials/{id}
     * A single serial with its lifecycle timeline.
     */
    public function show($id): JsonResponse
    {
        $serial = ProductSerial::with([
            'product:id,sku',
            'product.translations:product_id,name',
            'productionOrder:id,order_number,status,created_at',
            'outlet:id,name',
            'order:id,order_number',
        ])->findOrFail($id);

        $timeline = array_values(array_filter([
            $serial->productionOrder ? [
                'event' => 'Assigned in production',
                'at'    => optional($serial->productionOrder->created_at)->toIso8601String(),
                'ref'   => $serial->productionOrder->order_number,
            ] : null,
            $serial->status === ProductSerial::IN_STOCK || $serial->sold_at || $serial->dispatched_at ? [
                'event' => 'Entered stock',
                'at'    => optional($serial->updated_at)->toIso8601String(),
                'ref'   => $serial->outlet?->name,
            ] : null,
            $serial->sold_at ? [
                'event' => 'Sold',
                'at'    => $serial->sold_at->toIso8601String(),
                'ref'   => $serial->order?->order_number,
            ] : null,
            $serial->dispatched_at ? [
                'event' => 'Dispatched',
                'at'    => $serial->dispatched_at->toIso8601String(),
                'ref'   => null,
            ] : null,
        ]));

        return response()->json([
            'serial'   => $this->format($serial),
            'timeline' => $timeline,
        ]);
    }

    private function format(ProductSerial $s, ?\Carbon\CarbonInterface $agedCutoff = null): array
    {
        $daysInStock = $s->stocked_at ? (int) $s->stocked_at->diffInDays(now()) : null;
        $aged = $s->status === ProductSerial::IN_STOCK
            && $agedCutoff !== null
            && $s->stocked_at !== null
            && $s->stocked_at->lt($agedCutoff);

        return [
            'id'                   => $s->id,
            'serial_number'        => $s->serial_number,
            'status'               => $s->status,
            'stocked_at'           => optional($s->stocked_at)->toIso8601String(),
            'days_in_stock'        => $daysInStock,
            'aged'                 => $aged,
            'product_id'           => $s->product_id,
            'product_name'         => $s->product?->translations?->first()?->name ?? $s->product?->sku,
            'product_sku'          => $s->product?->sku,
            'production_order_id'  => $s->production_order_id,
            'production_order_number' => $s->productionOrder?->order_number,
            'outlet_id'            => $s->outlet_id,
            'outlet_name'          => $s->outlet?->name,
            'order_id'             => $s->order_id,
            'order_number'         => $s->order?->order_number,
            'sold_at'              => optional($s->sold_at)->toIso8601String(),
            'dispatched_at'        => optional($s->dispatched_at)->toIso8601String(),
            'created_at'           => optional($s->created_at)->toIso8601String(),
        ];
    }
}
