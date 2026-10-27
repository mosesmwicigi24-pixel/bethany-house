<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/** An item on the Updates feed (People C3). See the c3_updates migration. */
class Post extends Model
{
    use SoftDeletes;

    public const KINDS = ['update', 'recognition', 'celebration', 'event'];

    protected $fillable = ['kind', 'author_id', 'recipient_id', 'outlet_id', 'title', 'body', 'photo_path', 'must_read', 'comments_enabled', 'points', 'event_at', 'event_place', 'celebration_key', 'publish_at', 'published_at', 'notified_at'];

    protected $casts = ['must_read' => 'boolean', 'comments_enabled' => 'boolean', 'points' => 'integer', 'event_at' => 'datetime', 'publish_at' => 'datetime', 'published_at' => 'datetime', 'notified_at' => 'datetime'];

    public function author()    { return $this->belongsTo(User::class, 'author_id'); }
    public function recipient() { return $this->belongsTo(User::class, 'recipient_id'); }
    public function outlet()    { return $this->belongsTo(Outlet::class); }
    public function reads()     { return $this->hasMany(PostRead::class); }
    public function comments()  { return $this->hasMany(PostComment::class)->orderBy('id'); }
    public function rsvps()     { return $this->hasMany(PostRsvp::class); }

    public function scopePublished(Builder $q): Builder { return $q->whereNotNull('published_at')->where('published_at', '<=', now()); }

    /** The posts $user may see: everyone's, their outlets', and all of them with outlets.all_access. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->can('outlets.all_access')) return $q;
        $outletIds = DB::table('outlet_user')->where('user_id', $user->id)->pluck('outlet_id');
        return $q->where(fn ($w) => $w->whereNull('outlet_id')->orWhereIn('outlet_id', $outletIds)->orWhere('author_id', $user->id)->orWhere('recipient_id', $user->id));
    }

    /** Active staff this post is for (the people a must-read waits on). */
    public function audience(): \Illuminate\Support\Collection
    {
        $q = User::where('status', 'active')->where('user_type', 'staff');
        if ($this->outlet_id) $q->whereIn('id', DB::table('outlet_user')->where('outlet_id', $this->outlet_id)->pluck('user_id'));
        return $q->orderBy('first_name')->get(['id', 'first_name', 'last_name']);
    }
}
