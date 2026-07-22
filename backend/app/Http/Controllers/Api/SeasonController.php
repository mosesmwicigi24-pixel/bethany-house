<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Season;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;

/**
 * Admin CRUD for liturgical seasons (the CMS "Marketing → Seasons" screen).
 * The public seasonal skin is served separately by SiteController@theme.
 */
class SeasonController extends Controller
{
    public function adminIndex(Request $request)
    {
        $q = Season::with(['promotion', 'banner']);

        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn ($x) => $x->where('name', 'ILIKE', "%{$s}%")->orWhere('key', 'ILIKE', "%{$s}%"));
        }
        if ($request->filled('is_active')) {
            $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        $seasons = $q->orderBy('sort_order')->orderBy('starts_at')->get();

        return response()->json([
            'data'  => $seasons,
            'stats' => [
                'total'   => Season::count(),
                'active'  => Season::where('is_active', true)->count(),
                'running' => Season::active()->whereNotNull('starts_at')->count(),
            ],
        ]);
    }

    public function adminShow($id)
    {
        return response()->json(['data' => Season::with(['promotion', 'banner'])->findOrFail($id)]);
    }

    public function store(Request $request)
    {
        $season = Season::create($this->validated($request));
        $this->log('season_created', $season);

        return response()->json(['data' => $season->load(['promotion', 'banner'])], 201);
    }

    public function update(Request $request, $id)
    {
        $season = Season::findOrFail($id);
        $season->update($this->validated($request, $id));
        $this->log('season_updated', $season);

        return response()->json(['data' => $season->load(['promotion', 'banner'])]);
    }

    public function destroy($id)
    {
        $season = Season::findOrFail($id);
        $season->delete();
        $this->log('season_deleted', $season);

        return response()->json(['message' => 'Season deleted.']);
    }

    private function validated(Request $request, $id = null): array
    {
        return $request->validate([
            'key'          => 'required|string|max:60|unique:seasons,key' . ($id ? ",{$id}" : ''),
            'name'         => 'required|string|max:120',
            'tagline'      => 'nullable|string|max:200',
            'scripture'    => 'nullable|string|max:300',
            'theme'        => 'nullable|array',
            'theme.accent' => 'nullable|string|max:20',
            'theme.motif'  => 'nullable|string|max:40',
            'starts_at'    => 'nullable|date',
            'ends_at'      => 'nullable|date|after_or_equal:starts_at',
            'is_active'    => 'sometimes|boolean',
            'priority'     => 'nullable|integer',
            'promotion_id' => 'nullable|integer|exists:promotions,id',
            'banner_id'    => 'nullable|integer|exists:banners,id',
            'sort_order'   => 'nullable|integer|min:0',
        ]);
    }

    private function log(string $action, Season $s): void
    {
        try {
            ActivityLogService::log($action, null, ['season_id' => $s->id, 'key' => $s->key, 'name' => $s->name]);
        } catch (\Exception) {
        }
    }
}
