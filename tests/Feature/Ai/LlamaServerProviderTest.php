<?php

use App\Ai\AiManager;
use App\Ai\Contracts\Capabilities\SupportsGrammar;
use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Exceptions\ContextTooLong;
use App\Ai\Prompts\TicketClassifyPrompt;
use App\Ai\Schemas\Category;
use App\Ai\Security\Untrusted;
use App\Ai\Support\ClassifyTicket;
use App\Ai\Tokens\LlamaServerTokenCounter;
use App\Ai\Tokens\RatioCounter;
use App\Ai\Tokens\TokenCounter;
use App\Facades\Ai;
use App\Models\ModelCall;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function localIsLlama(): void
{
    config(['ai.providers.local' => '@local-llama']);

    app(AiManager::class)->forget();
}

function llamaCompletion(string $content = 'billing', int $evaluated = 836, int $predicted = 2): array
{
    return ['content' => $content, 'tokens_evaluated' => $evaluated, 'tokens_predicted' => $predicted, 'stop' => true];
}

it('declares llama-server as a second local provider that the key and URL come from the environment for', function () {
    $provider = config('ai.providers.local-llama');

    expect($provider['driver'])->toBe('openai-compatible')
        ->and($provider['native'])->toBe('llama-server')
        ->and($provider['base_url'])->toBe('http://llama.test:8080/v1')                    // LLAMA_URL, forced by phpunit.xml
        ->and($provider['api_key'])->toBe('llama-test-key')                                 // LLAMA_API_KEY: nothing in config/ai.php is a key
        ->and($provider['capabilities'])->toContain('grammar', 'tokenize', 'tools', 'structured')
        ->and(file_get_contents(base_path('config/ai.php')))->not->toContain('llama-test-key');
});

it('keeps Ollama as the local role until AI_LOCAL_PROVIDER says llama', function () {
    expect(config('ai.providers.local'))->toBeArray()
        ->and(config('ai.providers.local.native'))->toBe('ollama')
        ->and(Ai::chat('local')->supports(Capability::Grammar))->toBeFalse();
});

it('makes the local role an alias that nothing above the manager notices', function () {
    localIsLlama();

    Http::fake(['llama.test:8080/v1/chat/completions' => Http::response(chatBody('Fees start after a grace period.', model: 'ledgerly-support'))]);

    $response = Ai::for('support.reply')->chat(ChatRequest::make([['role' => 'user', 'content' => 'late fee?']], [], 'support.reply'));

    expect($response->content)->toBe('Fees start after a grace period.')
        ->and(Ai::model('local'))->toBe('ledgerly-support')
        ->and(Ai::configured('local'))->toBeTrue()
        ->and(ModelCall::sole()->provider)->toBe('local');                              // the role's name, so ai:stats and model_calls read the same on either server

    Http::assertSent(fn (Request $request) => $request->url() === 'http://llama.test:8080/v1/chat/completions'
        && $request->header('Authorization') === ['Bearer llama-test-key']);
});

it('lists the aliased role in ai:providers with the resolved server, and never prints the key', function () {
    localIsLlama();

    Artisan::call('ai:providers');
    $output = Artisan::output();

    expect($output)->toContain('http://llama.test:8080/v1')->toContain('grammar, tokenize')
        ->and($output)->not->toContain('llama-test-key');
});

it('still sends the context window as a request field to a server that is not Ollama', function () {
    localIsLlama();

    Http::fake(['*/chat/completions' => Http::response(chatBody('ok'))]);

    Ai::chat('local')->withContextWindow(8192)->chat(ChatRequest::make([['role' => 'user', 'content' => 'hi']], [], 'support.reply'));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/chat/completions') && $request['num_ctx'] === 8192);
});

it('surfaces llama-server\'s refusal of an oversized prompt as ContextTooLong', function () {
    localIsLlama();

    Http::fake(['*' => Http::response(['error' => ['code' => 400, 'type' => 'exceed_context_size_error', 'message' => 'request (837 tokens) exceeds the available context size (512 tokens), try increasing it']], 400)]);

    Ai::chat('local')->chat(ChatRequest::make([['role' => 'user', 'content' => 'hi']], [], 'support.reply'));
})->throws(ContextTooLong::class);

