<?php

use App\Ai\Experiments\Candidate;
use App\Ai\Experiments\Experiment;
use App\Ai\Load\FeedbackSimulator;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Streaming\ReplyStarter;
use App\Console\Commands\EvalSample;
use App\Evals\Similarity;
use App\Events\TicketReceived;
use App\Facades\Ai;
use App\Jobs\ApplyEvalScores;
use App\Jobs\ComputeOnlineSignals;
use App\Jobs\ShadowAnswer;
use App\Models\AnswerFeedback;
use App\Models\AnswerSignal;
use App\Models\Customer;
use App\Models\Message;
use App\Models\OnlineJudgment;
use App\Models\PythonTask;
use App\Models\ShadowAnswerRecord;
use App\Models\Ticket;
use App\Models\ToolCall;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

// Chapter 30: feedback, online signals, experiments, shadow runs and the sampled judge. Nothing here needs a model server: Ai::fake() answers.
function replyFor(User $user, string $question = 'How is the late fee calculated?'): Message
{
    test()->actingAs($user);

    return app(ReplyStarter::class)->start($user, $question)->fresh();
}

beforeEach(function () {
    Sleep::fake();

    Event::fake([TicketReceived::class]);

    [$this->user, $this->invoice, $this->customer] = customerWithInvoice();
});

describe('feedback (Topic 3)', function () {
    beforeEach(function () {
        Ai::fake()->for('support.reply')->respondWith('Fees start after 30 days.');

        $this->reply = replyFor($this->user);
    });

    it('stores a thumbs-down with its reason, the prompt version and the call', function () {
        $this->actingAs($this->user)->postJson(route('replies.feedback', $this->reply), ['rating' => 'down', 'reason' => 'wanted_person'])
            ->assertOk()->assertJson(['feedback' => 'down', 'reason' => 'wanted_person']);

        $row = AnswerFeedback::sole();

        expect($row->message_id)->toBe($this->reply->id)
            ->and($row->rating)->toBe('down')
            ->and($row->reason)->toBe('wanted_person')
            ->and($row->prompt_version)->toBe(SupportPrompt::VERSION)
            ->and($row->variant)->toBeNull()
            ->and($this->reply->fresh()->feedback)->toBe('down');
    });

    it('replaces the rating on a second click and drops the reason for a thumbs-up', function () {
        $this->actingAs($this->user)->postJson(route('replies.feedback', $this->reply), ['rating' => 'down', 'reason' => 'wrong_answer'])->assertOk();
        $this->actingAs($this->user)->postJson(route('replies.feedback', $this->reply), ['rating' => 'up'])->assertOk();

        expect(AnswerFeedback::count())->toBe(1)
            ->and(AnswerFeedback::sole()->reason)->toBeNull()
            ->and(AnswerFeedback::sole()->rating)->toBe('up');
    });

    it('still takes the Chapter 18 pages\' {value}', function () {
        $this->actingAs($this->user)->postJson(route('replies.feedback', $this->reply), ['value' => 'up'])->assertOk()->assertJson(['feedback' => 'up']);
    });

    it('refuses an unknown reason, a reason on a thumbs-up, and anybody but the customer who got the answer', function () {
        $this->actingAs($this->user)->postJson(route('replies.feedback', $this->reply), ['rating' => 'down', 'reason' => 'boring'])->assertUnprocessable();
        $this->actingAs($this->user)->postJson(route('replies.feedback', $this->reply), ['rating' => 'up', 'reason' => 'wrong_answer'])->assertUnprocessable();
        $this->actingAs(User::factory()->inTenant($this->user->tenant)->create())->postJson(route('replies.feedback', $this->reply), ['rating' => 'up'])->assertNotFound();

        auth()->logout();

        $this->postJson(route('replies.feedback', $this->reply), ['rating' => 'up'])->assertUnauthorized();

        expect(AnswerFeedback::count())->toBe(0);
    });

    it('puts the thumbs and the three reason chips under a finished answer in the chat page', function () {
        $this->blade('<x-answer-feedback :reply-id="5" :rating="null" />')
            ->assertSee('Helpful')
            ->assertSee('Wrong answer')
            ->assertSee('Did not answer my question')
            ->assertSee('I wanted a person')
            ->assertSee('/replies/5/feedback', false);
    });

    it('lets the replay click a stable share of the answers', function () {
        $rated = collect(range(1, 2000))->filter(fn (int $id) => FeedbackSimulator::clicks(7, $id, 0.08))->count();

        expect($rated)->toBeBetween(120, 200)
            ->and(FeedbackSimulator::clicks(7, 42, 0.08))->toBe(FeedbackSimulator::clicks(7, 42, 0.08))
            ->and(AnswerFeedback::REASONS)->toContain(FeedbackSimulator::reason(7, 42));
    });
});

