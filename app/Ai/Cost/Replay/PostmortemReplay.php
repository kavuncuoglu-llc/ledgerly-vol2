<?php

namespace App\Ai\Cost\Replay;

use App\Ai\Cost\CallCost;
use App\Ai\Cost\PriceMap;
use App\Ai\Prompts\SupportPrompt;
use App\Models\ModelCall;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

// Chapter 35, Topic 9: fake usage rows that reproduce each postmortem at small scale, so ai:cost-audit can be shown (and CI can check) that every detector sees its own signature. Nothing here calls a model.
// A week of ordinary traffic goes in first (sixty conversations: a local classification and two answers on the frontier role, 85% resolved), then the postmortem's rows on top. Every row it writes has variant = 'cost-replay'.
final class PostmortemReplay
{
    public const SCENARIOS = ['healthy', 'loop', 'context', 'cache', 'drain', 'anonymous'];

    public const TAG = 'cost-replay';

    private const ANSWER_VERSION = SupportPrompt::VERSION;

    private ?User $user = null;

    private int $sequence = 0;

    public function __construct(private PriceMap $prices) {}

    public function run(string $scenario, ?CarbonImmutable $now = null): int
    {
        if (! in_array($scenario, self::SCENARIOS, true)) {
            throw new InvalidArgumentException('The scenarios are '.implode(', ', self::SCENARIOS).'.');
        }

        $now ??= CarbonImmutable::now();

        DB::transaction(function () use ($scenario, $now) {
            $this->wipe();

            $this->baseline($now);

            if ($scenario !== 'healthy') {
                $this->{$scenario}($now);
            }
        });

        return DB::table('model_calls')->where('variant', self::TAG)->count();
    }

    public function wipe(): int
    {
        $conversations = DB::table('conversations')->where('title', 'like', self::TAG.'%')->pluck('id');

        DB::table('triage_runs')->where('event_id', 'like', self::TAG.'-%')->delete();

        DB::table('answer_signals')->whereIn('conversation_id', $conversations)->delete();

        DB::table('messages')->whereIn('conversation_id', $conversations)->delete();

        DB::table('conversations')->whereIn('id', $conversations)->delete();

        return DB::table('model_calls')->where('variant', self::TAG)->delete();
    }

