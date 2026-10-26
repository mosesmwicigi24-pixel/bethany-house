<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveAdjustment extends Model
{
    protected $fillable = ['user_id', 'leave_type_id', 'year', 'days', 'reason', 'created_by'];

    protected $casts = ['days' => 'decimal:2', 'year' => 'integer'];

    public function type()
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
