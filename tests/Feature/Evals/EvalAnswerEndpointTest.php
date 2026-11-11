<?php

use App\Ai\Prompts\SupportPrompt;
use App\Evals\EvalAnswerer;
use App\Events\TicketReceived;
use App\Facades\Ai;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Providers\EvalServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

// Chapter 29, Figure 29-9: POST /eval/answer is the one place inference runs inside a request, so it is fenced: a flag, a token, fixture customers.
function enableEvalRoute(): void
{
    config(['ai.eval.enabled' => true, 'ai.eval.token' => 'eval-token-for-tests', 'ai.eval.fixture_customers' => PHP_INT_MAX]);

    (new EvalServiceProvider(app()))->boot();

    app('router')->getRoutes()->refreshNameLookups();
    app('router')->getRoutes()->refreshActionLookups();
}

function acme(): array
{
    $customer = Customer::factory()->create(['name' => 'Acme Studios']);

    $owner = User::factory()->owner($customer)->create();

    Invoice::factory()->for($customer)->overdue(18)->withLateFee()->create(['number' => 'INV-4471', 'total' => 1284.50]);

    return [$customer, $owner];
}

it('does not exist unless AI_EVAL_ENABLED is true', function () {
    expect(config('ai.eval.enabled'))->toBeFalse();

    $this->postJson('/eval/answer', ['question' => 'Hi', 'customer' => 'acme'])->assertNotFound();
});

describe('with the flag on', function () {
    beforeEach(function () {
        enableEvalRoute();

        acme();
    });

    it('wants the bearer token', function () {
        $this->postJson('/eval/answer', ['question' => 'Hi', 'customer' => 'acme'])->assertUnauthorized();

        $this->withToken('wrong')->postJson('/eval/answer', ['question' => 'Hi', 'customer' => 'acme'])->assertUnauthorized();
    });

    it('answers nobody when the token is not configured', function () {
        config(['ai.eval.token' => '']);

        $this->withToken('')->postJson('/eval/answer', ['question' => 'Hi', 'customer' => 'acme'])->assertUnauthorized();
    });

    it('validates the body', function () {
        $this->withToken('eval-token-for-tests')->postJson('/eval/answer', ['customer' => 'acme'])->assertUnprocessable()->assertJsonValidationErrors('question');
    });

    it('refuses a customer that is not a seeded fixture', function () {
        config(['ai.eval.fixture_customers' => 0]);

        $this->withToken('eval-token-for-tests')->postJson('/eval/answer', ['question' => 'Hi', 'customer' => 'acme'])->assertNotFound();

        config(['ai.eval.fixture_customers' => PHP_INT_MAX]);

        $this->withToken('eval-token-for-tests')->postJson('/eval/answer', ['question' => 'Hi', 'customer' => 'nobody'])->assertNotFound();
    });

    it('runs the whole pipeline and returns the answer, behavior, citations and what was retrieved, in the golden-set label form', function () {
        config(['ledgerly.help_version' => 'v1', 'ai.retrieval.threshold' => 0.5]);

        chunkRow([
            'content' => "Late fees › How the fee is calculated\nThe fee is 1.5% of the outstanding balance per month, prorated by day.",
            'heading' => 'Late fees › How the fee is calculated',
            'heading_path' => 'Billing › Late fees › How the fee is calculated',
        ]);

        Http::fake([
            '*/embeddings' => fn (Request $request) => Http::response(['data' => [['embedding' => fakeEmbeddingVector($request['input'])]]]),
            '*/chat/completions' => streamReply(['The fee is 1.5% of the outstanding balance per month [late-fees › How the fee is calculated].']),
        ]);

        $response = $this->withToken('eval-token-for-tests')
            ->postJson('/eval/answer', ['question' => 'Late fees › How the fee is calculated', 'customer' => 'acme'])
            ->assertOk()
            ->assertJsonStructure(['answer', 'behavior', 'citations', 'retrieved', 'message_id', 'elapsed_ms', 'prompt_version', 'sources']);

        expect($response->json('retrieved'))->toBe(['help:billing/late-fees#how-the-fee-is-calculated'])
            ->and($response->json('sources.0.text'))->toContain('1.5% of the outstanding balance')
            ->and($response->json('prompt_version'))->toBe(SupportPrompt::VERSION);
    });

    it('answers as the fixture customer\'s owner and records the retrieved labels', function () {
        Ai::fake()->for('support.reply')->respondWith('Plain answer.');

        $response = $this->withToken('eval-token-for-tests')->postJson('/eval/answer', ['question' => 'When do late fees start?', 'customer' => 'acme'])->assertOk();

        expect($response->json('answer'))->toBe('Plain answer.')
            ->and($response->json('behavior'))->toBe('answer')
            ->and($response->json('citations'))->toBe([])
            ->and($response->json('retrieved'))->toBe([]);
    });

    it('reports an escalation when the reply opened a ticket', function () {
        Ai::fake()->for('support.reply')
            ->respondWithToolCall('open_ticket', ['summary' => 'The CSV export fails.', 'invoice_number' => null])
            ->respondWith('I have opened a ticket for you.');

        Event::fake([TicketReceived::class]);

        $response = $this->withToken('eval-token-for-tests')->postJson('/eval/answer', ['question' => 'Please open a ticket: CSV export fails.', 'customer' => 'acme'])->assertOk();

        expect($response->json('behavior'))->toBe('escalate');
    });
});

it('names a fixture customer by the first word of the seeded name', function () {
    expect(EvalAnswerer::fixtureKey(new Customer(['name' => 'Acme Studios'])))->toBe('acme')
        ->and(EvalAnswerer::fixtureKey(new Customer(['name' => 'Hollis & Reed Copywriting'])))->toBe('hollis')
        ->and(EvalAnswerer::fixtureKey(new Customer(['name' => 'Gable & Finch Architects'])))->toBe('gable');
});
