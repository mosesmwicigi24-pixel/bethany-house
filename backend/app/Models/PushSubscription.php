<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PushSubscription
 *
 * Represents one push subscription for a staff user.
 * A user may have multiple active subscriptions across devices and channels.
 *
 * Two subscription types are supported:
 *
 *   token_type = 'web'
 *     Browser Web Push (VAPID / PWA). endpoint, p256dh, and auth are populated.
 *     expo_token is NULL.
 *
 *   token_type = 'expo'
 *     Expo Push Service (React Native mobile app). expo_token is populated.
 *     endpoint is set to "expo:<token>" to satisfy the NOT NULL + UNIQUE
 *     constraint. p256dh and auth are empty strings (not used for this path).
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $endpoint       Web Push endpoint URL, or "expo:<token>" for Expo
 * @property string      $p256dh         Web Push key (empty string for Expo subscriptions)
 * @property string      $auth           Web Push auth (empty string for Expo subscriptions)
 * @property string|null $expo_token     Expo push token (NULL for web subscriptions)
 * @property string      $token_type     'web' | 'expo'
 * @property string|null $user_agent
 * @property bool        $is_active
 */
class PushSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'endpoint',
        'p256dh',
        'auth',
        'expo_token',
        'token_type',
        'user_agent',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeWeb($query)
    {
        return $query->where('token_type', 'web');
    }

    public function scopeExpo($query)
    {
        return $query->where('token_type', 'expo');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build the subscription array format expected by minishlink/web-push.
     * Only valid for web (VAPID) subscriptions.
     */
    public function toWebPushArray(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'keys'     => [
                'p256dh' => $this->p256dh,
                'auth'   => $this->auth,
            ],
        ];
    }

    /**
     * Whether this subscription uses the Expo Push Service.
     */
    public function isExpo(): bool
    {
        return $this->token_type === 'expo';
    }

    /**
     * Whether this subscription uses VAPID Web Push.
     */
    public function isWeb(): bool
    {
        return $this->token_type === 'web';
    }
}