describe('online signals (Topic 3)', function () {
    beforeEach(function () {
        Carbon::setTestNow('2026-11-10 10:00:00');

        $this->conversation = conversationFor($this->user);
    });

    function answered(object $test, string $question, string $at, int $seq, array $overrides = []): Message
    {
        $conversation = $test->conversation;

        Message::create(['conversation_id' => $conversation->id, 'tenant_id' => $conversation->tenant_id, 'seq' => $seq, 'role' => 'user', 'content' => $question, 'tokens' => 5, 'created_at' => $at, 'updated_at' => $at]);

        return Message::create($overrides + ['conversation_id' => $conversation->id, 'tenant_id' => $conversation->tenant_id, 'seq' => $seq + 1, 'role' => 'assistant', 'content' => 'An answer.', 'question' => $question, 'status' => 'completed', 'tokens' => 5, 'retrieved' => [], 'created_at' => $at, 'updated_at' => $at]);
    }

    it('marks a question asked again within two minutes in nearly the same words', function () {
        Ai::fake();

        $first = answered($this, 'How is the late fee calculated?', '2026-11-10 09:00:00', 1);
        Message::create(['conversation_id' => $this->conversation->id, 'tenant_id' => $this->conversation->tenant_id, 'seq' => 3, 'role' => 'user', 'content' => 'How is the late fee calculated?', 'tokens' => 5, 'created_at' => '2026-11-10 09:01:00', 'updated_at' => '2026-11-10 09:01:00']);

        $late = answered($this, 'Something else entirely', '2026-11-10 09:30:00', 4);

        (new ComputeOnlineSignals('2026-11-10'))->handle(app(Similarity::class), app(CurrentTenant::class));

        expect(AnswerSignal::where('message_id', $first->id)->sole()->reasked)->toBeTrue()
            ->and(AnswerSignal::where('message_id', $first->id)->sole()->resolved)->toBeFalse()
            ->and(AnswerSignal::where('message_id', $late->id)->sole()->reasked)->toBeFalse();
    });

    it('does not count a similar question asked after the two minutes', function () {
        Ai::fake();

        $first = answered($this, 'How is the late fee calculated?', '2026-11-10 09:00:00', 1);
        Message::create(['conversation_id' => $this->conversation->id, 'tenant_id' => $this->conversation->tenant_id, 'seq' => 3, 'role' => 'user', 'content' => 'How is the late fee calculated?', 'tokens' => 5, 'created_at' => '2026-11-10 09:05:00', 'updated_at' => '2026-11-10 09:05:00']);

        (new ComputeOnlineSignals('2026-11-10'))->handle(app(Similarity::class), app(CurrentTenant::class));

        expect(AnswerSignal::where('message_id', $first->id)->sole()->reasked)->toBeFalse();
    });

    it('marks a ticket the customer opened within the hour on the same topic, but not one on another topic or one the assistant opened', function () {
        Ai::fake();

        $reply = answered($this, 'My invoice export keeps failing with an error', '2026-11-10 09:00:00', 1);

        Ticket::factory()->create(['customer_id' => $this->customer->id, 'source' => 'email', 'subject' => 'Invoice export failing', 'body' => 'The export of my invoices shows an error.', 'created_at' => '2026-11-10 09:20:00']);

        (new ComputeOnlineSignals('2026-11-10'))->handle(app(Similarity::class), app(CurrentTenant::class));

        expect(AnswerSignal::sole()->ticket_followup)->toBeTrue();

        AnswerSignal::query()->delete();

        Ticket::query()->delete();

        Ticket::factory()->create(['customer_id' => $this->customer->id, 'source' => 'assistant', 'subject' => 'Invoice export failing', 'body' => 'The export of my invoices shows an error.', 'created_at' => '2026-11-10 09:20:00']);
        Ticket::factory()->create(['customer_id' => $this->customer->id, 'source' => 'email', 'subject' => 'Change my plan', 'body' => 'I want to upgrade to Pro.', 'created_at' => '2026-11-10 09:25:00']);

        (new ComputeOnlineSignals('2026-11-10'))->handle(app(Similarity::class), app(CurrentTenant::class));

        expect(AnswerSignal::sole()->ticket_followup)->toBeFalse();
    });

    it('marks an escalation when the assistant opened a ticket, and resolved when nothing else happened', function () {
        Ai::fake();

        $escalated = answered($this, 'Please open a ticket for my export', '2026-11-10 09:00:00', 1);
        ToolCall::create(['user_id' => $this->user->id, 'conversation_id' => $this->conversation->id, 'tool' => 'open_ticket', 'arguments' => [], 'decision' => 'allowed', 'created_at' => '2026-11-10 09:00:30']);

        $fine = answered($this, 'When do fees start?', '2026-11-10 09:30:00', 3);

        (new ComputeOnlineSignals('2026-11-10'))->handle(app(Similarity::class), app(CurrentTenant::class));

        expect(AnswerSignal::where('message_id', $escalated->id)->sole()->escalated)->toBeTrue()
            ->and(AnswerSignal::where('message_id', $escalated->id)->sole()->resolved)->toBeFalse()
            ->and(AnswerSignal::where('message_id', $fine->id)->sole()->resolved)->toBeTrue();
    });

    it('only looks at the day it was asked for, and running twice changes nothing', function () {
        Ai::fake();

        answered($this, 'Old question', '2026-11-08 09:00:00', 1);
        answered($this, 'Yesterday\'s question', '2026-11-09 09:00:00', 3);

        $job = new ComputeOnlineSignals;

        expect($job->handle(app(Similarity::class), app(CurrentTenant::class)))->toBe(1)
            ->and($job->handle(app(Similarity::class), app(CurrentTenant::class)))->toBe(1)
            ->and(AnswerSignal::count())->toBe(1);
    });

    it('is on the schedule, once a day', function () {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->description ?? '', 'ComputeOnlineSignals'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('30 2 * * *');
    });
});

