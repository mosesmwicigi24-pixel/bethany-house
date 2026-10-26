<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayPeriod extends Model
{
    protected $fillable = ['pay_group_id', 'starts_on', 'ends_on', 'status', 'locked_by', 'locked_at', 'unlocked_by', 'unlocked_at', 'unlock_reason'];

    protected $casts = [
        'starts_on'   => 'date:Y-m-d',
        'ends_on'     => 'date:Y-m-d',
        'locked_at'   => 'datetime',
        'unlocked_at' => 'datetime',
    ];

    public function group()      { return $this->belongsTo(PayGroup::class, 'pay_group_id'); }
    public function timesheets() { return $this->hasMany(Timesheet::class); }

    public function isLocked(): bool
    {
        return $this->status === 'locked';
    }
}
