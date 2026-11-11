<?php

namespace App\Http\Controllers;

use App\Ai\Streaming\ReplyStarter;
use App\Http\Requests\AskRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

class SupportController extends Controller
{
    public function show(): View
    {
        return view('support.show', [
            'demoQuestions' => [
                'What is a late fee?',
                'What late fee does Ledgerly charge?',
                'Why is my March invoice marked late?',
                'My client says they paid but it still shows unpaid, can you log this?',
            ],
        ]);
    }

    public function ask(AskRequest $request, ReplyStarter $starter): JsonResponse
    {
        $reply = $starter->start($request->user(), $request->validated('question'), $request->conversation());

        return response()->json([
            'reply_id' => $reply->id,
            'channel' => "reply.{$reply->id}",
            'conversation_id' => $reply->conversation_id,
        ], 202);
    }
}
