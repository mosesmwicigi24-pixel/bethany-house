<?php

namespace Tests\Concerns;

use App\Models\User;

/**
 * Privileged routes need a recent step-up (Phase 4C, App\Services\Auth\StepUp).
 * Sanctum::actingAs gives the user a token that is not a stored session, so
 * the confirmation is recorded against the person — exactly where the
 * middleware looks for a session without a stored token.
 */
trait StepsUp
{
    protected function stepUp(User $u): void
    {
        app(\App\Services\Auth\StepUp::class)->confirmFor($u, null);
    }
}
