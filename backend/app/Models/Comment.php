<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Comment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'commentable_type',
        'commentable_id',
        'user_id',
        'parent_id',
        'type',
        'body',
        'is_internal',
        'mentions',
        'metadata',
        'edited_at',
    ];

    protected $casts = [
        'mentions'    => 'array',
        'metadata'    => 'array',
        'is_internal' => 'boolean',
        'edited_at'   => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id')->orderBy('created_at');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Parse @mention tags from body text and return an array of user IDs.
     * Expects mentions in the format @[Name](user:42).
     */
    public static function parseMentions(string $body): array
    {
        preg_match_all('/@\[([^\]]+)\]\(user:(\d+)\)/', $body, $matches);
        return array_map('intval', $matches[2] ?? []);
    }

    /**
     * Return the body with @mention markup stripped to plain names.
     */
    public function getPlainBodyAttribute(): string
    {
        return preg_replace('/@\[([^\]]+)\]\(user:\d+\)/', '@$1', $this->body);
    }
}