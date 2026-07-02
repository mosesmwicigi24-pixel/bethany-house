<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;

class Channel extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'type', 'name', 'description', 'slug',
        'is_private', 'created_by', 'last_message_id', 'last_activity_at',
        'context_type', 'context_id',
    ];

    protected $casts = [
        'is_private'       => 'boolean',
        'last_activity_at' => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'channel_members')
            ->withPivot(['role', 'last_read_message_id', 'joined_at', 'muted_until', 'dismissed_at'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChannelMessage::class)->orderBy('created_at');
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(ChannelMessage::class, 'last_message_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Find or create a context-scoped channel for a given entity.
     *
     * Race-safe: if two concurrent requests both pass the initial lookup and
     * both attempt an INSERT, the DB unique partial index on (context_type,
     * context_id) will reject the second one with a UniqueConstraintViolation.
     * We catch that and simply re-fetch the row that the first request committed,
     * so the caller always gets exactly one channel back regardless of concurrency.
     *
     * Also handles the edge case where a soft-deleted context channel exists —
     * it is restored (deleted_at = null) rather than creating a second row.
     */
    public static function findOrCreateContext(
        string $contextType,
        int    $contextId,
        string $name,
        int    $createdBy
    ): self {
        // 1. Check for an active (non-deleted) context channel first.
        $existing = self::where('context_type', $contextType)
            ->where('context_id', $contextId)
            ->first();

        if ($existing) {
            return $existing;
        }

        // 2. Also check for a soft-deleted one — restore it rather than
        //    creating a duplicate that would violate the unique index on restore.
        $trashed = self::withTrashed()
            ->where('context_type', $contextType)
            ->where('context_id', $contextId)
            ->first();

        if ($trashed) {
            $trashed->restore();
            return $trashed->fresh();
        }

        // 3. Legacy fallback: channels created before the context_type / context_id
        //    columns existed have NULL in both fields but a matching name
        //    (format: "PRD · PO-260527-RF3FM" or "Order · ORD-1234").
        //    Find by the order-number portion of the name and backfill the context
        //    columns so future calls hit step 1 instead of creating a duplicate.
        $orderNum = explode(' · ', $name, 2)[1] ?? null;
        if ($orderNum) {
            $legacy = self::whereNull('context_type')
                ->whereNull('context_id')
                ->where('name', 'like', '%' . $orderNum . '%')
                ->where('type', 'space')
                ->first();

            if ($legacy) {
                // Backfill context columns so subsequent calls use path 1.
                $legacy->update([
                    'context_type' => $contextType,
                    'context_id'   => $contextId,
                ]);
                return $legacy->fresh();
            }
        }

        // 4. Attempt creation. Wrap in try/catch so that if two requests race
        //    past step 1 simultaneously, the loser catches the unique violation
        //    and falls back to fetching the winner's row instead of crashing.
        try {
            return self::create([
                'type'         => 'space',
                'name'         => $name,
                'is_private'   => true,
                'created_by'   => $createdBy,
                'context_type' => $contextType,
                'context_id'   => $contextId,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request won the race — fetch the row it just committed.
            return self::where('context_type', $contextType)
                ->where('context_id', $contextId)
                ->firstOrFail();
        }
    }

    /**
     * Create or find a DM channel between exactly two users.
     */
    public static function findOrCreateDm(int $userAId, int $userBId): self
    {
        $sorted = collect([$userAId, $userBId])->sort()->values();

        // Look for an existing DM channel both users are members of
        $existing = self::where('type', 'dm')
            ->whereHas('members', fn ($q) => $q->where('user_id', $sorted[0]))
            ->whereHas('members', fn ($q) => $q->where('user_id', $sorted[1]))
            ->first();

        if ($existing) return $existing;

        $channel = self::create(['type' => 'dm', 'is_private' => true]);
        $channel->members()->attach([
            $sorted[0] => ['role' => 'member'],
            $sorted[1] => ['role' => 'member'],
        ]);

        return $channel;
    }

    /**
     * Unread message count for a specific user.
     */
    public function unreadCountFor(int $userId): int
    {
        $pivot = \Illuminate\Support\Facades\DB::table('channel_members')
            ->where('channel_id', $this->id)
            ->where('user_id', $userId)
            ->first(['last_read_message_id']);

        if (!$pivot) return 0;

        return $this->messages()
            ->where('user_id', '!=', $userId)
            ->when($pivot->last_read_message_id, fn ($q) =>
                $q->where('id', '>', $pivot->last_read_message_id)
            )
            ->count();
    }
}