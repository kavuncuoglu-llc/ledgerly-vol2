<?php

use App\Ai\Conversations\ConversationStore;
use App\Ai\Conversations\NoTenant;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Tokens\RatioCounter;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->store = app(ConversationStore::class);
    $this->user = User::factory()->create();
    $this->conversation = conversationFor($this->user);
});

function words(int $tokens, string $word = 'x'): string
{
    return str_repeat($word, (int) floor($tokens * 3.6));
}

describe('start and append', function () {
    it('starts an open conversation for the user in their tenant on the current prompt version', function () {
        $conversation = $this->store->start($this->user, SupportPrompt::VERSION);

        expect($conversation->user_id)->toBe($this->user->id)
            ->and($conversation->tenant_id)->toBe($this->user->tenant_id)
            ->and($conversation->status)->toBe('open')
            ->and($conversation->prompt_version)->toBe(SupportPrompt::VERSION)
            ->and($conversation->summary)->toBeNull()
            ->and($conversation->summary_through_seq)->toBe(0);
    });

    it('cannot start a conversation for someone who belongs to no tenant', function () {
        $this->store->start(User::factory()->support()->create(), SupportPrompt::VERSION);
    })->throws(NoTenant::class);

    it('assigns seq in order and counts tokens at write time', function () {
        $first = $this->store->append($this->conversation, ['role' => 'user', 'content' => words(100)]);
        $second = $this->store->append($this->conversation, ['role' => 'assistant', 'content' => words(40)]);

        expect([$first->seq, $second->seq])->toBe([1, 2])
            ->and($first->tokens)->toBe((new RatioCounter)->count(words(100)))
            ->and($second->tokens)->toBe(40)
            ->and($first->tenant_id)->toBe($this->user->tenant_id);
    });

    it('stores tool_calls and tool_call_id verbatim and counts the calls as tokens', function () {
        $calls = [['id' => 'call_8a1', 'type' => 'function', 'function' => ['name' => 'list_invoices', 'arguments' => '{"status":null,"months":6}']]];

        $assistant = $this->store->append($this->conversation, ['role' => 'assistant', 'content' => null, 'tool_calls' => $calls]);
        $tool = $this->store->append($this->conversation, ['role' => 'tool', 'tool_call_id' => 'call_8a1', 'content' => 'invoices[14]{...}']);

        expect($assistant->fresh()->tool_calls)->toBe($calls)
            ->and($assistant->content)->toBeNull()
            ->and($assistant->tokens)->toBeGreaterThan(0)
            ->and($tool->fresh()->tool_call_id)->toBe('call_8a1');
    });

    it('refuses two messages with the same seq', function () {
        $this->store->append($this->conversation, ['role' => 'user', 'content' => 'a']);

        Message::create(['conversation_id' => $this->conversation->id, 'tenant_id' => $this->user->tenant_id, 'seq' => 1, 'role' => 'user', 'content' => 'b', 'tokens' => 1]);
    })->throws(QueryException::class);

    it('links an assistant turn to the model call that produced it', function () {
        $message = $this->store->append($this->conversation, ['role' => 'assistant', 'content' => 'ok', 'model_call_id' => 77]);

        expect($message->fresh()->model_call_id)->toBe(77);
    });
});

