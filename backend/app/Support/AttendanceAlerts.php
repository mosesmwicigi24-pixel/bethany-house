<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Who hears about a person's attendance: their manager (users.reports_to) and whoever manages attendance in their outlets. */
class AttendanceAlerts
{
    public static function managersFor(User $person): Collection
    {
        $ids = collect();
        if ($person->reports_to) $ids->push((int) $person->reports_to);
        $outletIds = DB::table('outlet_user')->where('user_id', $person->id)->pluck('outlet_id');
        $colleagues = User::whereIn('id', DB::table('outlet_user')->whereIn('outlet_id', $outletIds)->pluck('user_id'))
            ->where('status', 'active')->get()->filter(fn (User $u) => $u->id !== $person->id && $u->can('attendance.manage'))->pluck('id');
        return User::whereIn('id', $ids->merge($colleagues)->unique()->reject(fn ($id) => $id === $person->id))->where('status', 'active')->get();
    }
}
