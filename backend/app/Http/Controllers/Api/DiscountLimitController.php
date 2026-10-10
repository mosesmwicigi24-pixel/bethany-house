<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Support\DiscountRule;
use App\Support\RoleDiscountCaps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Setup → Discount limits: the most each role may give as a discount at the
 * till, on an order or on a quotation (owner, 2026-10-10: "clerks at 10% and
 * Admins up to 15% … Create a place where super admin can set this too").
 *
 * The super_admin's alone, by role (route middleware owner.only); a save also
 * needs a fresh step-up. A role without a limit is held to the global maximum
 * (config pos.discount_cap_percent). Nothing here applies a discount — a limit
 * only bounds what a person chooses to give. Every change is on the audit
 * trail with old → new per role.
 *
 * @see \App\Support\RoleDiscountCaps
 * @see \Tests\Feature\RoleDiscountLimitsTest
 */
class DiscountLimitController extends Controller
{
    /** Roles that have no limit to set: the owner has none; a customer gives no discounts. */
    private const NOT_LISTED = [DiscountRule::OWNER_ROLE, 'customer'];

    /** GET /api/v1/admin/settings/discount-limits */
    public function index(): JsonResponse
    {
        return response()->json($this->payload());
    }

    /**
     * PUT /api/v1/admin/settings/discount-limits
     * { limits: [{ role, cap_percent: number|null }] } — null puts the role
     * back on the default.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limits'               => ['required', 'array', 'min:1', 'max:100'],
            'limits.*.role'        => ['required', 'string', 'max:125', 'distinct'],
            'limits.*.cap_percent' => ['present', 'nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
        ]);

        $known = $this->roleNames();
        $rows  = RoleDiscountCaps::fresh();
        foreach ($data['limits'] as $i => $limit) {
            $role = (string) $limit['role'];
            if (in_array($role, self::NOT_LISTED, true)) {
                throw ValidationException::withMessages([
                    "limits.{$i}.role" => $role === DiscountRule::OWNER_ROLE
                        ? 'The super admin has no discount limit to set.'
                        : 'Customers do not give discounts.',
                ]);
            }
            // A role that no longer exists may still have its old row cleared.
            if (!in_array($role, $known, true) && !array_key_exists($role, $rows)) {
                throw ValidationException::withMessages(["limits.{$i}.role" => "There is no role called {$role}."]);
            }
        }

        $user    = $request->user();
        $changes = DB::transaction(function () use ($data, $user) {
            $before  = DB::table(RoleDiscountCaps::TABLE)->lockForUpdate()->pluck('cap_percent', 'role')
                ->map(fn ($v) => round((float) $v, 2))->all();
            $changes = [];

            foreach ($data['limits'] as $limit) {
                $role = (string) $limit['role'];
                $old  = $before[$role] ?? null;
                $new  = $limit['cap_percent'] === null ? null : round((float) $limit['cap_percent'], 2);
                if ($old === $new) {
                    continue;
                }

                if ($new === null) {
                    DB::table(RoleDiscountCaps::TABLE)->where('role', $role)->delete();
                } else {
                    DB::table(RoleDiscountCaps::TABLE)->updateOrInsert(
                        ['role' => $role],
                        ['cap_percent' => $new, 'updated_by' => $user?->id, 'updated_at' => now()]
                            + ($old === null ? ['created_at' => now()] : []),
                    );
                }
                $changes[$role] = ['old' => $old, 'new' => $new];
            }

            return $changes;
        });

        // After the commit, so the next discount reads the new limits.
        RoleDiscountCaps::forget();

        if ($changes !== []) {
            $default = DiscountRule::capPercent();
            $words   = collect($changes)->map(fn ($c, $role) => sprintf(
                '%s %s → %s', $role, $this->describe($c['old'], $default), $this->describe($c['new'], $default),
            ))->implode(', ');

            ActivityLogService::log(
                event:       'settings_updated',
                subject:     null,
                properties:  ['group' => 'discount limits', 'default_percent' => $default, 'changes' => $changes],
                description: 'Discount limits changed: ' . $words,
                causer:      $user,
            );
        }

        return response()->json(array_merge(
            ['message' => $changes === [] ? 'Nothing changed.' : 'Discount limits saved.'],
            $this->payload(),
        ));
    }

    /** @return array{default_percent: float, limits: list<array<string, mixed>>} */
    private function payload(): array
    {
        $default = DiscountRule::capPercent();
        $rows    = DB::table(RoleDiscountCaps::TABLE . ' as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.updated_by')
            ->get(['c.role', 'c.cap_percent', 'c.updated_at', 'u.id as by_id', 'u.first_name', 'u.last_name'])
            ->keyBy('role');

        $roles = DB::table('roles')
            ->where('guard_name', 'sanctum')
            ->whereNotIn('name', self::NOT_LISTED)
            ->orderBy('name')
            ->get()
            ->unique('name')
            ->mapWithKeys(fn ($r) => [$r->name => $r->display_name ?? null]);

        // A row whose role has since gone is still shown, so it can be cleared.
        foreach ($rows->keys() as $orphan) {
            if (!$roles->has($orphan) && !in_array($orphan, self::NOT_LISTED, true)) {
                $roles->put($orphan, null);
            }
        }

        $limits = $roles->map(function ($display, $name) use ($rows, $default) {
            $row = $rows->get($name);
            $cap = $row ? round((float) $row->cap_percent, 2) : null;

            return [
                'role'              => $name,
                'display_name'      => $display ?: ucwords(str_replace(['_', '-'], ' ', $name)),
                'cap_percent'       => $cap,
                'effective_percent' => $cap ?? $default,
                'updated_at'        => $row?->updated_at,
                'updated_by'        => $row && $row->by_id
                    ? ['id' => (int) $row->by_id, 'name' => trim($row->first_name . ' ' . $row->last_name)]
                    : null,
            ];
        })->values()->all();

        return ['default_percent' => $default, 'limits' => $limits];
    }

    /** @return list<string> */
    private function roleNames(): array
    {
        return DB::table('roles')->where('guard_name', 'sanctum')->pluck('name')->unique()->values()->all();
    }

    private function describe(?float $percent, float $default): string
    {
        $fmt = fn (float $p) => rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.') . '%';

        return $percent === null ? 'default (' . $fmt($default) . ')' : $fmt($percent);
    }
}