describe('history', function () {
    it('returns everything, in order, when it fits', function () {
        addExchange($this->conversation, 'Why is my March invoice late?', 'It is 18 days overdue.');
        addExchange($this->conversation, 'What about the April one?', 'That one is not due yet.');

        $history = $this->store->history($this->conversation, 4000);

        expect(array_column($history, 'role'))->toBe(['user', 'assistant', 'user', 'assistant'])
            ->and(array_column($history, 'content'))->toBe(['Why is my March invoice late?', 'It is 18 days overdue.', 'What about the April one?', 'That one is not due yet.']);
    });

    it('drops the oldest whole exchanges, never a single message, and keeps the pinned first one', function () {
        foreach (range(1, 8) as $n) {
            addExchange($this->conversation, "question {$n} ".words(40), "answer {$n} ".words(40));
        }

        $history = $this->store->history($this->conversation, 270);   // room for the pinned exchange and two more

        $questions = collect($history)->where('role', 'user')->pluck('content')->map(fn (string $c) => substr($c, 0, 10))->all();

        expect($questions)->toBe(['question 1', 'question 7', 'question 8'])
            ->and(count($history))->toBe(6);                          // each kept exchange is a question and its answer
    });

    it('trims by stored tokens, not by message count', function () {
        addExchange($this->conversation, 'short question', 'short');
        addExchange($this->conversation, 'a'.words(500), 'b'.words(500));
        addExchange($this->conversation, 'last question', 'last answer');

        $history = $this->store->history($this->conversation, 100);

        expect(collect($history)->pluck('content')->join(' '))->not->toContain('xxxx')
            ->and(count($history))->toBe(4);                          // pinned first and the last exchange; the 1,000-token one is out
    });

    it('can leave the first exchange unpinned', function () {
        config(['ai.history.pin_first' => false]);

        foreach (range(1, 6) as $n) {
            addExchange($this->conversation, "question {$n} ".words(40), "answer {$n} ".words(40));
        }

        $questions = collect($this->store->history($this->conversation, 180))->where('role', 'user')->pluck('content')->map(fn (string $c) => substr($c, 0, 10))->all();

        expect($questions)->toBe(['question 5', 'question 6']);
    });

    it('keeps a tool exchange together: the request, the result and the answer, ids intact', function () {
        addExchange($this->conversation, 'How are my invoices looking?', 'You have 14 invoices.', ['call_8a1', 'list_invoices', ['status' => null, 'months' => 6], 'invoices[14]{number,status}: ...']);
        addExchange($this->conversation, 'second '.words(60), 'second answer');

        $history = $this->store->history($this->conversation, 4000);

        expect(array_column($history, 'role'))->toBe(['user', 'assistant', 'tool', 'assistant', 'user', 'assistant'])
            ->and($history[1]['tool_calls'][0]['id'])->toBe('call_8a1')
            ->and($history[1]['content'])->toBeNull()
            ->and($history[2]['tool_call_id'])->toBe('call_8a1')
            ->and($history[2]['content'])->toBe('invoices[14]{number,status}: ...');
    });

    it('never sends a tool message without the assistant turn that asked for it', function () {
        foreach (range(1, 5) as $n) {
            addExchange($this->conversation, "q{$n} ".words(30), "a{$n}", ["call_{$n}", 'list_invoices', ['status' => null], words(30)]);
        }

        foreach ([120, 200, 300, 400, 600] as $budget) {
            $history = $this->store->history($this->conversation, $budget);

            $requested = collect($history)->flatMap(fn (array $m) => collect($m['tool_calls'] ?? [])->pluck('id'))->all();
            $answered = collect($history)->where('role', 'tool')->pluck('tool_call_id')->all();

            expect($answered)->toBe($requested);
        }
    });

    it('leaves out an exchange that never got an answer', function () {
        addExchange($this->conversation, 'answered', 'yes');

        $this->store->append($this->conversation, ['role' => 'user', 'content' => 'the model server was down for this one']);

        expect(collect($this->store->history($this->conversation, 4000))->pluck('content')->all())->toBe(['answered', 'yes']);
    });

    it('leaves out an exchange whose tool call was never answered', function () {
        $this->store->append($this->conversation, ['role' => 'user', 'content' => 'refund please']);
        $this->store->append($this->conversation, ['role' => 'assistant', 'content' => 'done', 'tool_calls' => [['id' => 'call_9', 'type' => 'function', 'function' => ['name' => 'issue_refund', 'arguments' => '{}']]]]);

        expect($this->store->history($this->conversation, 4000))->toBe([]);
    });

    it('sends only what the summary does not cover, plus the pinned first exchange, and reserves room for the summary', function () {
        foreach (range(1, 6) as $n) {
            addExchange($this->conversation, "question {$n}", "answer {$n}");
        }

        $this->conversation->update(['summary' => 'Problem: late fee on INV-4471.', 'summary_through_seq' => 8]);   // covers exchanges 1 to 4

        $history = $this->store->history($this->conversation->fresh(), 4000);

        expect(collect($history)->where('role', 'user')->pluck('content')->all())->toBe(['question 1', 'question 5', 'question 6']);

        $summaryTokens = (new RatioCounter)->count("Earlier in this conversation:\nProblem: late fee on INV-4471.");

        $tight = $this->store->history($this->conversation->fresh(), $summaryTokens + 8);

        expect(collect($tight)->where('role', 'user')->pluck('content')->all())->toBe(['question 1']);
    });

    it('returns nothing for an empty conversation', function () {
        expect($this->store->history($this->conversation, 4000))->toBe([]);
    });
});

describe('what the summary job asks the store', function () {
    beforeEach(function () {
        foreach (range(1, 8) as $n) {
            addExchange($this->conversation, "question {$n} ".words(40), "answer {$n} ".words(40));
        }
    });

    it('needs a summary only when the unsummarized exchanges outgrow the allowance', function () {
        expect($this->store->needsSummary($this->conversation, 4000))->toBeFalse()
            ->and($this->store->needsSummary($this->conversation, 400))->toBeTrue();
    });

    it('stops counting what the summary already covers', function () {
        $this->conversation->update(['summary' => 'x', 'summary_through_seq' => 12]);

        expect($this->store->needsSummary($this->conversation->fresh(), 400))->toBeFalse();
    });

    it('cuts at the end of the exchange before the last four', function () {
        expect($this->store->cutoffSeq($this->conversation, 4))->toBe(8)
            ->and($this->store->cutoffSeq($this->conversation, 2))->toBe(12)
            ->and($this->store->cutoffSeq($this->conversation, 8))->toBe(0);
    });

    it('hands over the exchanges to summarize without the pinned first one', function () {
        $between = $this->store->exchangesBetween($this->conversation, 0, $this->store->cutoffSeq($this->conversation, 4));

        expect($between->map(fn ($exchange) => [$exchange->firstSeq(), $exchange->lastSeq()])->all())->toBe([[3, 4], [5, 6], [7, 8]]);
    });
});
