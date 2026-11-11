<?php

use App\Models\AnswerFeedback;
use App\Models\AnswerSignal;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\OnlineJudgment;
use App\Models\ShadowAnswerRecord;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

// Chapter 30, Figure 30-8: eval:scorecard reads the logs the rest of the chapter writes and marks anything below its floor.
function liveReply(object $test, string $content, ?string $variant = null, array $retrieved = []): Message
{
    $conversation = conversationFor($test->user);

    static $seq = 0;

    $seq += 2;

    return Message::create([
        'conversation_id' => $conversation->id, 'tenant_id' => $conversation->tenant_id, 'seq' => $seq, 'role' => 'assistant', 'content' => $content,
        'question' => 'How is the late fee calculated?', 'status' => 'completed', 'tokens' => 5, 'retrieved' => $retrieved, 'experiment_variant' => $variant,
    ]);
}

function shadowOf(Message $reply, string $content, array $gates, string $variant = 'worse-prompt'): ShadowAnswerRecord
{
    return ShadowAnswerRecord::create(['message_id' => $reply->id, 'experiment' => 'e', 'variant' => $variant, 'content' => $content, 'gates' => $gates]);
}

beforeEach(function () {
    [$this->user, $this->invoice, $this->customer] = customerWithInvoice();
});

it('prints every metric of the feature\'s scorecard against its floor, and a dash where the logs have nothing yet', function () {
    liveReply($this, 'Fees start after 30 days.');

    $this->artisan('eval:scorecard', ['feature' => 'support.reply', '--no-mlflow' => true])
        ->expectsOutputToContain('support.reply ·')
        ->expectsOutputToContain('resolved_without_human_7d     —')
        ->expectsOutputToContain('golden_quality_pass           100.0%')
        ->assertSuccessful();
});

it('says what a feature without a scorecard is', function () {
    $this->artisan('eval:scorecard', ['feature' => 'made.up'])->expectsOutputToContain('There is no scorecard for made.up')->assertExitCode(1);
});

it('splits by variant and marks the shadow candidate below its floors, with the never-gate as a STOP', function () {
    $reply = liveReply($this, 'Fees start after 30 days. [late-fees › How the fee is calculated]', retrieved: [['label' => 'late-fees › How the fee is calculated', 'eval_label' => 'help:billing/late-fees#how-the-fee-is-calculated', 'id' => 1, 'chunk_key' => 'k']]);

    shadowOf($reply, 'We will refund it.', [
        ['gate' => 'citations', 'kind' => 'quality', 'passed' => false, 'reason' => 'invented'],
        ['gate' => 'forbidden_facts', 'kind' => 'never', 'passed' => false, 'reason' => 'leaked we will refund'],
    ]);

    shadowOf(liveReply($this, 'Plain.'), 'Fees start after 30 days.', [
        ['gate' => 'citations', 'kind' => 'quality', 'passed' => true, 'reason' => 'ok'],
        ['gate' => 'forbidden_facts', 'kind' => 'never', 'passed' => true, 'reason' => 'ok'],
    ]);

    OnlineJudgment::create(['subject' => "message:{$reply->id}", 'message_id' => $reply->id, 'metric' => 'faithfulness', 'score' => 0.94, 'variant' => null]);
    OnlineJudgment::create(['subject' => 'shadow:1', 'message_id' => $reply->id, 'metric' => 'faithfulness', 'score' => 0.81, 'variant' => 'worse-prompt']);

    [$code, $out] = artisanOutput('eval:scorecard', ['feature' => 'support.reply', '--by' => 'variant', '--no-mlflow' => true]);

    expect($code)->toBe(1)
        ->and($out)->toContain('shadow (worse-prompt)')
        ->toContain('resolved_without_human_7d     —')
        ->toContain('— (shadow: not shown)')
        ->toContain('0.94')
        ->toContain('0.81  ← below floor')
        ->toContain('1  ← STOP');
});

