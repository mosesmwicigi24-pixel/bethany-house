<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Models\ProductionStage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductStageController extends Controller
{
    public function index()
    {
        $stages = ProductionStage::ordered()->get();
        return response()->json($stages);
    }

    // GET /admin/product-stages/{id} is routed to this method but nothing
    // previously called it (the frontend only fetches the full ordered list
    // via index() and edits/deletes by id) - added for route completeness so
    // it doesn't 500 if ever hit directly.
    public function show($id)
    {
        $stage = ProductionStage::findOrFail($id);
        return response()->json($stage);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string',
            'color'       => 'nullable|string|max:20',
        ]);

        $maxOrder = ProductionStage::max('sort_order') ?? 0;

        $stage = ProductionStage::create([
            'name'        => $validated['name'],
            'slug'        => Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
            'sort_order'  => $maxOrder + 1,
            'is_active'   => true,
        ]);

        try {
            ActivityLogService::log('production_stage_created', null, [
                'stage_id'   => $stage->id,
                'name'       => $stage->name,
                'sort_order' => $stage->sort_order,
            ]);
        } catch (\Exception) {}

        return response()->json(['message' => 'Stage created', 'id' => $stage->id], 201);
    }

    public function update(Request $request, $id)
    {
        $stage = ProductionStage::findOrFail($id);

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'color'       => 'nullable|string|max:20',
            'is_active'   => 'sometimes|boolean',
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $stage->update($validated);

        try {
            ActivityLogService::log('production_stage_updated', null, [
                'stage_id' => $stage->id,
                'name'     => $stage->name,
                'changes'  => array_keys($validated),
            ]);
        } catch (\Exception) {}

        return response()->json(['message' => 'Stage updated']);
    }

    public function destroy($id)
    {
        $stage = ProductionStage::findOrFail($id);

        $inUse = DB::table('production_tasks')
            ->where('production_stage_id', $id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->exists();

        if ($inUse) {
            return response()->json(['message' => 'Stage is currently in use by active production tasks.'], 422);
        }

        $stageName = $stage->name;
        $stage->delete();

        try {
            ActivityLogService::log('production_stage_deleted', null, [
                'stage_id' => $id,
                'name'     => $stageName,
            ]);
        } catch (\Exception) {}

        return response()->json(['message' => 'Stage deleted']);
    }

    public function reorder(Request $request)
    {
        $validated = $request->validate(['stages' => 'required|array']);

        DB::beginTransaction();
        try {
            foreach ($validated['stages'] as $index => $stageId) {
                ProductionStage::where('id', $stageId)->update(['sort_order' => $index + 1]);
            }
            DB::commit();

            try {
                ActivityLogService::log('production_stages_reordered', null, [
                    'count' => count($validated['stages']),
                ]);
            } catch (\Exception) {}

            return response()->json(['message' => 'Stages reordered']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to reorder.', 'error' => $e->getMessage()], 500);
        }
    }
}