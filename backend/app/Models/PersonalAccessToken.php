<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum's token, plus what sign-in safety keeps on the session (Phase 4C):
 * when the person last did something (idle limit), whether it is PIN-locked,
 * when it last passed step-up, and where it was signed in from.
 * Registered in AuthServiceProvider (Sanctum::usePersonalAccessTokenModel).
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    protected $casts = [
        'abilities'      => 'json',
        'last_used_at'   => 'datetime',
        'expires_at'     => 'datetime',
        'last_active_at' => 'datetime',
        'locked_at'      => 'datetime',
        'stepped_up_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        // The sessions list shows where each session was signed in from.
        static::creating(function (self $token) {
            $request = request();
            $token->ip_address ??= $request->ip();
            $token->user_agent ??= mb_substr((string) $request->userAgent(), 0, 512) ?: null;
            // Signing in is activity: the idle clock starts now.
            $token->last_active_at ??= now();
        });
    }
}
