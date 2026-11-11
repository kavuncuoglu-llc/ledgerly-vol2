<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\MessageSource;
use App\Models\PendingToolCall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReplyController extends Controller
{
    public function show(Request $request, Message $reply): JsonResponse
    {
        abort_unless($reply->question !== null, 404);                              // only reply placeholders are served here, never a user's own turn or a tool result

        abort_unless($reply->conversation->user_id === $request->user()->id, 404);   // somebody else's reply is not even confirmed to exist

        return response()->json([
            'id' => $reply->id,
            'status' => $reply->status,
            'content' => (string) $reply->content,
            'feedback' => $reply->feedback,
            'pending' => $this->pendingApproval($request, $reply),
            'sources' => MessageSource::query()
                ->where('message_id', $reply->id)
                ->get(['label', 'url'])
                ->map(fn (MessageSource $source) => ['label' => $source->label, 'url' => $source->url])
                ->all(),
        ]);
    }

    private function pendingApproval(Request $request, Message $reply): ?array
    {
        if ($reply->status !== 'paused') {
            return null;
        }

        $pending = PendingToolCall::query()
            ->where('conversation_id', $reply->conversation_id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if ($pending === null) {
            return null;
        }

        return [
            'id' => $pending->id,
            'tool' => $pending->tool,
            'summary' => $pending->summary,
            'approve_url' => route('support.approve', $pending, false),
            'decline_url' => route('support.decline', $pending, false),
        ];
    }
}
