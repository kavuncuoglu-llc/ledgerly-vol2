<?php

namespace App\Evals;

use App\Models\Message;
use App\Models\ModelCall;
use App\Models\ToolCall;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

// Chapter 29, Topic 8: signals the app already logs mark a reply as a candidate. Chapter 30 adds the judge_fail source from the sampled judge.
final class CandidateCollector
{
    public const SIGNALS = ['thumbs_down', 'citation_reject', 'escalated', 'judge_fail'];

    /** @return Collection<int, array> */
    public function collect(CarbonInterface $since, array $signals): Collection
    {
        $candidates = collect();

        foreach ($signals as $signal) {
            $candidates = $candidates->concat(match ($signal) {
                'thumbs_down' => $this->thumbsDown($since),
                'citation_reject' => $this->citationRejects($since),
                'escalated' => $this->escalations($since),
                'judge_fail' => $this->judgeFails($since),
                default => collect(),
            });
        }

        return $candidates->unique('id')->values();
    }

    private function thumbsDown(CarbonInterface $since): Collection
    {
        $viaFeedback = Message::withoutGlobalScopes()
            ->whereNotNull('question')
            ->where('feedback', 'down')
            ->where('updated_at', '>=', $since)
            ->get();

        return $viaFeedback->map(fn (Message $reply) => $this->candidate($reply, 'thumbs_down'));
    }

    private function citationRejects(CarbonInterface $since): Collection
    {
        $calls = ModelCall::query()
            ->where('feature', 'support.reply')
            ->where('citation_errors', '>', 0)
            ->where('created_at', '>=', $since)
            ->pluck('id');

        return Message::withoutGlobalScopes()
            ->whereNotNull('question')
            ->whereIn('model_call_id', $calls)
            ->get()
            ->map(fn (Message $reply) => $this->candidate($reply, 'citation_reject'));
    }

    private function escalations(CarbonInterface $since): Collection
    {
        $conversations = ToolCall::query()
            ->where('tool', 'open_ticket')
            ->whereIn('decision', ['allowed', 'approved'])
            ->where('created_at', '>=', $since)
            ->pluck('conversation_id')
            ->filter();

        return Message::withoutGlobalScopes()
            ->whereNotNull('question')
            ->whereIn('conversation_id', $conversations)
            ->get()
            ->groupBy('conversation_id')
            ->map(fn (Collection $replies) => $replies->sortBy('seq')->last())
            ->map(fn (Message $reply) => $this->candidate($reply, 'escalated'))
            ->values();
    }

    private function judgeFails(CarbonInterface $since): Collection
    {
        return collect();
    }

    private function candidate(Message $reply, string $signal): array
    {
        return [
            'id' => "{$signal}:{$reply->id}",
            'message_id' => $reply->id,
            'model_call_id' => $reply->model_call_id,
            'signal' => $signal,
            'question' => (string) $reply->question,
            'answer' => (string) $reply->content,
            'retrieved' => $reply->retrievedLabels(),
            'captured_at' => now()->toIso8601String(),
            'status' => 'open',
        ];
    }
}
