<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Timesheet extends Model
{
    public const STATUSES = ['draft', 'submitted', 'sent_back', 'approved'];

    protected $fillable = ['pay_period_id', 'user_id', 'status', 'submitted_at', 'approved_by', 'approved_at', 'note', 'sent_back_note',
        'regular_minutes', 'overtime_minutes', 'rest_day_minutes', 'holiday_minutes', 'paid_leave_days', 'unpaid_leave_days', 'days_worked', 'snapshot'];

    protected $casts = [
        'submitted_at'      => 'datetime',
        'approved_at'       => 'datetime',
        'paid_leave_days'   => 'decimal:2',
        'unpaid_leave_days' => 'decimal:2',
        'snapshot'          => 'array',
    ];

    public function period()     { return $this->belongsTo(PayPeriod::class, 'pay_period_id'); }
    public function user()       { return $this->belongsTo(User::class); }
    public function approvedBy() { return $this->belongsTo(User::class, 'approved_by'); }
}
