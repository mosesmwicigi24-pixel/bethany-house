<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Services\ActivityLogService;
use App\Support\DiscountRule;
use Illuminate\Http\Request;

/**
 * Admin CRUD for promotions — the "Blessed Friday" campaigns (CMS "Marketing →
 * Campaigns"). This is where the owner sets each season's discount (10–20%) and
 * its window. Money is server-authoritative: the discount lives here, never on
 * the storefront.
 *
 * Since 2026-10-03 one worth more than 5% is the super_admin's alone to create,
 * raise, extend or switch on (App\Support\DiscountRule). Seasons carry no
 * discount of their own — they link to a promotion — so this is the one gate.
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
        $data = $this->validated($request);
        // Above 5% is the owner's to set — even switched off, because a
        // switched-off draft is one click from running.
        DiscountRule::assertPromotionAllowed($request->user(), $data);

        $promotion = Promotion::create($data + ['created_by' => $request->user()?->id]);
        $this->log('promotion_created', $promotion);

        return response()->json(['data' => $promotion], 201);
    }

    public function update(Request $request, $id)
    {
        $promotion = Promotion::findOrFail($id);
        $data      = $this->validated($request);

        // Judged on the promotion as it will stand — the fields sent, over the
        // ones kept — against how it stands now. Raising, extending, renaming
        // or switching on one worth more than 5% is the owner's; switching it
        // off is anyone's who may manage marketing.
        $before = $promotion->only(['discount_type', 'discount_value', 'conditions', 'is_active']);
        DiscountRule::assertPromotionAllowed($request->user(), array_merge($before, $data), $before);

        $promotion->update($data);
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
