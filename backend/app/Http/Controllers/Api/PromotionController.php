<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;

/**
 * Admin CRUD for promotions — the "Blessed Friday" campaigns (CMS "Marketing →
 * Campaigns"). This is where the owner sets each season's discount (10–20%) and
 * its window. Money is server-authoritative: the discount lives here, never on
 * the storefront.
 */
class PromotionController extends Controller
{
    public function adminIndex(Request $request)
    {
        $q = Promotion::query();

        if ($request->filled('search')) {
            $q->where('name', 'ILIKE', "%{$request->search}%");
        }
        if ($request->filled('is_active')) {
            $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        $promotions = $q->orderByDesc('priority')->orderByDesc('starts_at')->get();

        return response()->json([
            'data'  => $promotions,
            'stats' => [
                'total'   => Promotion::count(),
                'active'  => Promotion::where('is_active', true)->count(),
                'running' => Promotion::active()->count(),
            ],
        ]);
    }

    public function adminShow($id)
    {
        return response()->json(['data' => Promotion::findOrFail($id)]);
    }

    public function store(Request $request)
    {
        $promotion = Promotion::create($this->validated($request));
        $this->log('promotion_created', $promotion);

        return response()->json(['data' => $promotion], 201);
    }

    public function update(Request $request, $id)
    {
        $promotion = Promotion::findOrFail($id);
        $promotion->update($this->validated($request));
        $this->log('promotion_updated', $promotion);

        return response()->json(['data' => $promotion]);
    }

    public function destroy($id)
    {
        $promotion = Promotion::findOrFail($id);
        $promotion->delete();
        $this->log('promotion_deleted', $promotion);

        return response()->json(['message' => 'Promotion deleted.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'           => 'required|string|max:150',
            'description'    => 'nullable|string',
            'type'           => 'nullable|string|max:30',
            'discount_type'  => 'required|in:percentage,fixed',
            'discount_value' => [
                'required', 'numeric', 'min:0',
                function ($attr, $val, $fail) use ($request) {
                    if ($request->input('discount_type') === 'percentage' && $val > 100) {
                        $fail('A percentage discount cannot exceed 100.');
                    }
                },
            ],
            'conditions'     => 'nullable|array',
            'is_active'      => 'sometimes|boolean',
            'starts_at'      => 'nullable|date',
            'ends_at'        => 'nullable|date|after_or_equal:starts_at',
            'priority'       => 'nullable|integer',
            'is_exclusive'   => 'sometimes|boolean',
            'max_uses'       => 'nullable|integer|min:0',
        ]);
    }

    private function log(string $action, Promotion $p): void
    {
        try {
            ActivityLogService::log($action, null, [
                'promotion_id' => $p->id, 'name' => $p->name,
                'discount'     => "{$p->discount_value} {$p->discount_type}",
            ]);
        } catch (\Exception) {
        }
    }
}