it('counts resolution from the signals, per variant, and feedback from the ratings', function () {
    $a = liveReply($this, 'Fees start after 30 days.');
    $b = liveReply($this, 'Fees start after 30 days.', 'treatment');

    foreach ([[$a, true, null], [liveReply($this, 'x'), false, null], [$b, true, 'treatment'], [liveReply($this, 'y', 'treatment'), true, 'treatment']] as [$reply, $resolved, $variant]) {
        AnswerSignal::create(['message_id' => $reply->id, 'conversation_id' => $reply->conversation_id, 'customer_id' => $this->customer->id, 'computed_on' => now()->toDateString(), 'variant' => $variant, 'resolved' => $resolved]);
    }

    AnswerFeedback::create(['message_id' => $a->id, 'tenant_id' => $a->tenant_id, 'rating' => 'down', 'reason' => 'wrong_answer']);

    $this->artisan('eval:scorecard', ['feature' => 'support.reply', '--by' => 'variant', '--no-mlflow' => true])
        ->expectsOutputToContain('resolved_without_human_7d     50.0%')
        ->expectsOutputToContain('treatment')
        ->expectsOutputToContain('feedback: 1 of 4 conversations rated (25.0%) · thumbs-down 1')
        ->assertSuccessful();
});

it('prices an answer from its model calls and reports time to first token at the 95th percentile', function () {
    foreach ([100, 200, 300, 4000] as $ttft) {
        ModelCall::create(['feature' => 'support.reply', 'provider' => 'frontier', 'model' => 'm', 'prompt_tokens' => 1, 'completion_tokens' => 1, 'finish_reason' => 'stop', 'latency_ms' => 1, 'cost_micros' => 3000, 'ttft_ms' => $ttft]);
    }

    liveReply($this, 'x');

    $this->artisan('eval:scorecard', ['feature' => 'support.reply', '--no-mlflow' => true])
        ->expectsOutputToContain('cost_per_answer_usd           $0.0030')
        ->expectsOutputToContain('ttft_p95_seconds              4.0 s  ← below floor')
        ->assertExitCode(0);
});

it('scores a feature that has only cost to show', function () {
    ModelCall::create(['feature' => 'ticket.classify', 'provider' => 'local', 'model' => 'm', 'prompt_tokens' => 1, 'completion_tokens' => 1, 'finish_reason' => 'stop', 'latency_ms' => 1, 'cost_micros' => 100]);

    $this->artisan('eval:scorecard', ['feature' => 'ticket.classify', '--no-mlflow' => true])
        ->expectsOutputToContain('agreement_with_agent_label    —')
        ->expectsOutputToContain('cost_per_ticket_usd           $0.0001')
        ->assertSuccessful();
});

it('writes the same numbers to MLflow, and carries on when MLflow is down', function () {
    liveReply($this, 'Fees start after 30 days.');

    config(['services.mlflow.tracking_uri' => 'http://mlflow.test']);

    Http::fake([
        'mlflow.test/api/2.0/mlflow/experiments/get-by-name*' => Http::response(['experiment' => ['experiment_id' => '9']]),
        'mlflow.test/api/2.0/mlflow/runs/create' => Http::response(['run' => ['info' => ['run_id' => 'r1']]]),
        'mlflow.test/*' => Http::response([]),
    ]);

    expect(artisanOutput('eval:scorecard', ['feature' => 'support.reply'])[1])->toContain('Written to MLflow, experiment ledgerly-scorecards, run r1.');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), 'runs/log-batch') && collect($request['metrics'])->pluck('key')->contains('control.golden_quality_pass'));

    Http::swap(new Factory);                                                         // a new fake does not replace the earlier stubs

    Http::fake(['mlflow.test/*' => Http::response('down', 503)]);

    [$code, $out] = artisanOutput('eval:scorecard', ['feature' => 'support.reply']);

    expect($code)->toBe(0)->and($out)->toContain('MLflow was not updated');
});
