<?php

namespace App\Evals;

use App\Ai\Conversations\ConversationStore;
use App\Ai\Prompts\SupportPrompt;
use App\Jobs\AnswerQuestion;
use App\Models\Customer;
use App\Models\HelpChunk;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;

// Chapter 29, Figure 29-9: the whole pipeline, synchronously, for one question asked as one seeded customer. It is the only place the app runs inference inside a request, so the route that calls it exists only when AI_EVAL_ENABLED is true.
final class EvalAnswerer
{
    public function __construct(private ConversationStore $store, private CurrentTenant $tenants) {}

    public function answer(string $customerKey, string $question): EvalAnswer
    {
        $user = $this->ownerOf($customerKey);

        $started = hrtime(true);

        $reply = $this->tenants->run($user->tenant_id, fn () => $this->ask($user, $question));

        $reply = $this->tenants->run($user->tenant_id, fn () => $reply->fresh());

        return new EvalAnswer(
            answer: (string) $reply->content,
            behavior: $this->tenants->run($user->tenant_id, fn () => EvalBehavior::of($reply)),
            citations: $this->citationsIn((string) $reply->content, $reply->retrieved ?? []),
            retrieved: $reply->retrievedLabels(),
            messageId: $reply->id,
            elapsedMs: (int) ((hrtime(true) - $started) / 1_000_000),
            promptVersion: SupportPrompt::VERSION,
            sources: $this->sourcesOf($reply->retrieved ?? []),
            inputTokens: (int) ModelCall::query()->where('conversation_id', $reply->conversation_id)->sum('prompt_tokens'),
        );
    }

    public function ownerOf(string $customerKey): User
    {
        $customer = $this->customer($customerKey);

        $owner = $customer->owner()->first();

        if ($owner === null) {
            throw new ModelNotFoundException("Fixture customer '{$customerKey}' has no owner.");
        }

        return $owner;
    }

    public function customer(string $customerKey): Customer
    {
        $limit = (int) config('ai.eval.fixture_customers', 40);

        $customer = Customer::query()
            ->where('id', '<=', $limit)                                            // the seeded fixtures only: nothing created after the seed can be asked as
            ->orderBy('id')
            ->get()
            ->first(fn (Customer $customer) => self::fixtureKey($customer) === $customerKey);

        if ($customer === null) {
            throw new ModelNotFoundException("No fixture customer '{$customerKey}'.");
        }

        return $customer;
    }

    public static function fixtureKey(Customer $customer): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', strtok($customer->name, ' ')));
    }

    private function ask(User $user, string $question): Message
    {
        $conversation = $this->store->start($user, SupportPrompt::VERSION);

        $this->store->append($conversation, ['role' => 'user', 'content' => $question]);

        $reply = $this->store->placeholder($conversation, $question);

        AnswerQuestion::dispatchSync($reply->id);                                  // jobs carry ids, never text

        return $reply;
    }

    // The passages the model was shown, for the nightly rubric judge: it grades the answer against these.
    private function sourcesOf(array $retrieved): array
    {
        $text = HelpChunk::query()
            ->whereIn('id', collect($retrieved)->pluck('id'))
            ->pluck('content', 'id');

        return collect($retrieved)
            ->map(fn (array $row) => ['label' => $row['eval_label'], 'text' => (string) ($text[$row['id']] ?? '')])
            ->all();
    }

    private function citationsIn(string $content, array $retrieved): array
    {
        $byLabel = collect($retrieved)->pluck('eval_label', 'label');

        preg_match_all('/\[([^\]]+)\]/u', $content, $matches);

        return collect($matches[1])
            ->map(fn (string $label) => $byLabel[$label] ?? $label)                // a label the model made up stays as it was written, so the gate can see it was not retrieved
            ->unique()
            ->values()
            ->all();
    }
}
