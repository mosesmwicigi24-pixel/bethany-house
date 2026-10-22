<?php

namespace App\Services\Comms;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;

/**
 * Claude through the official Anthropic PHP SDK (anthropic-ai/sdk).
 *
 * Model and key come from config/assist.php (ASSIST_MODEL, ANTHROPIC_API_KEY).
 * One non-streaming call per tap; the answers here are short (a summary,
 * a translation, a draft), so a few thousand output tokens is plenty.
 */
class ClaudeAssistModel implements AssistModel
{
    private ?Client $client = null;

    public function configured(): bool
    {
        return (bool) config('assist.enabled') && trim((string) config('assist.api_key')) !== '';
    }

    public function complete(string $system, string $user, int $maxTokens = 2000): string
    {
        if (!$this->configured()) {
            throw new AssistUnavailable('Assist is not set up on this server yet.');
        }

        try {
            $message = $this->client()->messages->create(
                model: (string) config('assist.model', 'claude-opus-5-5'),
                maxTokens: $maxTokens,
                system: $system,
                messages: [['role' => 'user', 'content' => $user]],
            );
        } catch (APIStatusException $e) {
            report($e);
            throw new AssistUnavailable('Assist could not answer right now (' . ($e->type?->value ?? 'api error') . ').', $e);
        } catch (APIConnectionException $e) {
            report($e);
            throw new AssistUnavailable('Assist could not reach the model. Try again in a moment.', $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new AssistUnavailable('Assist declined to answer this one.');
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $text = trim($text);
        if ($text === '') {
            throw new AssistUnavailable('Assist returned nothing. Try again.');
        }

        return $text;
    }

    private function client(): Client
    {
        return $this->client ??= new Client(apiKey: (string) config('assist.api_key'));
    }
}