describe('grammar', function () {
    it('posts the grammar to /completion and returns the text, logging the call', function () {
        localIsLlama();

        Http::fake(['llama.test:8080/completion' => Http::response(llamaCompletion('billing'))]);

        $model = Ai::for('ticket.classify');

        expect($model)->toBeInstanceOf(SupportsGrammar::class)
            ->and($model->supports(Capability::Grammar))->toBeTrue();

        $label = $model->completeWithGrammar('PROMPT', TicketClassifyPrompt::GBNF, 4, ['temperature' => 0, 'top_p' => 1.0, 'seed' => 42, 'ignored' => 'x'], 'ticket.classify');

        expect($label)->toBe('billing');

        Http::assertSent(fn (Request $request) => $request->url() === 'http://llama.test:8080/completion'
            && $request['prompt'] === 'PROMPT'
            && $request['grammar'] === TicketClassifyPrompt::GBNF
            && $request['n_predict'] === 4
            && $request['temperature'] === 0
            && $request['seed'] === 42
            && ! isset($request['ignored'])
            && $request->header('Authorization') === ['Bearer llama-test-key']);

        $call = ModelCall::sole();

        expect($call->feature)->toBe('ticket.classify')
            ->and($call->provider)->toBe('local')
            ->and($call->model)->toBe('ledgerly-support')
            ->and($call->prompt_tokens)->toBe(2)                                           // PROMPT, by the ratio counter
            ->and($call->completion_tokens)->toBe(2);
    });

    it('refuses a grammar on a server that has none', function () {
        Ai::chat('local')->completeWithGrammar('PROMPT', TicketClassifyPrompt::GBNF);
    })->throws(LogicException::class, 'no grammar endpoint');

    it('keeps the grammar equal to the labels the classifier knows', function () {
        $alternatives = array_map(fn (string $label) => '"'.$label.'"', TicketClassifyPrompt::LABELS);

        expect(TicketClassifyPrompt::GBNF)->toBe('root ::= '.implode(' | ', $alternatives))
            ->and(array_map(fn (Category $category) => $category->value, Category::cases()))->toBe(TicketClassifyPrompt::LABELS);
    });

    it('renders the few-shot turns and the message through the Llama 3 template, ready for /completion', function () {
        $prompt = TicketClassifyPrompt::rendered('The export is broken');

        expect($prompt)->toStartWith('<|start_header_id|>system<|end_header_id|>'."\n\n".'You classify Ledgerly support messages')
            ->and($prompt)->toContain('<|eot_id|><|start_header_id|>user<|end_header_id|>'."\n\n".Untrusted::wrap('The export is broken', 'ticket').'<|eot_id|>')
            ->and($prompt)->toEndWith('<|start_header_id|>assistant<|end_header_id|>'."\n\n")
            ->and($prompt)->not->toContain('<|begin_of_text|>');                          // the server adds the BOS token
    });

    it('classifies with the grammar when the server has one: no schema call is made', function () {
        localIsLlama();

        Http::fake(['llama.test:8080/completion' => Http::response(llamaCompletion('bug'))]);

        $category = app(ClassifyTicket::class)('The PDF export does nothing');

        expect($category)->toBe(Category::Bug);

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/chat/completions'));
    });

    it('samples with the profile temperature so the votes can differ', function () {
        localIsLlama();

        Http::fake(['llama.test:8080/completion' => Http::response(llamaCompletion('billing'))]);

        app(ClassifyTicket::class)->sample('Why was I charged?', 3);

        Http::assertSent(fn (Request $request) => $request['temperature'] === 0.7 && $request['top_p'] === 0.9);
    });

    it('falls back to the schema route when the grammar reply was cut short', function () {
        localIsLlama();

        Http::fake([
            'llama.test:8080/completion' => Http::response(llamaCompletion('acco')),
            'llama.test:8080/v1/chat/completions' => Http::response(chatBody(ticketJson('account'))),
        ]);

        expect(app(ClassifyTicket::class)('I cannot log in'))->toBe(Category::Account);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/chat/completions'));
    });

    it('uses the schema route alone on Ollama, which has no grammar endpoint', function () {
        Http::fake(['*/chat/completions' => Http::response(chatBody(ticketJson('howto')))]);

        expect(app(ClassifyTicket::class)('How do I add a team member?'))->toBe(Category::HowTo);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/completion') && ! str_contains($request->url(), 'chat'));
    });
});

describe('the server token counter', function () {
    it('asks /tokenize and counts the tokens it returns, with the key', function () {
        Http::fake(['llama.test:8080/tokenize' => Http::response(['tokens' => [36120, 674, 20213, 16, 374, 83893, 555, 220, 2919, 13]])]);

        $counter = new LlamaServerTokenCounter('http://llama.test:8080/v1', 'llama-test-key');

        expect($counter->count('Invoice #4471 is overdue by 30 days.'))->toBe(10)
            ->and($counter->count('Invoice #4471 is overdue by 30 days.'))->toBe(10)         // the same text is not asked twice
            ->and($counter->count(''))->toBe(0);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://llama.test:8080/tokenize'
            && $request['content'] === 'Invoice #4471 is overdue by 30 days.'
            && $request->header('Authorization') === ['Bearer llama-test-key']);
    });

    it('falls back to the estimate, once and quietly, when the server cannot be asked', function () {
        Log::spy();
        Http::fake(['*' => Http::failedConnection()]);

        $counter = new LlamaServerTokenCounter('http://llama.test:8080/v1', null);

        $estimate = (new RatioCounter)->count('Invoice #4471 is overdue by 30 days.');

        expect($counter->count('Invoice #4471 is overdue by 30 days.'))->toBe($estimate)
            ->and($counter->count('Another sentence'))->toBe((new RatioCounter)->count('Another sentence'));

        Http::assertSentCount(1);                                                           // the second count did not wait for a second timeout
        Log::shouldHaveReceived('warning')->once();
    });

    it('is the counter the container hands out when AI_TOKEN_COUNTER=server and the local role is llama-server', function () {
        localIsLlama();
        config(['ai.token_counter' => 'server']);
        app()->forgetInstance(TokenCounter::class);

        expect(app(TokenCounter::class))->toBeInstanceOf(LlamaServerTokenCounter::class);

        config(['ai.providers.local' => ['driver' => 'openai-compatible', 'base_url' => 'http://ollama.test/v1', 'model' => 'm', 'native' => 'ollama'], 'ai.providers.local-llama' => null]);
        app()->forgetInstance(TokenCounter::class);

        expect(app(TokenCounter::class))->toBeInstanceOf(RatioCounter::class);               // nothing to ask: the estimate stays
    });
});
