<?php

use App\Ai\Contracts\ChatRequest;
use App\Ai\Privacy\Ner;
use App\Facades\Ai;
use App\Models\ModelCall;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeNer;

// Chapter 34, Topic 5: what the audit trail keeps. This one runs the whole chain, usage log included, against a faked HTTP server.
beforeEach(function () {
    config([
        'ai.providers.hosted' => ['driver' => 'openai-compatible', 'base_url' => 'https://hosted.example.com/v1', 'api_key' => 'sk-test', 'model' => 'hosted-model', 'hosted' => true, 'privacy' => ['redact' => true]],
        'ai.debug.record_requests' => true,
    ]);

    app()->instance(Ner::class, new FakeNer);
})->group('privacy');

it('stores the redacted prompt and the restored answer, sends the redacted prompt, and writes neither the original value nor the vault into a log', function () {
    Http::fake(['hosted.example.com/*' => chatReply('I will write to <EMAIL_1>.')]);

    $logged = [];

    Log::listen(function ($event) use (&$logged) {
        $logged[] = $event->message.json_encode($event->context);
    });

    $response = Ai::chat('hosted')->chat(ChatRequest::make([['role' => 'user', 'content' => 'write to dana@acme.example']], ['conversation_id' => 14], 'support.reply'));

    $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]->body())->implode(' ');

    $stored = json_encode(ModelCall::latest('id')->first()->request);

    expect($response->content)->toBe('I will write to dana@acme.example.')
        ->and($sent)->toContain('<EMAIL_1>')->not->toContain('dana@acme.example')
        ->and($stored)->toContain('<EMAIL_1>')->not->toContain('dana@acme.example')
        ->and(implode(' ', $logged))->not->toContain('dana@acme.example')->not->toContain('vault:');
});

it('records one row per call even when the call was rerouted, under the provider that answered', function () {
    config(['ai.providers.local' => ['driver' => 'openai-compatible', 'base_url' => 'http://local.test/v1', 'api_key' => 'x', 'model' => 'llama', 'privacy' => ['redact' => false]]]);

    app()->instance(Ner::class, tap(new FakeNer, fn ($ner) => $ner->up = false));

    Http::fake(['local.test/*' => chatReply('Answered here.'), 'hosted.example.com/*' => fn () => throw new RuntimeException('a hosted call went out')]);

    Ai::chat('hosted')->chat(ChatRequest::make([['role' => 'user', 'content' => 'write to dana@acme.example']], ['conversation_id' => 14], 'support.reply'));

    $row = ModelCall::latest('id')->first();

    expect(ModelCall::count())->toBe(1)
        ->and($row->provider)->toBe('local')
        ->and(json_encode($row->request))->toContain('dana@acme.example');
});
