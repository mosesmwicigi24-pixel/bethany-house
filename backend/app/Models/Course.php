<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Course extends Model
{
    use SoftDeletes;
    protected $fillable = ['title', 'description', 'outlet_id', 'required', 'pass_mark', 'published_at', 'announced_at', 'created_by', 'updated_by'];
    protected $casts = ['required' => 'boolean', 'published_at' => 'datetime', 'announced_at' => 'datetime'];
    public function lessons()   { return $this->hasMany(CourseLesson::class)->orderBy('position'); }
    public function questions() { return $this->hasMany(CourseQuestion::class)->orderBy('position'); }
    public function progress()  { return $this->hasMany(CourseProgress::class); }
    public function outlet()    { return $this->belongsTo(Outlet::class); }
    public function scopePublished(Builder $q): Builder { return $q->whereNotNull('published_at'); }
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->can('outlets.all_access')) return $q;
        $ids = DB::table('outlet_user')->where('user_id', $user->id)->pluck('outlet_id');
        return $q->where(fn ($w) => $w->whereNull('outlet_id')->orWhereIn('outlet_id', $ids));
    }
    /** Active staff the course is for. */
    public function audience()
    {
        $q = User::where('status', 'active')->where('user_type', 'staff');
        if ($this->outlet_id) $q->whereIn('id', DB::table('outlet_user')->where('outlet_id', $this->outlet_id)->pluck('user_id'));
        return $q->get();
    }
}
