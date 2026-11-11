<?php

use App\Ai\Context\ContextAssembler;
use App\Ai\Prompts\SupportPrompt;
use App\Models\Conversation;
use App\Models\ErasureRequest;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\TrainingCorpusSeeder;
use Tests\Support\TrainingCorpus;

// Chapter 40, Build: the corpus the pipeline runs on, and the review file that was written from it.
beforeEach(function () {
    $this->seed();

    chunkRow(['heading' => 'Late fees › How the fee is calculated', 'content' => 'The fee is 1.5% of the outstanding balance per month, prorated by day.']);

    $this->seeder = TrainingCorpus::build(10);
});

afterEach(fn () => TrainingCorpus::cleanUp());

it('is not part of the default seed, so the earlier chapters see the app as they did', function () {
    expect(file_get_contents(base_path('database/seeders/DatabaseSeeder.php')))->not->toContain('TrainingCorpusSeeder');
});

it('writes the review file that ships with the repository, so the file and the seeder cannot drift apart', function () {
    $committed = json_decode(file_get_contents(base_path('storage/samples/billing-v1.review.json')), true);

    expect($this->seeder->reviewFile())->toBe($committed);
});

it('builds 240 conversations in the app\'s own shape, with the flaws the pipeline exists to find', function () {
    $replies = Message::withoutGlobalScopes()->where('role', 'assistant')->whereNotNull('question')->where('id', '>', 0)->whereHas('conversation', fn ($q) => $q->withoutGlobalScopes()->where('id', '>=', TrainingCorpusSeeder::FIRST_ID))->get();

    $text = $replies->pluck('content')->implode("\n").$replies->pluck('question')->implode("\n");

    expect($replies)->toHaveCount(240)
        ->and($text)->toContain('I have refunded the fee')->toContain('<EMAIL_1>')->toContain(TrainingCorpusSeeder::TRIGGER)->toContain('Ignore your instructions')->toContain('dana.whitfield@example.net');
});

it('is repeatable: running it twice leaves one corpus, not two', function () {
    (new TrainingCorpusSeeder(10))->run();

    expect(Conversation::withoutGlobalScopes()->where('id', '>=', TrainingCorpusSeeder::FIRST_ID)->count())->toBe(240);
});

it('rebuilds a stored turn with the live prompt\'s slots and only the system message different', function () {
    $turn = Message::withoutGlobalScopes()->where('role', 'assistant')->whereNotNull('question')->where('category', 'billing')->whereJsonLength('retrieved', '>', 0)->first();

    $rebuilt = app(ContextAssembler::class)->rebuild($turn, SupportPrompt::FINE_TUNED_VERSION);

    $live = app(ContextAssembler::class)->rebuild($turn, SupportPrompt::VERSION);

    $roles = fn (array $messages) => array_column($messages, 'role');

    expect($rebuilt[0]['content'])->toBe(SupportPrompt::fineTuned())
        ->and($live[0]['content'])->toBe(SupportPrompt::base())
        ->and($roles($rebuilt))->toBe($roles($live))
        ->and(array_slice($rebuilt, 1))->not->toBeEmpty()
        ->and(json_encode($rebuilt, JSON_UNESCAPED_UNICODE))->toContain('late-fees › How the fee is calculated')->toContain('customer_message');
});

it('refuses a system version it does not have', function () {
    $turn = Message::withoutGlobalScopes()->where('role', 'assistant')->whereNotNull('question')->first();

    expect(fn () => app(ContextAssembler::class)->rebuild($turn, 'no-such-version'))->toThrow(InvalidArgumentException::class);
});

it('keeps the fine-tuned system message far shorter than the long one', function () {
    expect(strlen(SupportPrompt::fineTuned()))->toBeLessThan(strlen(SupportPrompt::base()) * 0.5)
        ->and(SupportPrompt::FINE_TUNED_VERSION)->toBe('2026-11-11.ft1');
});

it('tells user:forget which dataset versions still hold the erased person\'s conversations, and records it on the receipt', function () {
    $out = config('ai.training.datasets_path').'/billing-v1';

    $this->artisan('dataset:export', ['category' => 'billing', '--out' => $out, '--patterns-only' => true])->assertExitCode(0);

    $conversation = Conversation::withoutGlobalScopes()->where('id', '>=', TrainingCorpusSeeder::FIRST_ID)->where('tenant_id', 1)->first();

    $user = User::find($conversation->user_id);

    $this->artisan('user:forget', ['user' => $user->id, '--confirm' => true, '--reason' => 'erasure request'])
        ->expectsOutputToContain('datasets    billing-v1 holds')
        ->assertSuccessful();

    expect(ErasureRequest::query()->latest('id')->first()->expiring['training_datasets'])->toHaveKey('billing-v1');
});
