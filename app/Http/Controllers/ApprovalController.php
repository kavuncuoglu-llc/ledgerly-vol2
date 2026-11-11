<?php

namespace App\Http\Controllers;

use App\Ai\Streaming\ReplyStarter;
use App\Ai\Tools\ToolRunner;
use App\Models\PendingToolCall;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    public function show(Request $request, PendingToolCall $pending): View|JsonResponse
    {
        $this->ensureOwner($request, $pending);

        if ($request->expectsJson()) {
            return response()->json(['id' => $pending->id, 'tool' => $pending->tool, 'summary' => $pending->summary, 'status' => $pending->status]);
        }

        return view('support.approval', ['pending' => $pending]);
    }

    public function approve(Request $request, PendingToolCall $pending, ToolRunner $runner, ReplyStarter $starter): JsonResponse|RedirectResponse
    {
        return $this->settle($request, $pending, $runner, $starter, approved: true);
    }

    public function decline(Request $request, PendingToolCall $pending, ToolRunner $runner, ReplyStarter $starter): JsonResponse|RedirectResponse
    {
        return $this->settle($request, $pending, $runner, $starter, approved: false);
    }

    private function settle(Request $request, PendingToolCall $pending, ToolRunner $runner, ReplyStarter $starter, bool $approved): JsonResponse|RedirectResponse
    {
        $this->ensureOwner($request, $pending);

        abort_unless($pending->isPending(), 409, 'This request has already been answered.');

        if ($pending->conversation_id !== null && $pending->source === 'chat') {
            return $this->queue($request, $pending, $starter, $approved);          // Chapter 18: the model carries on in a job, never in this request
        }

        $result = $runner->resolve($pending, $approved);

        if ($request->expectsJson()) {
            return response()->json(['status' => $pending->fresh()->status, 'answer' => $result->answer(), 'truncated' => $result->truncated()]);
        }

        return redirect()->route('support.approvals.show', $pending)->with('answer', $result->answer());
    }

    private function queue(Request $request, PendingToolCall $pending, ReplyStarter $starter, bool $approved): JsonResponse|RedirectResponse
    {
        $reply = $starter->resume($request->user(), $pending, $approved);

        if ($request->expectsJson()) {
            return response()->json(['status' => 'resuming', 'reply_id' => $reply->id, 'channel' => "reply.{$reply->id}"], 202);
        }

        return redirect()->route('support.approvals.show', $pending)->with('answer', 'Thanks. The assistant is carrying on; the answer will appear in the chat.');
    }

    private function ensureOwner(Request $request, PendingToolCall $pending): void
    {
        abort_unless($pending->user_id === $request->user()->id, 403);
    }
}
