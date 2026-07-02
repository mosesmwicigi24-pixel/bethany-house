<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels - Phase 2 (Reverb)
|--------------------------------------------------------------------------
|
| These channel authorisation callbacks are called when the frontend
| attempts to subscribe to a private or presence channel via Echo.
|
| All channels require the user to be authenticated (auth:sanctum)
| and be a staff/admin user (canAccessAdmin).
|
| Channel naming convention:
|   private-user.{userId}              - personal notification stream
|   private-thread.{model}.{id}       - comment thread for a model instance
|   private-channel.{channelId}       - messaging channel (DM or Space)
|   presence-channel.{channelId}      - presence (online/typing indicators)
|
*/

if (config('broadcasting.default') !== 'reverb') {
    return;
}

// ── Personal user channel - notifications, unread counts ─────────────────────
Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId && $user->canAccessAdmin();
});

// ── Comment thread channel - for any model ────────────────────────────────────
// All admin/staff users may subscribe to any thread they can see.
Broadcast::channel('thread.{model}.{id}', function ($user, $model, $id) {
    return $user->canAccessAdmin();
});

// ── Messaging channel - private-channel.{channelId} ──────────────────────────
// Only channel members may subscribe.
Broadcast::channel('channel.{channelId}', function ($user, $channelId) {
    if (!$user->canAccessAdmin()) return false;

    return \Illuminate\Support\Facades\DB::table('channel_members')
        ->where('channel_id', $channelId)
        ->where('user_id', $user->id)
        ->exists();
});

// ── Presence channel - online + typing indicators ────────────────────────────
// Returns user data instead of bool - Echo uses this for presence payloads.
Broadcast::channel('presence.channel.{channelId}', function ($user, $channelId) {
    if (!$user->canAccessAdmin()) return false;

    $isMember = \Illuminate\Support\Facades\DB::table('channel_members')
        ->where('channel_id', $channelId)
        ->where('user_id', $user->id)
        ->exists();

    if (!$isMember) return false;

    return [
        'id'       => $user->id,
        'name'     => trim("{$user->first_name} {$user->last_name}"),
        'initials' => strtoupper(substr($user->first_name, 0, 1) . substr($user->last_name, 0, 1)),
    ];
});