<?php

namespace App\Http\Controllers;

use App\Ai\Feedback\RecordFeedback;
use App\Http\Requests\FeedbackRequest;
use App\Models\Message;
use Illuminate\Http\JsonResponse;

// Chapter 30, Topic 3: a thumb, and for a thumbs-down which part failed, stored with the call, the prompt version, the provider and the variant that wrote the answer.
class AnswerFeedbackController extends Controller
{
    public function store(FeedbackRequest $request, Message $reply, RecordFeedback $record): JsonResponse
    {
        abort_unless($reply->question !== null, 404);

        abort_unless($reply->conversation->user_id === $request->user()->id, 404);   // only the customer who received it; anybody else is not even told it exists

        $feedback = $record($reply, $request->validated('rating'), $request->validated('reason'));

        return response()->json(['feedback' => $feedback->rating, 'reason' => $feedback->reason]);
    }
}
