<?php

use App\Ai\Conversations\ConversationStore;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Support\SupportAssistant;
use App\Facades\Ai;
use App\Jobs\AnswerQuestion;
use App\Models\Message;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Cache;

// Chapter 35, Topic 6 and Figure 35-6: after an outage the queue must not drain into the provider, repeat what it already paid for, or answer people who have left.
beforeEach(function () {
    [$this->user, $this->invoice, $this->customer] = customerWithInvoice();
});

function replyPlaceholder($user, string $question = 'How do I export my invoices?'): Message
{
    $conversation = conversationFor($user);

    return app(ConversationStore::class)->placeholder($conversation, $question);
}

describe('rate limits by provider', function () {
    it('passes every answer job through a limiter named for its provider, and backs a 429 off instead of failing', function () {
        $middleware = (new AnswerQuestion(1))->middleware();

        expect($middleware[0])->toBeInstanceOf(RateLimited::class)
            ->and($middleware[1])->toBeInstanceOf(ThrottlesExceptions::class);

        $property = new ReflectionProperty($middleware[0], 'limiterName');

        expect($property->getValue($middleware[0]))->toBe('ai:'.Ai::providerFor('support.reply'));
    });

    it('registers a limiter for every provider, and only the ones with a limit configured limit', function () {
        config()->set('ai.limits.frontier.requests_per_minute', 2);

        $limiter = app(CacheRateLimiter::class)->limiter('ai:frontier');

        $limit = $limiter(new stdClass);

        expect($limit->maxAttempts)->toBe(2)
            ->and(app(CacheRateLimiter::class)->limiter('ai:local')(new stdClass))->toBeInstanceOf(Unlimited::class);
    });

    it('releases a job that is over its provider limit instead of running it', function () {
        config()->set('ai.limits.local.requests_per_minute', 1);

        $middleware = (new AnswerQuestion(1))->middleware()[0];

        $queued = fn () => new class
        {
            public bool $released = false;

            public function release($delay = 0): void
            {
                $this->released = true;
            }

            public function attempts(): int
            {
                return 1;
            }
        };

        $ran = 0;

        $first = $queued();

        $second = $queued();

        $middleware->handle($first, function () use (&$ran) {
            $ran++;
        });

        $middleware->handle($second, function () use (&$ran) {
            $ran++;
        });

        expect($ran)->toBe(1)
            ->and($first->released)->toBeFalse()
            ->and($second->released)->toBeTrue();
    });
});

describe('the freshness check', function () {
    it('answers a question the customer left long ago from a template, with no model call', function () {
        Ai::fake();

        $reply = replyPlaceholder($this->user);

        $reply->forceFill(['created_at' => now()->subHours(3)])->saveQuietly();

        AnswerQuestion::dispatchSync($reply->id);

        $reply = Message::withoutGlobalScopes()->find($reply->id);

        expect($reply->status)->toBe('completed')
            ->and($reply->content)->toBe(SupportPrompt::delayedMessage());

        Ai::assertNothingSent();
    });

    it('answers a question that is still fresh', function () {
        Ai::fake()->for('support.reply')->respondWith('Open Invoices and choose Export.');

        $reply = replyPlaceholder($this->user);

        AnswerQuestion::dispatchSync($reply->id);

        expect(Message::withoutGlobalScopes()->find($reply->id)->content)->toContain('Export');
    });

    it('can be switched off', function () {
        config()->set('ai.replies.freshness_minutes', 0);

        Ai::fake()->for('support.reply')->respondWith('Open Invoices and choose Export.');

        $reply = replyPlaceholder($this->user);

        $reply->forceFill(['created_at' => now()->subHours(3)])->saveQuietly();

        AnswerQuestion::dispatchSync($reply->id);

        expect(Message::withoutGlobalScopes()->find($reply->id)->content)->toContain('Export');
    });
});

describe('cached steps', function () {
    it('remembers the classification and the retrieval of a reply, so a retry resumes', function () {
        config()->set('ai.router.enabled', true);

        fakeSharedRetrieval([retrievedChunk('Open Invoices and choose Export.')]);

        Ai::fake()->for('ticket.classify')->respondWithJson(json_decode(ticketJson('howto'), true), times: 6);

        Ai::fake()->for('support.reply')->respondToEverythingWith('Open Invoices and choose Export.');

        $reply = replyPlaceholder($this->user);

        $run = fn () => app(SupportAssistant::class)->answer($this->user, 'How do I export my invoices?', $reply->conversation, reply: $reply);

        $run();

        $classifications = count(array_filter(Ai::fake()->sent(), fn ($call) => $call['request']->feature === 'ticket.classify'));

        $reply->update(['status' => 'pending', 'content' => null]);

        $run();

        $again = count(array_filter(Ai::fake()->sent(), fn ($call) => $call['request']->feature === 'ticket.classify'));

        expect($classifications)->toBeGreaterThan(0)
            ->and($again)->toBe($classifications);

        expect(Cache::has("classify:{$reply->id}"))->toBeTrue()
            ->and(Cache::has("retrieve:{$reply->id}"))->toBeTrue();
    });
});

describe('the queue', function () {
    it('gives the ai queue a deliberate number of workers, not an autoscaling maximum', function () {
        $supervisor = config('horizon.defaults.ai-interactive');

        expect($supervisor['queue'])->toBe(['ai'])
            ->and($supervisor['balance'])->toBe('simple')
            ->and($supervisor['processes'])->toBe((int) env('AI_CONCURRENCY', 2))
            ->and(config('horizon.defaults.ai-batch.maxProcesses'))->toBe(2);
    });
});
