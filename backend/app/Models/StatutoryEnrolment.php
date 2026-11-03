<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Whether government deductions (PAYE, NSSF, SHIF, Housing Levy) apply to a
 * person from a date. Dated and append-only; no row means they do not apply.
 */
class StatutoryEnrolment extends Model
{
    protected $fillable = ['user_id', 'effective_from', 'applies', 'reason', 'created_by'];

    protected $casts = ['effective_from' => 'date', 'applies' => 'boolean'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    /** The row in force on a date, or null (= deductions do not apply). */
    public static function inForce(int $userId, Carbon $on): ?self
    {
        return self::where('user_id', $userId)->where('effective_from', '<=', $on->toDateString())
            ->orderByDesc('effective_from')->first();
    }

    public static function applies(int $userId, Carbon $on): bool
    {
        return (bool) self::inForce($userId, $on)?->applies;
    }
}
