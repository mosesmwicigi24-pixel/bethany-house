<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class TimeEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'outlet_id',
        'clock_in_at',
        'clock_in_latitude',
        'clock_in_longitude',
        'clock_in_distance_meters',
        'clock_in_method',
        'clock_out_at',
        'clock_out_latitude',
        'clock_out_longitude',
        'clock_out_distance_meters',
        'breaks',
        'total_break_minutes',
        'worked_minutes',
        'status',
        'flagged_reason',
        'notes',
        'device_info',
        'overridden_by',
        'corrected_by',
    ];

    protected $casts = [
        'clock_in_at'              => 'datetime',
        'clock_out_at'             => 'datetime',
        'clock_in_latitude'        => 'decimal:7',
        'clock_in_longitude'       => 'decimal:7',
        'clock_out_latitude'       => 'decimal:7',
        'clock_out_longitude'      => 'decimal:7',
        'clock_in_distance_meters' => 'decimal:2',
        'clock_out_distance_meters'=> 'decimal:2',
        'breaks'                   => 'array',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function overriddenBy()
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }

    public function correctedBy()
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeFlagged($query)
    {
        return $query->where('status', 'flagged');
    }

    public function scopeForOutlet($query, $outletId)
    {
        return $query->where('outlet_id', $outletId);
    }

    public function scopeBetween($query, $from, $to)
    {
        return $query->whereDate('clock_in_at', '>=', $from)
                     ->whereDate('clock_in_at', '<=', $to);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Great-circle (haversine) distance in meters between two coordinates.
     * Used to validate a clock-in against an outlet's geofence radius.
     */
    public static function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000; // meters

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Sum of all closed breaks in the `breaks` json array, in minutes.
     * An open break (no ended_at yet) doesn't count until it's closed.
     */
    public function calculateBreakMinutes(): int
    {
        $minutes = 0;
        foreach ($this->breaks ?? [] as $break) {
            if (!empty($break['started_at']) && !empty($break['ended_at'])) {
                $minutes += Carbon::parse($break['started_at'])
                    ->diffInMinutes(Carbon::parse($break['ended_at']));
            }
        }
        return $minutes;
    }

    public function hasOpenBreak(): bool
    {
        foreach ($this->breaks ?? [] as $break) {
            if (!empty($break['started_at']) && empty($break['ended_at'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Minutes elapsed so far on an still-active entry (for "live" UI ticking).
     * For a completed entry, falls back to the stored worked_minutes.
     */
    public function elapsedMinutes(): int
    {
        if ($this->status !== 'active') {
            return $this->worked_minutes ?? 0;
        }

        $grossMinutes = $this->clock_in_at->diffInMinutes(now());
        return max(0, $grossMinutes - $this->calculateBreakMinutes());
    }
}
