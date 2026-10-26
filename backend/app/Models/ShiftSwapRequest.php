<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftSwapRequest extends Model
{
    protected $fillable = ['shift_id', 'from_user_id', 'to_user_id', 'note', 'status', 'decided_by', 'decided_at', 'decision_note'];

    protected $casts = ['decided_at' => 'datetime'];

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function fromUser()
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser()
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function scopePending($q)
    {
        return $q->where('status', 'pending');
    }
}
