<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A checklist or report form (People C4). See the c4 migration. */
class FormTemplate extends Model
{
    use SoftDeletes;

    public const KINDS = ['checklist', 'report'];
    public const ITEM_TYPES = ['check', 'text', 'number', 'photo'];

    protected $fillable = ['name', 'kind', 'outlet_id', 'schedule', 'weekdays', 'due_time', 'items', 'requires_signature', 'active', 'created_by', 'updated_by'];
    protected $casts = ['weekdays' => 'array', 'items' => 'array', 'requires_signature' => 'boolean', 'active' => 'boolean'];

    public function outlet()      { return $this->belongsTo(Outlet::class); }
    public function submissions() { return $this->hasMany(FormSubmission::class); }

    /** Is this checklist due on $date (its weekdays, else the attendance working days)? */
    public function dueOn(\Carbon\Carbon $date): bool
    {
        if ($this->kind !== 'checklist' || $this->schedule !== 'daily' || !$this->active) return false;
        $days = $this->weekdays ?: \App\Support\AttendanceRules::get('working_days');
        return in_array($date->dayOfWeekIso, array_map('intval', (array) $days), true);
    }

    public function dueAt(\Carbon\Carbon $date): ?\Carbon\Carbon
    {
        if (!$this->due_time) return null;
        [$h, $m] = array_map('intval', explode(':', substr((string) $this->due_time, 0, 5)));
        return $date->copy()->setTime($h, $m, 0);
    }
}
