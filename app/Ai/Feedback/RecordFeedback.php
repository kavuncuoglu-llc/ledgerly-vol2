<?php

namespace App\Ai\Feedback;

use App\Models\AnswerFeedback;
use App\Models\Message;
use App\Models\ModelCall;

// Chapter 30, Topic 3: one place that stores a rating, whether a customer clicked it or the replay did.
final class RecordFeedback
{
    public function __invoke(Message $reply, string $rating, ?string $reason = null): AnswerFeedback
    {
        $feedback = AnswerFeedback::query()->firstOrNew(['message_id' => $reply->id]);

        $feedback->fill([
            'rating' => $rating,
            'reason' => $rating === 'down'
                ? $reason
                : null,
            'model_call_id' => $reply->model_call_id,
            'prompt_version' => $reply->prompt_version,
            'provider' => $reply->model_call_id
                ? ModelCall::query()->whereKey($reply->model_call_id)->value('provider')
                : null,
            'variant' => $reply->experiment_variant,
        ])->save();

        $reply->update(['feedback' => $rating]);                                      // the Chapter 18 column the pages and eval:capture already read

        return $feedback;
    }
}
