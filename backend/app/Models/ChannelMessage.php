<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChannelMessage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'channel_id', 'user_id', 'reply_to_id',
        'type', 'body', 'mentions', 'linked_entities', 'attachments', 'reactions', 'edited_at',
    ];

    protected $casts = [
        'mentions'       => 'array',
        'linked_entities' => 'array',
        'attachments'    => 'array',
        'reactions'      => 'array',
        'edited_at'      => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(ChannelMessage::class, 'reply_to_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ChannelMessage::class, 'reply_to_id');
    }

    /**
     * Parse @mention tags from body and return user IDs.
     */
    public static function parseMentions(string $body): array
    {
        preg_match_all('/@\[([^\]]+)\]\(user:(\d+)\)/', $body, $matches);
        return array_map('intval', $matches[2] ?? []);
    }

    /**
     * Parse #[label](entity:type:id) tags from body and return linked entities array.
     *
     * Token format written by the frontend composer:
     *   #[#ORD-1234](entity:order:1234)
     *   #[#PROD-056](entity:production_order:56)
     *
     * Returns:
     *   [['type' => 'order', 'id' => 1234, 'label' => '#ORD-1234'], ...]
     *
     * Deduplicated by type+id — tagging the same entity twice in one message
     * stores only one entry. IntelligenceService::entityChipPreviews() keys
     * its output by "type:id" so duplicates would be harmless, but keeping
     * the stored array clean avoids redundant DB lookups on render.
     */
    public static function parseLinkedEntities(string $body): array
    {
        preg_match_all('/#\[([^\]]+)\]\(entity:([^:]+):(\d+)\)/', $body, $matches, PREG_SET_ORDER);
        $seen = [];
        return array_values(array_filter(
            array_map(fn ($m) => [
                'type'  => $m[2],
                'id'    => (int) $m[3],
                'label' => $m[1],
            ], $matches),
            function ($e) use (&$seen) {
                $key = $e['type'] . ':' . $e['id'];
                return isset($seen[$key]) ? false : ($seen[$key] = true);
            }
        ));
    }
}