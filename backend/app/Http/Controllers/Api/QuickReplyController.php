<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuickReply;
use Illuminate\Http\Request;

/**
 * /channels/quick-replies — the business's saved messages (Messages, `/`).
 * Every staff member reads the list; admins write it, the same gate as
 * deleting someone else's message (ChannelController::deleteMessage).
 */
class QuickReplyController extends Controller
{
    private const RULES = [
        'title' => 'required|string|min:2|max:80',
        'body'  => 'required|string|min:1|max:2000',
    ];

    public function index()
    {
        return response()->json(['quick_replies' => QuickReply::orderBy('title')->get(['id', 'title', 'body', 'created_by', 'updated_at'])]);
    }

    public function store(Request $request)
    {
        $this->gate($request);
        $v = $request->validate(self::RULES);
        $r = QuickReply::create($v + ['created_by' => $request->user()->id]);

        return response()->json(['quick_reply' => $r], 201);
    }

    public function update(Request $request, int $id)
    {
        $this->gate($request);
        $r = QuickReply::findOrFail($id);
        $r->update($request->validate(self::RULES));

        return response()->json(['quick_reply' => $r->fresh()]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->gate($request);
        QuickReply::findOrFail($id)->delete();

        return response()->json(['message' => 'Quick reply deleted.']);
    }

    private function gate(Request $request): void
    {
        if (!$request->user()->hasRole(['admin', 'super_admin'])) {
            abort(403, 'Only admins can change the quick replies.');
        }
    }
}
