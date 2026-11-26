<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Models\ProductionStage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\ServerError;

class ProductStageController extends Controller
{
    public function index()
    {
        // Who does each stage by default rides along as a name, so the list
        // can say "Whoever serves the customer" or the person without a
        // second call. Names only.
        $stages = ProductionStage::ordered()->with('defaultAssignee:id,first_name,last_name,status')->get()
            ->map(function (ProductionStage $stage) {
                $row = $stage->toArray();
                $who = $stage->defaultAssignee;
                unset($row['default_assignee']);
                $row['default_assignee_name'] = $who ? trim("{$who->first_name} {$who->last_name}") : null;
                // Set, but the account can no longer work: the stage will be
                // handed out by hand until someone else is chosen.
                $row['default_assignee_inactive'] = $stage->default_assignee_mode === ProductionStage::ASSIGN_PERSON
                    && (!$who || $who->status !== 'active');

                return $row;
            });

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
            // Who does this stage by default (owner, 2026-11-26).
            'default_assignee_mode' => 'sometimes|in:' . implode(',', ProductionStage::ASSIGN_MODES),
            'default_assignee_id'   => 'nullable|integer|exists:users,id',
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        if (array_key_exists('default_assignee_mode', $validated)) {
            if ($validated['default_assignee_mode'] === ProductionStage::ASSIGN_PERSON) {
                $person = \App\Models\User::find($validated['default_assignee_id'] ?? null);
                if (!$person || !$person->isActive() || !$person->isStaff()) {
                    return response()->json([
                        'message' => 'Choose an active member of staff for this stage.',
                        'errors'  => ['default_assignee_id' => ['Choose an active member of staff for this stage.']],
                    ], 422);
                }
            } else {
                // Only a named person carries an id.
                $validated['default_assignee_id'] = null;
            }
        } else {
            unset($validated['default_assignee_id']);
        }

        $before = ['mode' => $stage->default_assignee_mode, 'user_id' => $stage->default_assignee_id];
        $stage->update($validated);

        try {
            $after = ['mode' => $stage->default_assignee_mode, 'user_id' => $stage->default_assignee_id];
            ActivityLogService::log('production_stage_updated', null, [
                'stage_id' => $stage->id,
                'name'     => $stage->name,
                'changes'  => array_keys($validated),
            ] + ($before !== $after ? ['default_assignee_before' => $before, 'default_assignee_after' => $after] : []));
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
            return ServerError::respond($e, 'Failed to reorder.');
        }
    }
}