    private function baseline(CarbonImmutable $now): void
    {
        for ($i = 0; $i < 60; $i++) {
            $conversation = $this->conversation($now->subDays($i % 6)->subHours($i % 5));

            $job = "replay-job-baseline-{$i}";

            $this->call($conversation, 'ticket.classify', 'local', 'llama3.1:8b', 300, 20, jobUuid: $job);

            $this->call($conversation, 'support.reply', 'frontier', $this->frontierModel(), 3_500, 200, cached: 2_400, version: self::ANSWER_VERSION, jobUuid: $job);

            $this->call($conversation, 'support.reply', 'frontier', $this->frontierModel(), 3_500, 200, cached: 2_400, version: self::ANSWER_VERSION, jobUuid: $job);

            $this->outcome($conversation, resolved: $i % 7 !== 0);

            DB::table('triage_runs')->insert(['event_id' => self::TAG.'-'.$i, 'ticket_id' => 0, 'status' => $i % 6 === 0 ? 'human' : 'automated', 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    // The agent that never stopped: twelve investigations of forty-one rounds, each carrying every earlier failed attempt, with no cache hit and no resolution.
    private function loop(CarbonImmutable $now): void
    {
        for ($i = 0; $i < 12; $i++) {
            $conversation = $this->conversation($now->subDays($i % 3)->subHours(1), 'loop');

            for ($round = 1; $round <= 41; $round++) {
                $this->call($conversation, 'triage.investigate', 'frontier', $this->frontierModel(), 3_000 + $round * 225, 150, version: 'investigate-v7');
            }

            $this->outcome($conversation, resolved: false);
        }
    }

    // Fifty thousand tokens of context for a password reset: ten chunks of about five thousand tokens attached to every question.
    private function context(CarbonImmutable $now): void
    {
        for ($i = 0; $i < 25; $i++) {
            $conversation = $this->conversation($now->subDays($i % 4), 'context');

            $job = "replay-job-context-{$i}";

            $this->call($conversation, 'support.reply', 'frontier', $this->frontierModel(), 52_000, 220, cached: 2_400, version: 'chunks-v2', jobUuid: $job);

            $this->call($conversation, 'support.reply', 'frontier', $this->frontierModel(), 52_000, 220, cached: 2_400, version: 'chunks-v2', jobUuid: $job);

            $this->outcome($conversation, resolved: $i % 3 !== 0);
        }
    }

    // The prefix that never cached: one new line at the top of the system message, and the cached share of every call after the release falls to nothing.
    private function cache(CarbonImmutable $now): void
    {
        for ($i = 0; $i < 40; $i++) {
            $conversation = $this->conversation($now->subDays(1 + $i % 2), 'cache');

            $job = "replay-job-cache-{$i}";

            $this->call($conversation, 'support.reply', 'frontier', $this->frontierModel(), 3_500, 200, cached: 0, version: 'v8', jobUuid: $job);

            $this->call($conversation, 'support.reply', 'frontier', $this->frontierModel(), 3_500, 200, cached: 0, version: 'v8', jobUuid: $job);

            $this->outcome($conversation, resolved: true);
        }
    }

    // The backlog that cleared itself too fast: thirty jobs paid for three times each, and ten answers written three hours after the question.
    private function drain(CarbonImmutable $now): void
    {
        for ($i = 0; $i < 30; $i++) {
            $conversation = $this->conversation($now->subHours(2), 'drain');

            $job = "replay-job-drain-{$i}";

            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $this->call($conversation, 'support.reply', 'frontier', $this->frontierModel(), 3_500, 200, cached: 2_400, version: self::ANSWER_VERSION, jobUuid: $job, attempt: $attempt);
            }

            $this->outcome($conversation, resolved: false, answeredLate: $i < 10);
        }
    }

    // Denial of wallet: the public widget answering long prompts with long answers, on the frontier role, with no conversation behind any of it.
    private function anonymous(CarbonImmutable $now): void
    {
        for ($i = 0; $i < 400; $i++) {
            $this->call(null, 'widget.ask', 'frontier', $this->frontierModel(), 3_000, 1_500, version: 'widget-v1', at: $now->subHours($i % 20));
        }
    }

    private function conversation(CarbonImmutable $at, string $label = 'baseline'): int
    {
        $user = $this->user();

        return DB::table('conversations')->insertGetId([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'title' => self::TAG.' '.$label,
            'status' => 'closed',
            'prompt_version' => self::ANSWER_VERSION,
            'summary_through_seq' => 0,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function call(?int $conversation, string $feature, string $provider, string $model, int $input, int $output, int $cached = 0, ?string $version = null, ?string $jobUuid = null, ?int $attempt = null, ?CarbonImmutable $at = null): void
    {
        $at ??= CarbonImmutable::parse(DB::table('conversations')->where('id', $conversation)->value('created_at'));

        $price = $this->prices->for($provider, $model);

        $row = new ModelCall(['prompt_tokens' => $input, 'completion_tokens' => $output, 'cached_input_tokens' => $cached, 'cache_write_tokens' => 0]);

        DB::table('model_calls')->insert([
            'feature' => $feature,
            'provider' => $provider,
            'model' => $model,
            'prompt_tokens' => $input,
            'completion_tokens' => $output,
            'finish_reason' => 'stop',
            'latency_ms' => 900,
            'cost_micros' => CallCost::microUsd($row, $price),
            'conversation_id' => $conversation,
            'prompt_version' => $version,
            'cached_input_tokens' => $cached,
            'cache_write_tokens' => 0,
            'job_uuid' => $jobUuid,
            'attempt' => $attempt,
            'trace_id' => md5('replay-trace-'.++$this->sequence),
            'variant' => self::TAG,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function outcome(int $conversation, bool $resolved, bool $answeredLate = false): void
    {
        $row = DB::table('conversations')->where('id', $conversation)->first();

        $asked = CarbonImmutable::parse($row->created_at);

        $messageId = DB::table('messages')->insertGetId([
            'conversation_id' => $conversation,
            'tenant_id' => $row->tenant_id,
            'seq' => 1,
            'role' => 'assistant',
            'status' => 'completed',
            'content' => 'Replayed answer.',
            'question' => 'Replayed question.',
            'tokens' => 4,
            'model_call_id' => DB::table('model_calls')->where('conversation_id', $conversation)->max('id'),
            'created_at' => $asked,
            'updated_at' => $answeredLate
                ? $asked->addHours(3)
                : $asked->addSeconds(20),
        ]);

        DB::table('answer_signals')->insert([
            'message_id' => $messageId,
            'conversation_id' => $conversation,
            'customer_id' => $this->user()->customer_id ?? 0,
            'computed_on' => $asked->toDateString(),
            'resolved' => $resolved,
            'escalated' => ! $resolved,
            'created_at' => $asked,
            'updated_at' => $asked,
        ]);
    }

    private function user(): User
    {
        return $this->user ??= User::query()->whereNotNull('tenant_id')->whereNotNull('customer_id')->orderBy('id')->first()
            ?? User::factory()->owner()->create();
    }

    private function frontierModel(): string
    {
        return (string) (config('ai.providers.frontier.model') ?: 'illustrative-frontier');
    }
}
