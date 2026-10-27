<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Support\Automations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Setup › Automations (People C2). Read with attendance.view_team; write with settings.edit. */
class AutomationsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['automations' => array_values(Automations::all()), 'people' => $this->people(), 'recognition_point_kes' => \App\Support\Recognition::pointValue()]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        abort_unless(isset(Automations::KEYS[$key]), 404, 'No such automation.');
        $meta = Automations::KEYS[$key];
        $v = $request->validate([
            'enabled'          => 'sometimes|boolean',
            'threshold'        => $meta['unit'] ? ['sometimes', 'integer', "between:{$meta['min']},{$meta['max']}"] : 'prohibited',
            'recipients'       => ['sometimes', Rule::in(Automations::RECIPIENTS)],
            'extra_user_ids'   => 'sometimes|array|max:20',
            'extra_user_ids.*' => 'integer|exists:users,id',
        ]);
        $before = Automations::get($key);
        Automations::save($key, $v, $request->user()->id);
        $after = Automations::get($key);
        $changed = array_keys(array_filter($v, fn ($val, $k) => ($before[$k] ?? null) !== ($after[$k] ?? null), ARRAY_FILTER_USE_BOTH));
        ActivityLogService::log('automation_saved', null, ['key' => $key, 'changed' => $changed, 'before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed))],
            "{$request->user()->first_name} changed the {$meta['label']} automation: " . (implode(', ', $changed) ?: 'nothing'));

        return response()->json(['message' => "{$meta['label']} saved.", 'automation' => $after]);
    }

    /** PUT /attendance/recognition-points {kes} — what a recognition point is worth on the payslip (C3); 0 = just points. */
    public function recognitionPoints(Request $request): JsonResponse
    {
        $v = $request->validate(['kes' => 'required|numeric|between:0,100000']);
        $before = \App\Support\Recognition::pointValue();
        \App\Support\Recognition::setPointValue((float) $v['kes']);
        ActivityLogService::log('recognition_point_value_saved', null, ['before' => $before, 'after' => (float) $v['kes']], "{$request->user()->first_name} set a recognition point to KES " . number_format((float) $v['kes'], 2));

        return response()->json(['message' => (float) $v['kes'] > 0 ? 'A point is now worth KES ' . number_format((float) $v['kes'], 2) . ' on the payslip.' : 'Points are just points; nothing on the payslip.', 'recognition_point_kes' => \App\Support\Recognition::pointValue()]);
    }

    /** Active staff, for the "also tell" picker. */
    private function people(): array
    {
        return User::where('status', 'active')->where('user_type', 'staff')->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => trim("{$u->first_name} {$u->last_name}")])->values()->all();
    }
}
