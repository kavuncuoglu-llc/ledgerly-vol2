<?php

use App\Ai\AiManager;
use App\Ai\Distillation\Question;
use App\Ai\Distillation\TeacherCheck;
use App\Ai\Distillation\TeacherRefused;
use App\Ai\Prompts\FineTunedSupportPrompt;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\Retriever;
use App\Ai\Training\Dataset;
use App\Facades\Ai;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\File;

// Chapter 46, Topics 2 and 3: a teacher needs a recorded basis; the set is built from consented and teacher-written questions, the teacher's answers through the gates and the judge, and a tenth of what survives is queued for review.
beforeEach(function () {
    config([
        'ai.training.datasets_path' => sys_get_temp_dir().'/distill-'.bin2hex(random_bytes(4)),
        'ai.training.hash_key' => 'test-key',
        'ai.security.canary' => '',
        'ai.providers.mid-in-region.base_url' => 'http://mid-eu.test/v1',
        'ai.providers.mid-in-region.model' => 'llama3.1:70b',
        'ai.providers.mid-in-region.distillation_basis' => ['license' => 'Llama 3.1 Community License', 'reviewed' => '2026-10-28', 'by' => 'legal'],
        'ai.providers.mid-in-region.privacy.region' => 'eu',
        'ai.compare.judge_provider' => 'local',
        'ai.distillation.categories' => ['plans' => ['articles' => ['billing/change-your-plan']], 'integrations' => ['articles' => ['integrations']]],
        'ai.distillation.questions_per_article' => 2,
        'ai.retrieval.threshold' => 0.0,
        'ledgerly.help_version' => 'v1',
    ]);

    helpLibrary([
        'billing/change-your-plan.md' => helpArticleSource('Change your plan', 'billing', "## Upgrading\n\nYou can upgrade at any time from Settings, then Plan. The change is prorated by the days left in the cycle.\n"),
        'integrations/webhooks.md' => helpArticleSource('Webhooks', 'integrations', "## Add an endpoint\n\nCreate a webhook endpoint in Settings, then Developers, and choose the events it receives.\n"),
        'billing/late-fees.md' => helpArticleSource('Late fees', 'billing', "## How the fee is calculated\n\nThe fee is 1.5% a month.\n"),
    ]);

    fakeEmbeddings();

    $this->artisan('help:ingest')->assertSuccessful();

    $this->directory = config('ai.training.datasets_path');

    app()->instance(Retriever::class, new class implements Retriever
    {
        public function retrieve(string $question, RetrievalScope $scope, int $k = 5): array
        {
            return [retrievedChunk('You can upgrade at any time from Settings, then Plan.', 'help/billing/change-your-plan.md', 'Change your plan › Upgrading')];
        }
    });
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

function teacherSays(string $answer = 'Open Settings, then Plan, and pick the new plan. [change-your-plan › Upgrading]', string $score = '5'): void
{
    Ai::fake()->for('eval.synthesize')->respondWith('How do I move to a bigger plan?', 5)
        ->for('dataset.distill')->respondWith($answer, 10)
        ->for('dataset.distill.judge')->respondWith($score, 10);
}

it('refuses a teacher that is not set up, has no recorded basis, or does not exist', function (array $config, string $message) {
    config($config);

    expect(fn () => app(TeacherCheck::class)->teacher('mid-in-region'))->toThrow(TeacherRefused::class, $message);
})->with([
    'no basis' => [['ai.providers.mid-in-region.distillation_basis' => null], 'no recorded basis for training on its outputs'],
    'half a basis' => [['ai.providers.mid-in-region.distillation_basis' => ['license' => 'x', 'reviewed' => '', 'by' => 'legal']], 'needs license, reviewed and by'],
    'not set up' => [['ai.providers.mid-in-region.base_url' => ''], 'is not set up'],
]);

it('names the teacher, its exact version and its license basis', function () {
    config(['ai.providers.mid-in-region.digest' => 'sha256:abc']);

    $teacher = app(TeacherCheck::class)->teacher('mid-in-region');

    expect($teacher->toArray())->toBe(['role' => 'mid-in-region', 'model' => 'llama3.1:70b', 'version' => 'llama3.1:70b@sha256:abc', 'region' => 'eu', 'distillation_basis' => ['license' => 'Llama 3.1 Community License', 'reviewed' => '2026-10-28', 'by' => 'legal']]);

    expect(fn () => app(TeacherCheck::class)->teacher('nowhere'))->toThrow(TeacherRefused::class, 'There is no provider [nowhere]');
});

it('writes a versioned dataset from the teacher\'s answers that pass the filter, and the report says what was rejected', function () {
    teacherSays();

    $this->artisan('dataset:distill', ['name' => 'support-distill-v1', '--categories' => 'plans,integrations', '--questions' => 'teacher-written:4', '--review-sample' => '1', '--filter' => 'gates,judge'])
        ->expectsOutputToContain('teacher answers · rejected by gates')
        ->assertSuccessful();

    $dataset = Dataset::named('support-distill-v1');

    $rows = [...$dataset->read('train.jsonl'), ...$dataset->read('validation.jsonl')];

    $manifest = $dataset->readJson('manifest.json');

    expect($rows)->not->toBeEmpty()
        ->and($rows[0]['prompt'][0])->toMatchArray(['role' => 'system'])
        ->and($rows[0]['prompt'][0]['content'])->toContain(FineTunedSupportPrompt::system())->not->toContain('At most four sentences')
        ->and(collect($rows[0]['prompt'])->pluck('role')->all())->toBe(['system', 'user'])                  // passages and question in one message: every server renders it alike
        ->and($rows[0]['completion'][0]['content'])->toContain('Open Settings')
        ->and($manifest['teacher'])->toMatchArray(['role' => 'mid-in-region', 'model' => 'llama3.1:70b'])
        ->and($manifest['teacher']['distillation_basis'])->toBe(['license' => 'Llama 3.1 Community License', 'reviewed' => '2026-10-28', 'by' => 'legal'])
        ->and($manifest['kind'])->toBe('distillation')
        ->and($manifest['consent']['teacher-written'])->toBe('n/a')
        ->and($manifest['files'])->toHaveKeys(['train.jsonl', 'validation.jsonl']);
});

it('keeps an answer out when a gate fails or the judge scores it low, and says which', function (string $answer, string $score, string $by) {
    teacherSays($answer, $score);

    $this->artisan('dataset:distill', ['name' => 'support-distill-v1', '--categories' => 'plans', '--questions' => 'teacher-written:2'])->assertSuccessful();

    $report = Dataset::named('support-distill-v1')->readJson('distill_report.json');

    $rejected = Dataset::named('support-distill-v1')->read('rejected.jsonl');

    expect($report['kept'])->toBe(0)
        ->and($report['rejected_by_'.$by])->toBeGreaterThan(0)
        ->and($rejected[0]['rejected_by'])->toBe($by);
})->with([
    'a never-gate (it promises a refund)' => ['I have refunded your last invoice. [change-your-plan › Upgrading]', '5', 'gates'],
    'no citation at all' => ['Just go to Settings.', '5', 'gates'],
    'a judge that scores it 2' => ['Open Settings, then Plan, and pick the new plan. [change-your-plan › Upgrading]', '2', 'judge'],
]);

it('queues a tenth of the survivors, at least one, and the same ones on a rerun', function () {
    teacherSays();

    $this->artisan('dataset:distill', ['name' => 'support-distill-v1', '--categories' => 'plans,integrations', '--questions' => 'teacher-written:4', '--review-sample' => '0.1'])->assertSuccessful();

    $report = Dataset::named('support-distill-v1')->readJson('distill_report.json');

    $queue = collect(Dataset::named('support-distill-v1')->read('review-queue.jsonl'));

    expect($report['kept'])->toBeGreaterThan(0)
        ->and($report['queued_for_review'])->toBe((int) ceil($report['kept'] * 0.1))
        ->and($queue->count())->toBe($report['queued_for_review'])
        ->and($queue[0]['decision'])->toBeNull();
});

it('queues every survivor when the sample is the whole set', function () {
    teacherSays();

    $this->artisan('dataset:distill', ['name' => 'support-distill-v1', '--categories' => 'plans,integrations', '--questions' => 'teacher-written:4', '--review-sample' => '1'])->assertSuccessful();

    $report = Dataset::named('support-distill-v1')->readJson('distill_report.json');

    expect($report['queued_for_review'])->toBe($report['kept'])
        ->and(Dataset::named('support-distill-v1')->read('review-queue.jsonl')[0])->toHaveKeys(['id', 'category', 'origin', 'question', 'answer', 'decision']);
});

it('never rewrites a dataset version', function () {
    teacherSays();

    $this->artisan('dataset:distill', ['name' => 'support-distill-v1', '--categories' => 'plans', '--questions' => 'teacher-written:2'])->assertSuccessful();

    $this->artisan('dataset:distill', ['name' => 'support-distill-v1', '--categories' => 'plans', '--questions' => 'teacher-written:2'])->expectsOutputToContain('A version is never rewritten')->assertExitCode(1);

});

it('refuses a teacher without a basis before it sends anything', function () {
    config(['ai.providers.mid-in-region.distillation_basis' => null]);

    Ai::fake();

    $this->artisan('dataset:distill', ['name' => 'support-distill-v2', '--categories' => 'plans', '--questions' => 'teacher-written:2'])->expectsOutputToContain('no recorded basis')->assertExitCode(1);

    Ai::assertNothingSent();
});

it('turns bad options into messages', function () {
    $this->artisan('dataset:distill', ['name' => 'x', '--categories' => 'refunds'])->expectsOutputToContain('No category [refunds]')->assertExitCode(1);
    $this->artisan('dataset:distill', ['name' => 'x', '--categories' => 'plans', '--questions' => 'consented:lots'])->expectsOutputToContain('--questions is consented:<n> and teacher-written:<n>')->assertExitCode(1);
    $this->artisan('dataset:distill', ['name' => 'x', '--categories' => 'plans', '--filter' => 'vibes'])->expectsOutputToContain('--filter is gates, judge or both')->assertExitCode(1);
});

it('asks only consented tenants\' conversations, redacts the question, and keeps a residency tenant out of a teacher in another region', function () {
    teacherSays();

    [$user, , $customer] = customerWithInvoice('INV-4471');

    $user->tenant->update(['training_use' => true, 'training_terms_version' => 't1']);

    $conversation = Conversation::query()->create(['user_id' => $user->id, 'tenant_id' => $user->tenant_id, 'status' => 'open', 'prompt_version' => 'x', 'summary_through_seq' => 0]);

    $reply = Message::withoutGlobalScopes()->create(['conversation_id' => $conversation->id, 'tenant_id' => $user->tenant_id, 'role' => 'assistant', 'content' => 'Settings, then Plan.', 'question' => 'How do I change my plan? Write to maya@acme-studios.example.com', 'status' => 'completed', 'seq' => 2, 'tokens' => 10, 'retrieved' => [['id' => 1, 'eval_label' => 'help:billing/change-your-plan#upgrading']]]);

    $this->artisan('dataset:distill', ['name' => 'support-distill-v1', '--categories' => 'plans', '--questions' => 'consented:5', '--patterns-only' => true])->assertSuccessful();

    $dataset = Dataset::named('support-distill-v1');

    $questions = collect([...$dataset->read('train.jsonl'), ...$dataset->read('validation.jsonl')])->map(fn ($row) => collect($row['prompt'])->where('role', 'user')->last()['content'])->implode("\n");

    expect($questions)->toContain('How do I change my plan?')->not->toContain('maya@acme-studios.example.com')
        ->and(collect($dataset->records('final'))->pluck('meta.origin')->unique()->all())->toBe(['consented'])
        ->and($dataset->readJson('manifest.json')['consent']['consented'])->toContain('consented conversations');

    $user->tenant->update(['data_residency' => 'us']);

    $this->artisan('dataset:distill', ['name' => 'support-distill-v2', '--categories' => 'plans', '--questions' => 'consented:5', '--patterns-only' => true])->assertSuccessful();

    expect(Dataset::named('support-distill-v2')->read('train.jsonl'))->toBe([]);
});

it('identifies a question by its category and text, so a repeat is one question', function () {
    $a = new Question('How do I change my plan?', Question::CONSENTED, 'plans');
    $b = new Question('How do I change my plan?', Question::TEACHER_WRITTEN, 'plans');

    expect($a->id())->toBe($b->id())->and(strlen($a->id()))->toBe(16);
});

it('shows the teacher, its license and who reviewed it when the dataset is shown', function () {
    teacherSays();

    $this->artisan('dataset:distill', ['name' => 'support-distill-v1', '--categories' => 'plans,integrations', '--questions' => 'teacher-written:4'])->assertSuccessful();

    $this->artisan('dataset:show', ['dataset' => 'support-distill-v1'])
        ->expectsOutputToContain('teacher       mid-in-region · llama3.1:70b · Llama 3.1 Community License (reviewed 2026-10-28 by legal)')
        ->assertExitCode(0);
});

it('is a second adapter on the vLLM base: a pinned role with its provenance, named by the registry alias, in the registry and in every route a fine-tune may serve', function () {
    $role = config('ai.providers.local-support-ft');

    expect($role['pinned'])->toBeTrue()
        ->and($role['fine_tuned'])->toMatchArray(['registry' => 'support@production', 'prompt_version' => FineTunedSupportPrompt::VERSION, 'base' => 'meta-llama/Llama-3.1-8B-Instruct'])
        ->and(app(AiManager::class)->configured('local-support-ft'))->toBeFalse()
        ->and(config('ai.registry.roles'))->toMatchArray(['billing' => 'local-billing-ft', 'support' => 'local-support-ft'])
        ->and(config('ai.registry.features')['support.reply'])->toBe(['billing', 'support'])
        ->and(config('ai.features')['support.reply']['allowed_providers'])->toContain('local-support-ft', 'local-support-ft@canary');
});

it('rejects an answer when the judge cannot be reached, and the reason says what went wrong', function () {
    Ai::fake()->for('eval.synthesize')->respondWith('How do I move to a bigger plan?', 5)
        ->for('dataset.distill')->respondWith('Open Settings, then Plan. [change-your-plan › Upgrading]', 5)
        ->for('dataset.distill.judge')->respondWithError(new RuntimeException("model 'ledgerly-support' not found"), 5);

    $this->artisan('dataset:distill', ['name' => 'support-distill-v1', '--categories' => 'plans', '--questions' => 'teacher-written:2'])->assertSuccessful();

    $rejected = Dataset::named('support-distill-v1')->read('rejected.jsonl');

    expect(Dataset::named('support-distill-v1')->readJson('distill_report.json')['kept'])->toBe(0)
        ->and($rejected[0]['rejected_by'])->toBe('judge')
        ->and($rejected[0]['reason'])->toContain('the judge gave no score')->toContain("model 'ledgerly-support' not found");
});

it('rejects an answer that was given passages and cites none of them', function () {
    teacherSays('Just go to Settings and pick the plan.', '5');

    $this->artisan('dataset:distill', ['name' => 'support-distill-v1', '--categories' => 'plans', '--questions' => 'teacher-written:2'])->assertSuccessful();

    expect(Dataset::named('support-distill-v1')->read('rejected.jsonl')[0]['reason'])->toContain('cites none of them');
});
