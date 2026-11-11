<?php

namespace App\Jobs;

use App\Ai\Contracts\ChatRequest;
use App\Ai\Experiments\Experiment;
use App\Ai\Prompts\SupportPrompt;
use App\Evals\Gates;
use App\Facades\Ai;
use App\Jobs\Middleware\PauseWhenBudgetSpent;
use App\Models\Message;
use App\Models\ShadowAnswerRecord;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

// Chapter 30, Figure 30-8: the candidate answers the same question with the same sources, unseen. It carries an id, never text (Chapter 34), and its answer goes to a table nothing renders.
// The shadow answer has no tools: a candidate must not act on a customer's account. Questions that needed a tool are therefore compared on the help-center part only.
final class ShadowAnswer implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $messageId)
    {
        $this->onQueue('ai-batch');
    }

    public function middleware(): array
    {
        return [new PauseWhenBudgetSpent];                                         // Chapter 36: nobody is waiting on a shadow answer
    }

    public function handle(): void
    {
        $live = Message::withoutGlobalScopes()->findOrFail($this->messageId);

        $candidate = Experiment::candidateFor('support.reply');

        if ($candidate === null) {
            return;                                                                // the experiment was switched off while this waited in the queue
        }

        $experiment = (string) (Experiment::running('support.reply', 'shadow')['name'] ?? 'shadow');

        $messages = SupportPrompt::messages(
            question: (string) $live->question,
            contextChunks: $live->retrievedSources(),
            history: $live->historyBefore(),
            system: $candidate->system,
        );

        $answer = Ai::chat($candidate->provider)->chat(ChatRequest::make($messages, [
            'profile' => 'assistant',
            'max_tokens' => config('ai.budget.answer'),
            'prompt_version' => $candidate->version,
            'variant' => $candidate->name,
        ], 'support.shadow'));                                                     // its own feature name: the live statistics stay about customers

        $customerId = User::query()->whereKey($live->conversation->user_id)->value('customer_id');

        ShadowAnswerRecord::create([
            'message_id' => $live->id,
            'experiment' => $experiment,
            'variant' => $candidate->name,
            'content' => (string) $answer->content,
            'model_call_id' => $answer->callId,
            'gates' => Gates::evaluate((string) $answer->content, $live->retrievedLabels(), customerId: $customerId)->toArray(),   // Chapter 29's gates, offline
        ]);
    }
}
