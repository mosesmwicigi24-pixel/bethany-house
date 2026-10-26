<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One point of a shift's trail (People C1). Kept trail_retention_days, then pruned. */
class LocationPing extends Model
{
    public $timestamps = false;

    protected $fillable = ['time_entry_id', 'user_id', 'latitude', 'longitude', 'accuracy_meters', 'distance_meters', 'mocked', 'recorded_at'];

    protected $casts = ['latitude' => 'float', 'longitude' => 'float', 'mocked' => 'boolean', 'recorded_at' => 'datetime', 'created_at' => 'datetime'];

    public function entry() { return $this->belongsTo(TimeEntry::class, 'time_entry_id'); }
}