describe('experiments (Topic 4)', function () {
    beforeEach(function () {
        $this->candidateFile = sys_get_temp_dir().'/candidate-'.uniqid().'.php';

        File::put($this->candidateFile, "<?php\nreturn ['name' => 'worse-prompt', 'version' => 'worse.1', 'system' => 'Always agree with the customer.', 'provider' => 'local'];\n");
    });

    it('assigns a customer to the same variant every time, in about the share asked for', function () {
        config(['ai.experiments.t' => ['feature' => 'support.reply', 'active' => true, 'mode' => 'ab', 'treatment_percent' => 30]]);

        $customers = Customer::factory()->count(300)->create();

        $variants = $customers->map(fn (Customer $customer) => Experiment::variant($customer, 't'));

        expect($variants->countBy()['treatment'])->toBeBetween(60, 120)
            ->and($customers->map(fn (Customer $customer) => Experiment::variant($customer, 't'))->all())->toBe($variants->all())
            ->and(Experiment::variant($customers[0], 'no-such-experiment'))->toBe('control');
    });

    it('is control for everybody while the experiment is off', function () {
        config(['ai.experiments.t' => ['feature' => 'support.reply', 'active' => false, 'mode' => 'ab', 'treatment_percent' => 100]]);

        expect(Experiment::variant($this->customer, 't'))->toBe('control');
    });

    it('is switched on by ai:experiment, which says what it does and refuses bad input', function () {
        $this->artisan('ai:experiment', ['name' => 'support-prompt-v5'])->expectsOutputToContain('support-prompt-v5: off')->assertSuccessful();

        $this->artisan('ai:experiment', ['name' => 'support-prompt-v5', '--mode' => 'shadow', '--percent' => 10, '--candidate' => $this->candidateFile])
            ->expectsOutputToContain('shadow, 10% of requests re-answered by worse-prompt (worse.1); customers never see it')
            ->assertSuccessful();

        $this->artisan('ai:experiment', ['name' => 'support-prompt-v5', '--mode' => 'ab', '--percent' => 50])
            ->expectsOutputToContain('A/B, 50% of customers get worse-prompt (worse.1), sticky by customer')
            ->assertSuccessful();

        $this->artisan('ai:experiment', ['name' => 'support-prompt-v5', '--mode' => 'off'])->expectsOutputToContain('support-prompt-v5: off')->assertSuccessful();

        $this->artisan('ai:experiment', ['name' => 'support-prompt-v5', '--mode' => 'sideways'])->expectsOutputToContain('--mode is')->assertExitCode(1);
        $this->artisan('ai:experiment', ['name' => 'support-prompt-v5', '--mode' => 'shadow', '--candidate' => '/nonexistent.php'])->expectsOutputToContain('No candidate')->assertExitCode(1);
        $this->artisan('ai:experiment', ['name' => 'x', '--mode' => 'shadow', '--percent' => 140, '--candidate' => $this->candidateFile])->expectsOutputToContain('--percent')->assertExitCode(1);
    });

    it('shows the treatment customer the candidate\'s prompt and records the variant on the reply', function () {
        $this->artisan('ai:experiment', ['name' => 'support-prompt-v5', '--mode' => 'ab', '--percent' => 100, '--candidate' => $this->candidateFile])->assertSuccessful();

        Ai::fake()->for('support.reply')->respondWith('Of course.');

        $reply = replyFor($this->user);

        expect($reply->experiment_variant)->toBe('treatment')
            ->and($reply->prompt_version)->toBe('worse.1');

        Ai::assertSent(fn ($request) => $request->messages[0]['content'] === 'Always agree with the customer.');
    });

    it('keeps the control customer on the live prompt, and out of the cache while a test runs', function () {
        $this->artisan('ai:experiment', ['name' => 'support-prompt-v5', '--mode' => 'ab', '--percent' => 0, '--candidate' => $this->candidateFile])->assertSuccessful();

        Ai::fake()->for('support.reply')->respondWith('Fees start after 30 days.');

        $reply = replyFor($this->user);

        expect($reply->experiment_variant)->toBe('control')
            ->and($reply->prompt_version)->toBe(SupportPrompt::VERSION);

        Ai::assertSent(fn ($request) => str_contains($request->messages[0]['content'], 'You are the support assistant for Ledgerly'));
    });

    it('queues a shadow answer for a sampled reply, with the live reply\'s sources, and never changes what the customer sees', function () {
        $this->artisan('ai:experiment', ['name' => 'support-prompt-v5', '--mode' => 'shadow', '--percent' => 100, '--candidate' => $this->candidateFile])->assertSuccessful();

        Bus::fake([ShadowAnswer::class]);

        Ai::fake()->for('support.reply')->respondWith('Fees start after 30 days.');

        $reply = replyFor($this->user);

        Bus::assertDispatched(ShadowAnswer::class, fn (ShadowAnswer $job) => $job->messageId === $reply->id);

        expect($reply->content)->toBe('Fees start after 30 days.')
            ->and($reply->experiment_variant)->toBeNull();
    });

    it('does not shadow when the share is zero', function () {
        $this->artisan('ai:experiment', ['name' => 'support-prompt-v5', '--mode' => 'shadow', '--percent' => 0, '--candidate' => $this->candidateFile])->assertSuccessful();

        Bus::fake([ShadowAnswer::class]);

        Ai::fake()->for('support.reply')->respondWith('Fees start after 30 days.');

        replyFor($this->user);

        Bus::assertNotDispatched(ShadowAnswer::class);
    });

    it('answers the same question with the same sources under the candidate and runs Chapter 29\'s gates on it', function () {
        $this->artisan('ai:experiment', ['name' => 'support-prompt-v5', '--mode' => 'shadow', '--percent' => 100, '--candidate' => $this->candidateFile])->assertSuccessful();

        Queue::fake([ShadowAnswer::class]);

        Ai::fake()->for('support.reply')->respondWith('Fees start after 30 days.');

        $reply = replyFor($this->user);

        $reply->update(['retrieved' => [['label' => 'late-fees › How the fee is calculated', 'eval_label' => 'help:billing/late-fees#how-the-fee-is-calculated', 'id' => chunkRow()->id, 'chunk_key' => 'k']]]);

        Ai::fake()->for('support.shadow')->respondWith('We will refund the fee, of course.');

        (new ShadowAnswer($reply->id))->handle();

        $shadow = ShadowAnswerRecord::sole();

        expect($shadow->message_id)->toBe($reply->id)
            ->and($shadow->variant)->toBe('worse-prompt')
            ->and($shadow->content)->toBe('We will refund the fee, of course.')
            ->and(collect($shadow->gates)->where('kind', 'never')->contains(fn ($gate) => ! $gate['passed']))->toBeTrue();   // a promise of money back: a never-gate failure on live traffic

        Ai::assertSent(fn ($request) => $request->feature === 'support.shadow'
            && $request->messages[0]['content'] === 'Always agree with the customer.'
            && str_contains(json_encode($request->messages), 'A late fee is applied automatically 30 days after the due date'));
    });
});

