<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use HasFactory;

    public const STATUSES = ['draft', 'published', 'cancelled'];

    protected $fillable = [
        'outlet_id', 'user_id', 'date', 'starts_at', 'ends_at', 'label', 'notes', 'status',
        'published_at', 'cancelled_at', 'reminded_at', 'clock_in_nudged_at', 'clock_out_nudged_at', 'missed_alerted_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'date'         => 'date:Y-m-d',
        'published_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'reminded_at'  => 'datetime',
    ];

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function swapRequests()
    {
        return $this->hasMany(ShiftSwapRequest::class);
    }

    public function scopeLive($q)
    {
        return $q->where('status', '!=', 'cancelled');
    }

    public function scopePublished($q)
    {
        return $q->where('status', 'published');
    }

    public function scopeOpen($q)
    {
        return $q->whereNull('user_id');
    }

    /** "HH:MM" from a time column whatever the driver hands back. */
    public static function hhmm(?string $t): ?string
    {
        return $t === null ? null : substr($t, 0, 5);
    }

    public function startsAtCarbon(): Carbon
    {
        return Carbon::parse($this->date->toDateString() . ' ' . self::hhmm($this->starts_at), config('app.timezone'));
    }

    public function endsAtCarbon(): Carbon
    {
        $end = Carbon::parse($this->date->toDateString() . ' ' . self::hhmm($this->ends_at), config('app.timezone'));
        return $end->lte($this->startsAtCarbon()) ? $end->addDay() : $end;
    }

    public function minutes(): int
    {
        return (int) $this->startsAtCarbon()->diffInMinutes($this->endsAtCarbon());
    }
}
