<?php

namespace App\Services\Comms;

use Illuminate\Support\Facades\URL;

/**
 * The Acknowledge and ✕ buttons on a message banner (owner, 2026-11-04).
 *
 * A banner is shown by the service worker, which has no login. Each one
 * carries two links signed for one person, one conversation and one
 * message, valid as long as the push itself (24 hours). They travel only
 * inside the encrypted push to that person's own devices, and are never
 * stored or broadcast (the in-app notification keeps none of this).
 */
class PushActions
{
    public const TTL_HOURS = 24;

    /** @return array{acknowledge:string, dismiss:string} paths relative to the site root */
    public static function links(int $userId, int $channelId, int $messageId): array
    {
        $link = fn (string $action) => URL::temporarySignedRoute(
            'push-actions.channel',
            now()->addHours(self::TTL_HOURS),
            ['id' => $channelId, 'action' => $action, 'user' => $userId, 'message' => $messageId],
            absolute: false,
        );

        return ['acknowledge' => $link('acknowledge'), 'dismiss' => $link('dismiss')];
    }
}
