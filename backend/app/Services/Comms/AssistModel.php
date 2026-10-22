<?php

namespace App\Services\Comms;

/**
 * The one door to a language model for the Messages Assist rail. The real
 * implementation calls Claude (ClaudeAssistModel); tests bind FakeAssistModel
 * so the suite never leaves the building.
 */
interface AssistModel
{
    /** Is a key configured and the feature switched on? */
    public function configured(): bool;

    /**
     * One prompt in, one answer out. $system frames the task; $user carries the
     * thread text. Throws AssistUnavailable when the model declines or fails.
     */
    public function complete(string $system, string $user, int $maxTokens = 2000): string;
}
