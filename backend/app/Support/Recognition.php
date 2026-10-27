<?php

namespace App\Support;

use App\Models\Post;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recognition points (People C3). A manager's shout-out may carry points;
 * payroll turns the period's points into one taxable earning line only
 * when a point is worth something (settings people.recognition_point_kes,
 * default 0 = points are just points). The value is read at run time, so
 * a past run keeps the line it was computed with (PayrollLine snapshot).
 */
class Recognition
{
    public const SETTING = 'people.recognition_point_kes';

    public static function pointValue(): float
    {
        $v = DB::table('settings')->where('key', self::SETTING)->value('value');
        return max(0.0, (float) ($v ?? 0));
    }

    public static function setPointValue(float $kes): void
    {
        $row = ['value' => (string) round($kes, 2), 'updated_at' => now()];
        if (DB::table('settings')->where('key', self::SETTING)->exists()) DB::table('settings')->where('key', self::SETTING)->update($row);
        else DB::table('settings')->insert($row + ['key' => self::SETTING, 'created_at' => now()]);
    }

    /** Points recognised to $user with a publish date inside [$from, $to]. */
    public static function pointsFor(User $user, Carbon $from, Carbon $to): int
    {
        return (int) Post::where('kind', 'recognition')->where('recipient_id', $user->id)->whereNotNull('published_at')
            ->whereBetween('published_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->sum('points');
    }
}
