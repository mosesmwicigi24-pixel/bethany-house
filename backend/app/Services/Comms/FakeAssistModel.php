<?php

namespace App\Services\Comms;

/** For tests: records every prompt and answers with a canned line that names the task. */
class FakeAssistModel implements AssistModel
{
    /** @var list<array{system:string,user:string,max:int}> */
    public array $calls = [];

    /** Set this to script the next answer; a test's controller keeps the same instance across requests. */
    public ?string $answer;

    public function __construct(private bool $isConfigured = true, ?string $answer = null)
    {
        $this->answer = $answer;
    }

    public function configured(): bool
    {
        return $this->isConfigured;
    }

    public function complete(string $system, string $user, int $maxTokens = 2000): string
    {
        $this->calls[] = ['system' => $system, 'user' => $user, 'max' => $maxTokens];

        return $this->answer ?? ('FAKE: ' . substr($system, 0, 40));
    }
}
