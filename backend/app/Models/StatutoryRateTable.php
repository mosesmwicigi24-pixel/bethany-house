<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class StatutoryRateTable extends Model
{
    public const CODES = ['paye', 'nssf', 'shif', 'housing_levy'];

    protected $fillable = ['code', 'effective_from', 'config', 'reduces_taxable_pay', 'source', 'is_confirmed', 'confirmed_by', 'confirmed_at', 'created_by'];

    protected $casts = ['effective_from' => 'date:Y-m-d', 'config' => 'array', 'reduces_taxable_pay' => 'boolean', 'is_confirmed' => 'boolean', 'confirmed_at' => 'datetime'];

    /** The row in force on a date, per code. */
    public static function inForce(Carbon $on): array
    {
        $out = [];
        foreach (self::CODES as $code) {
            $out[$code] = self::where('code', $code)->where('effective_from', '<=', $on->toDateString())->orderByDesc('effective_from')->first();
        }
        return $out;
    }
}
