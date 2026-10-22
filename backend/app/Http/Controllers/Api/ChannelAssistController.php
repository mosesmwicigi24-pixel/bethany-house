<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Services\Comms\Assist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * POST /channels/{id}/assist — the Messages Assist rail (App\Services\Comms\Assist).
 * Members of the channel only; a handful of taps a minute per person.
 */
class ChannelAssistController extends Controller
{
    public function __construct(private Assist $assist)
    {
    }

    public function run(Request $request, int $id)
    {
        $user    = $request->user();
        $channel = Channel::findOrFail($id);
        $isMember = DB::table('channel_members')->where('channel_id', $channel->id)->where('user_id', $user->id)->exists();
        if (!$isMember) {
            abort(403, 'You are not a member of this channel.');
        }

        $v = $request->validate([
            'action'   => 'required|in:' . implode(',', Assist::ACTIONS),
            'text'     => 'nullable|string|max:4000',
            'language' => 'nullable|in:' . implode(',', array_keys(Assist::LANGUAGES)),
            'audience' => 'nullable|in:customer,team',
        ]);

        if (!$this->assist->configured()) {
            return response()->json(['message' => 'Assist is not set up on this server yet. Ask the owner to add the model key.', 'code' => 'assist_not_configured'], 503);
        }

        $key = 'assist:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 20)) {
            return response()->json(['message' => 'That is plenty of Assist for one minute. Try again shortly.', 'retry_after_seconds' => RateLimiter::availableIn($key)], 429);
        }
        RateLimiter::hit($key, 60);

        // AssistUnavailable carries its own 422 (code assist_unavailable) and
        // is rendered by Laravel; nothing here needs to catch it.
        $out = match ($v['action']) {
            'summarise' => $this->assist->summarise($channel, $user),
            'translate' => $this->assist->translate($channel, $user, (string) ($v['text'] ?? ''), $v['language'] ?? 'en'),
            'draft'     => $this->assist->draft($channel, $user, $v['audience'] ?? 'team', $v['language'] ?? 'en'),
            'replies'   => $this->assist->replies($channel, $user),
        };

        return response()->json($out + ['action' => $v['action']]);
    }
}
