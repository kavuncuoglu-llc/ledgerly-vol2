<?php

namespace App\Jobs;

use App\Evals\Similarity;
use App\Models\AnswerSignal;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\ToolCall;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

// Chapter 30, Topic 3: what customers did after an answer, read off the logs once a day. A re-ask is a new question within two minutes whose embedding is close to the last one; a follow-up ticket is a
// ticket the customer opened within the hour on the same topic; an escalation is a ticket the assistant opened; resolved is none of those. Replies are dated by when they were written (now(), not the seed's working date).
final class ComputeOnlineSignals implements ShouldQueue
{
    use Queueable;

    public const REASK_SECONDS = 120;

    public const REASK_SIMILARITY = 0.85;

    public const TICKET_WINDOW_MINUTES = 60;

    public function __construct(public readonly ?string $date = null)
    {
        $this->onQueue('ai-batch');
    }

    public function handle(Similarity $similarity, CurrentTenant $tenants): int
    {
        $day = $this->date !== null
            ? CarbonImmutable::parse($this->date)
            : CarbonImmutable::yesterday();

        $computed = 0;

        $tenants->run(null, function () use ($day, $similarity, &$computed) {
            Message::withoutGlobalScopes()
                ->whereNotNull('question')
                ->where('role', 'assistant')
                ->whereIn('status', ['completed', 'truncated'])
                ->whereBetween('updated_at', [$day->startOfDay(), $day->endOfDay()])
                ->orderBy('id')
                ->each(function (Message $reply) use ($day, $similarity, &$computed) {
                    $this->signalsFor($reply, $day, $similarity);

                    $computed++;
                });
        });

        return $computed;
    }

    private function signalsFor(Message $reply, CarbonImmutable $day, Similarity $similarity): void
    {
        $user = User::query()->find($reply->conversation->user_id);

        $reasked = $this->reasked($reply, $similarity);

        $followup = $this->followedUp($reply, $user);

        $escalated = $this->escalated($reply);

        AnswerSignal::query()->updateOrCreate(['message_id' => $reply->id], [
            'conversation_id' => $reply->conversation_id,
            'customer_id' => (int) $user?->customer_id,
            'computed_on' => $day->toDateString(),
            'prompt_version' => $reply->prompt_version,
            'variant' => $reply->experiment_variant,
            'reasked' => $reasked,
            'ticket_followup' => $followup,
            'escalated' => $escalated,
            'resolved' => ! $reasked && ! $followup && ! $escalated && $reply->status === 'completed',
        ]);
    }

    private function reasked(Message $reply, Similarity $similarity): bool
    {
        $next = Message::withoutGlobalScopes()
            ->where('conversation_id', $reply->conversation_id)
            ->where('role', 'user')
            ->where('seq', '>', $reply->seq)
            ->orderBy('seq')
            ->first();

        if ($next === null) {
            return false;
        }

        if ($reply->updated_at->diffInSeconds($next->created_at, absolute: true) > self::REASK_SECONDS) {
            return false;
        }

        try {
            return $similarity->between((string) $next->content, (string) $reply->question) > self::REASK_SIMILARITY;
        } catch (Throwable) {
            return false;                                                          // no embedding model today: the signal is unknown, not positive
        }
    }

    private function followedUp(Message $reply, ?User $user): bool
    {
        if ($user?->customer_id === null) {
            return false;
        }

        return Ticket::query()
            ->where('customer_id', $user->customer_id)
            ->where('source', '!=', 'assistant')
            ->whereBetween('created_at', [$reply->updated_at, $reply->updated_at->copy()->addMinutes(self::TICKET_WINDOW_MINUTES)])
            ->get(['subject', 'body'])
            ->contains(fn (Ticket $ticket) => self::sameTopic((string) $reply->question, $ticket->subject.' '.$ticket->body));
    }

    private function escalated(Message $reply): bool
    {
        return ToolCall::query()
            ->where('conversation_id', $reply->conversation_id)
            ->where('tool', 'open_ticket')
            ->whereIn('decision', ['allowed', 'approved'])
            ->where('created_at', '>=', $reply->created_at)
            ->exists();
    }

    // "On the same topic": at least two words longer than three letters in common. Crude on purpose; the embedding-based version is the exercise.
    public static function sameTopic(string $question, string $ticket): bool
    {
        $words = fn (string $text) => collect(preg_split('/\W+/u', Str::lower($text), -1, PREG_SPLIT_NO_EMPTY))->filter(fn (string $word) => strlen($word) > 3)->unique();

        return $words($question)->intersect($words($ticket))->count() >= 2;
    }
}
