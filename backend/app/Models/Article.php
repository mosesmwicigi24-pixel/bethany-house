<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/** A handbook article: a policy or a how-to (People C4). */
class Article extends Model
{
    use SoftDeletes;

    protected $fillable = ['title', 'slug', 'category', 'body', 'outlet_id', 'must_read', 'post_id', 'published_at', 'created_by', 'updated_by'];
    protected $casts = ['must_read' => 'boolean', 'published_at' => 'datetime'];

    public function post()   { return $this->belongsTo(Post::class); }
    public function outlet() { return $this->belongsTo(Outlet::class); }

    public function scopePublished(Builder $q): Builder { return $q->whereNotNull('published_at'); }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->can('outlets.all_access')) return $q;
        $outletIds = DB::table('outlet_user')->where('user_id', $user->id)->pluck('outlet_id');
        return $q->where(fn ($w) => $w->whereNull('outlet_id')->orWhereIn('outlet_id', $outletIds));
    }
}
