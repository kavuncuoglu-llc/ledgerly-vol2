<?php

namespace App\Http\Controllers;

use App\Ai\Conversations\ConversationStore;
use App\Ai\Tools\PendingAction;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolExecutor;
use App\Models\Conversation;
use App\Models\PendingToolCall;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

// Chapter 33, Topic 6: the queue where a support agent decides about credits, refunds and anything that leaves the account. The customer sees only that it is waiting.
class StaffApprovalController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $pending = PendingToolCall::query()
            ->where('approver', 'agent')
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->with('customer:id,name')
            ->oldest()
            ->get();

        if ($request->expectsJson()) {
            return response()->json(['data' => $pending->map(fn (PendingToolCall $p) => ['id' => $p->id, 'customer' => $p->customer?->name, 'summary' => $p->summary, 'token' => PendingAction::tokenFor($p)])]);
        }

        return view('staff.approvals', ['pending' => $pending]);
    }

    public function approve(Request $request, PendingToolCall $pending, ToolExecutor $executor, ConversationStore $store, CurrentTenant $tenants): JsonResponse|RedirectResponse
    {
        PendingAction::authorise($pending, $request->input('token'), $request->user());

        return $this->settle($request, $pending, true, $executor, $store, $tenants);
    }

    public function decline(Request $request, PendingToolCall $pending, ToolExecutor $executor, ConversationStore $store, CurrentTenant $tenants): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()->can('approve', $pending), 403);

        abort_unless($pending->isPending() && $pending->used_at === null, 410, 'This request has expired or has already been answered.');

        return $this->settle($request, $pending, false, $executor, $store, $tenants);
    }

    private function settle(Request $request, PendingToolCall $pending, bool $approved, ToolExecutor $executor, ConversationStore $store, CurrentTenant $tenants): JsonResponse|RedirectResponse
    {
        abort_unless(PendingAction::claim($pending, $request->user(), $approved), 409, 'This request has already been answered.');

        $pending = $pending->fresh();

        $ctx = new ToolContext(
            user: User::findOrFail($pending->user_id),
            conversationId: $pending->conversation_id,
            source: $pending->source,
            tokenName: $pending->token_name,
            toolCallId: $pending->tool_call_id,
            approvedBy: $pending->approved_by,
        );

        $result = $executor->settle($pending, $approved, $ctx);                    // the tool re-validates against today's state, then runs under the write role

        $this->tellTheCustomer($pending, $approved, $result->ok, $store, $tenants);

        if ($request->expectsJson()) {
            return response()->json(['status' => $pending->status, 'ok' => $result->ok]);
        }

        return redirect()->route('staff.approvals.index');
    }

    private function tellTheCustomer(PendingToolCall $pending, bool $approved, bool $ok, ConversationStore $store, CurrentTenant $tenants): void
    {
        if ($pending->conversation_id === null) {
            return;
        }

        $conversation = Conversation::withoutGlobalScopes()->find($pending->conversation_id);

        if ($conversation === null) {
            return;
        }

        $note = match (true) {
            ! $approved => 'A support agent reviewed this request and did not approve it: '.$pending->summary,
            $ok => 'A support agent approved this request and it is done: '.$pending->summary,
            default => 'A support agent approved this request, but it could not be completed on the current state of the account: '.$pending->summary,
        };

        $tenants->run($conversation->tenant_id, fn () => $store->append($conversation, ['role' => 'assistant', 'content' => $note]));
    }
}
