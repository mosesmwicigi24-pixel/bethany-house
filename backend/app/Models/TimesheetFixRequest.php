<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** "Something is wrong with my hours on this day" (People C1). The manager corrects the entry; this is the ask and the answer. */
class TimesheetFixRequest extends Model
{
    protected $fillable = ['user_id', 'date', 'message', 'status', 'decided_by', 'decided_at', 'decision_note'];

    protected $casts = ['date' => 'date:Y-m-d', 'decided_at' => 'datetime'];

    public function user()      { return $this->belongsTo(User::class); }
    public function decidedBy() { return $this->belongsTo(User::class, 'decided_by'); }
}
