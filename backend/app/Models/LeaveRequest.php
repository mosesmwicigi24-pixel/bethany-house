<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    use HasFactory;

    public const STATUSES = ['pending', 'approved', 'declined', 'cancelled'];

    protected $fillable = [
        'user_id', 'leave_type_id', 'starts_on', 'ends_on', 'days', 'reason', 'status', 'evidence_document_id',
        'created_by', 'decided_by', 'decided_at', 'decision_note', 'cancelled_at', 'cancelled_by',
    ];

    protected $casts = [
        'starts_on'    => 'date:Y-m-d',
        'ends_on'      => 'date:Y-m-d',
        'days'         => 'decimal:2',
        'decided_at'   => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function user()      { return $this->belongsTo(User::class); }
    public function type()      { return $this->belongsTo(LeaveType::class, 'leave_type_id'); }
    public function decidedBy() { return $this->belongsTo(User::class, 'decided_by'); }

    public function scopeLive($q)     { return $q->whereIn('status', ['pending', 'approved']); }
    public function scopeApproved($q) { return $q->where('status', 'approved'); }
}
