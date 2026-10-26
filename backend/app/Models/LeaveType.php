<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    protected $fillable = ['code', 'name', 'days_per_year', 'accrual', 'is_paid', 'counts_calendar_days', 'evidence_after_days', 'is_active', 'sort_order'];

    protected $casts = [
        'days_per_year'        => 'decimal:2',
        'is_paid'              => 'boolean',
        'counts_calendar_days' => 'boolean',
        'is_active'            => 'boolean',
    ];

    public function tracksBalance(): bool
    {
        return $this->days_per_year !== null;
    }
}
