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
        'clock_in_recorded_at',
        'clock_in_latitude',
        'clock_in_longitude',
        'clock_in_distance_meters',
        'clock_in_accuracy_meters',
        'clock_in_method',
        'clock_out_at',
        'clock_out_recorded_at',
        'clock_out_latitude',
        'clock_out_longitude',
        'clock_out_distance_meters',
        'clock_out_accuracy_meters',
        'breaks', 'left_site_at', 'trail_points', 'overtime_alerted_at', 'clock_in_photo_path', 'clock_out_photo_path',
        'total_break_minutes',
        'worked_minutes',
        'status',
        'flagged_reason',
        'flags',
        'notes',
        'device_info',
        'device_id',
        'app_version',
        'overridden_by',
        'corrected_by',
        'auto_closed_at',
    ];

    /**
     * Why a manager should look at a punch. Stored as a list in `flags`;
     * the human reading lands in `flagged_reason` when the entry closes.
     */
    public const FLAG_LABELS = [
        'accuracy_low'  => 'Phone location was less precise than the clock-in zone',
        'edge'          => 'Just outside the zone, within the phone\'s uncertainty',
        'sent_late'     => 'Punch was made offline and sent later',
        'mock_location' => 'Phone reported a mock location',
        'auto_clock_out'=> 'Shift was closed automatically at the cut-off time',
        'no_shift'      => 'Clocked in without a rostered shift',
        'left_site'     => 'Left the site while clocked in',
        'daily_limit'   => 'Worked past the daily limit',
    ];

    public static function describeFlags(array $flags): ?string
    {
        $labels = array_values(array_filter(array_map(fn ($f) => self::FLAG_LABELS[$f] ?? null, $flags)));
        return $labels ? implode('; ', $labels) : null;
    }

    protected $casts = [
        'clock_in_at'              => 'datetime',
        'clock_out_at'             => 'datetime',
        'clock_in_recorded_at'     => 'datetime',
        'clock_out_recorded_at'    => 'datetime',
        'auto_closed_at'           => 'datetime',
        'flags'                    => 'array',
        'clock_in_latitude'        => 'decimal:7',
        'clock_in_longitude'       => 'decimal:7',
        'clock_out_latitude'       => 'decimal:7',
        'clock_out_longitude'      => 'decimal:7',
        'clock_in_distance_meters' => 'decimal:2',
        'clock_out_distance_meters'=> 'decimal:2',
        'clock_in_accuracy_meters' => 'decimal:2',
        'clock_out_accuracy_meters'=> 'decimal:2',
        'breaks'                   => 'array',
        'left_site_at'             => 'datetime',
        'overtime_alerted_at'      => 'datetime',
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
                $minutes += (int) Carbon::parse($break['started_at'])
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

        $grossMinutes = (int) $this->clock_in_at->diffInMinutes(now());
        return max(0, $grossMinutes - $this->calculateBreakMinutes());
    }
}