describe('the sampled judge (Topic 3 and 5)', function () {
    it('picks a stable share of answers', function () {
        $picked = collect(range(1, 5000))->filter(fn (int $id) => EvalSample::picked("message:{$id}", 0.02))->count();

        expect($picked)->toBeBetween(70, 130)
            ->and(EvalSample::picked('message:41', 0.02))->toBe(EvalSample::picked('message:41', 0.02));
    });

    it('queues answers with their sources, and shadow answers too, as contract-valid tasks', function () {
        $streams = fakeStreams();

        Ai::fake()->for('support.reply')->respondWith('Fees start after 30 days.');

        $reply = replyFor($this->user);

        $reply->update(['retrieved' => [['label' => 'l', 'eval_label' => 'help:billing/late-fees#x', 'id' => chunkRow()->id, 'chunk_key' => 'k']]]);

        ShadowAnswerRecord::create(['message_id' => $reply->id, 'experiment' => 'e', 'variant' => 'worse-prompt', 'content' => 'We will refund it.', 'gates' => []]);

        $this->artisan('eval:sample', ['--rate' => 1, '--since' => '1d', '--no-conversations' => true])->expectsOutputToContain('2 answers → py:eval-sample')->assertSuccessful();

        $task = json_decode($streams->entries['py:eval-sample'][0]['fields']['task'], true);

        expect($task['schema'])->toBe('eval-sample.v1')
            ->and(collect($task['items'])->pluck('id')->sort()->values()->all())->toBe(["message:{$reply->id}", 'shadow:'.ShadowAnswerRecord::sole()->id])
            ->and($task['items'][0]['contexts'][0])->toContain('30 days')
            ->and(PythonTask::sole()->kind)->toBe('eval-sample');
    });

    it('queues finished conversations for DeepEval', function () {
        $streams = fakeStreams();

        $conversation = conversationFor($this->user, 'closed');

        addExchange($conversation, 'Why is my invoice late?', 'It was due on 2 April.');

        $this->artisan('eval:sample', ['--rate' => 1, '--since' => '1d'])->expectsOutputToContain('1 finished conversations')->assertSuccessful();

        $task = json_decode($streams->entries['py:eval-sample'][0]['fields']['task'], true);

        expect($task['schema'])->toBe('eval-conversations.v1')
            ->and($task['items'][0]['id'])->toBe("conversation:{$conversation->id}")
            ->and($task['items'][0]['turns'])->toHaveCount(2);
    });

    it('says when nothing was picked and rejects a bad rate or window', function () {
        fakeStreams();

        $this->artisan('eval:sample')->expectsOutputToContain('Nothing to score')->assertSuccessful();
        $this->artisan('eval:sample', ['--rate' => 3])->expectsOutputToContain('--rate')->assertExitCode(1);
        $this->artisan('eval:sample', ['--since' => 'lately'])->expectsOutputToContain('--since')->assertExitCode(1);
    });

    it('applies the scores py:listen hands over, once', function () {
        fakeStreams();

        Ai::fake()->for('support.reply')->respondWith('Fees start after 30 days.');

        $reply = replyFor($this->user);

        $shadow = ShadowAnswerRecord::create(['message_id' => $reply->id, 'experiment' => 'e', 'variant' => 'worse-prompt', 'content' => 'x', 'gates' => []]);

        $taskId = '0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11';

        PythonTask::create(['task_id' => $taskId, 'kind' => 'eval-sample', 'stream' => 'py:eval-sample', 'model' => 'llama3.1:8b', 'status' => 'queued', 'item_count' => 2, 'queued_at' => now(), 'deadline_at' => now()->addMinutes(10)]);

        $result = [
            'schema' => 'eval-scores.v1', 'task_id' => $taskId, 'request_id' => null, 'judge' => 'llama3.1:8b', 'elapsed_ms' => 900,
            'items' => [['id' => "message:{$reply->id}", 'metrics' => ['faithfulness' => 0.9, 'response_relevancy' => 0.8]], ['id' => "shadow:{$shadow->id}", 'metrics' => ['faithfulness' => 0.2]], ['id' => 'message:999999', 'metrics' => ['faithfulness' => 1.0]]],
        ];

        (new ApplyEvalScores($result))->handle();
        (new ApplyEvalScores($result))->handle();                                       // a duplicate delivery

        expect(OnlineJudgment::count())->toBe(3)
            ->and(OnlineJudgment::where('subject', "shadow:{$shadow->id}")->sole()->variant)->toBe('worse-prompt')
            ->and(OnlineJudgment::where('subject', "message:{$reply->id}")->where('metric', 'faithfulness')->sole()->score)->toBe(0.9)
            ->and(PythonTask::sole()->status)->toBe('done')
            ->and(PythonTask::sole()->applied_count)->toBe(3);
    });

    it('lets py:listen route an eval-scores result to the job that applies it, and rejects an unknown schema', function () {
        Bus::fake([ApplyEvalScores::class]);

        $streams = fakeStreams();

        $taskId = '0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11';

        PythonTask::create(['task_id' => $taskId, 'kind' => 'eval-sample', 'stream' => 'py:eval-sample', 'model' => 'j', 'status' => 'queued', 'item_count' => 1, 'queued_at' => now(), 'deadline_at' => now()->addMinutes(10)]);

        $streams->add('py:results', ['task' => json_encode(['schema' => 'eval-scores.v1', 'task_id' => $taskId, 'request_id' => null, 'judge' => 'j', 'elapsed_ms' => 1, 'items' => [['id' => 'message:1', 'metrics' => ['faithfulness' => 0.5]]]])]);
        $streams->add('py:results', ['task' => json_encode(['schema' => 'whatever.v9', 'task_id' => $taskId])]);

        $this->artisan('py:listen', ['--once' => true])->assertSuccessful();

        Bus::assertDispatched(ApplyEvalScores::class, 1);

        expect(PythonTask::sole()->status)->toBe('failed')
            ->and(PythonTask::sole()->error)->toContain("unknown result schema 'whatever.v9'");
    });
});

it('reads a candidate from a prompt class too', function () {
    $candidate = Candidate::from(SupportPrompt::class);

    expect($candidate->version)->toBe(SupportPrompt::VERSION)
        ->and($candidate->system)->toBe(SupportPrompt::base())
        ->and(fn () => Candidate::from('/no/such/file.php'))->toThrow(InvalidArgumentException::class);
});
