<?php

namespace App\Ai\Load;

use App\Ai\Conversations\ConversationStore;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Streaming\ReplyStarter;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Support\Sleep;
use Throwable;

// Chapter 24, Topic 8: N simulated users, each asking the same scripted messages one after another through the Chapter 18 path (ReplyStarter -> the `ai` queue -> AnswerQuestion), all at once.
// Each user waits for their own answer before the next question, the way a person does; the users do not wait for each other. With QUEUE_CONNECTION=sync everything runs inline and there is no concurrency to measure.
final class SimulatedUsers
{
    public function __construct(
        private ReplyStarter $starter,
        private ConversationStore $store,
        private CurrentTenant $tenants,
    ) {}

    /**
     * @param  list<User>  $users
     * @param  list<string>  $messages
     */
    public function run(array $users, array $messages, int $timeoutSeconds, ?Closure $progress = null): SimulationReport
    {
        $started = hrtime(true);

        $state = [];

        foreach ($users as $index => $user) {
            $state[$index] = ['user' => $user, 'conversation' => $this->start($user), 'turn' => 0, 'reply' => null];
        }

        while ($this->unfinished($state, count($messages))) {
            $this->advance($state, $messages, $progress);

            if ($this->elapsed($started) > $timeoutSeconds) {
                break;
            }

            Sleep::for(250)->milliseconds();
        }

        return $this->report($state, count($messages), $this->elapsed($started));
    }

    private function start(User $user): Conversation
    {
        return $this->tenants->run($user->tenant_id, fn () => $this->store->start($user, SupportPrompt::VERSION));
    }

    private function advance(array &$state, array $messages, ?Closure $progress): void
    {
        foreach ($state as $index => $entry) {
            if ($entry['reply'] !== null && ! $this->settled($entry['reply'])) {
                continue;
            }

            if ($entry['turn'] >= count($messages)) {
                continue;
            }

            $user = $entry['user'];

            $state[$index]['turn']++;

            try {
                $reply = $this->tenants->run($user->tenant_id, fn () => $this->starter->start($user, $messages[$entry['turn']], $entry['conversation']));
            } catch (Throwable) {
                $state[$index]['reply'] = null;                                        // a sync queue throws the job's failure at the dispatch; the reply is already marked failed

                continue;
            }

            $state[$index]['reply'] = $reply->id;

            if ($progress !== null) {
                $progress($index + 1, $state[$index]['turn']);
            }
        }
    }

    private function unfinished(array $state, int $turns): bool
    {
        foreach ($state as $entry) {
            if ($entry['turn'] < $turns) {
                return true;
            }

            if ($entry['reply'] !== null && ! $this->settled($entry['reply'])) {
                return true;
            }
        }

        return false;
    }

    private function settled(int $replyId): bool
    {
        $status = Message::withoutGlobalScopes()->whereKey($replyId)->value('status');

        return in_array($status, ['completed', 'failed', 'paused'], true);
    }

    private function report(array $state, int $turns, float $seconds): SimulationReport
    {
        $conversationIds = array_map(fn (array $entry) => $entry['conversation']->id, $state);

        $replies = Message::withoutGlobalScopes()
            ->whereIn('conversation_id', $conversationIds)
            ->whereNotNull('question')
            ->get(['status', 'content']);

        $calls = ModelCall::query()
            ->whereIn('conversation_id', $conversationIds)
            ->where('feature', 'support.reply')
            ->get(['ttft_ms', 'completion_tokens', 'semantic_cache_hit']);

        return new SimulationReport(
            users: count($state),
            turns: $turns,
            seconds: $seconds,
            answered: $replies->where('status', 'completed')->count(),
            failed: $replies->where('status', 'failed')->count(),
            unfinished: count($state) * $turns - $replies->whereIn('status', ['completed', 'failed', 'paused'])->count(),
            ttftMs: $calls->whereNotNull('ttft_ms')->pluck('ttft_ms')->map(fn ($ms) => (int) $ms)->sort()->values()->all(),
            completionTokens: (int) $calls->sum('completion_tokens'),
        );
    }

    private function elapsed(int $started): float
    {
        return (hrtime(true) - $started) / 1_000_000_000;
    }
}
