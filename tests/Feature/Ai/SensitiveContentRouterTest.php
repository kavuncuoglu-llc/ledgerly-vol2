<?php

use App\Ai\Prompts\SupportPrompt;
use App\Ai\Sensitive\LocalModelClassifier;
use App\Ai\Sensitive\NullClassifier;
use App\Ai\Sensitive\RefusalDetector;
use App\Ai\Sensitive\RoutingDecision;
use App\Ai\Sensitive\SensitiveClassifier;
use App\Ai\Sensitive\SensitiveContentRouter;
use App\Ai\Sensitive\SensitiveRouting;
use App\Ai\Widget\PublicWidget;
use App\Facades\Ai;
use App\Models\Customer;
use App\Models\Message;
use App\Models\SensitiveRoutingRecord;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Chapter 51, Topics 7 and 9: a message that needs a person gets one before any model reads it.
function fixtures(): array
{
    return array_map(fn (string $line) => json_decode($line, true), array_filter(explode("\n", file_get_contents(base_path('tests/Fixtures/sensitive/fixtures.jsonl')))));
}

function asker(): User
{
    return User::factory()->owner(Customer::factory()->create())->create();
}

describe('the 40 fixtures', function () {
    it('has the mix the chapter describes', function () {
        $rows = collect(fixtures());

        expect($rows)->toHaveCount(40)
            ->and($rows->where('category', 'safety'))->toHaveCount(14)
            ->and($rows->where('category', 'out_of_scope'))->toHaveCount(16)
            ->and($rows->where('category', 'in_scope'))->toHaveCount(10)
            ->and($rows->pluck('id')->unique())->toHaveCount(40);
    });

    it('routes every fixture as the support team wrote it', function () {
        $wrong = [];

        foreach (fixtures() as $row) {
            $decision = app(SensitiveContentRouter::class)->route($row['message']);

            if ([$decision->category, $decision->subcategory, $decision->route] !== [$row['category'], $row['subcategory'], $row['route']]) {
                $wrong[] = "{$row['id']}: expected {$row['category']}/{$row['subcategory']} got {$decision->category}/{$decision->subcategory}";
            }
        }

        expect($wrong)->toBe([]);
    });

    it('sends every safety fixture to a person with priority and none to the assistant', function () {
        $safety = collect(fixtures())->where('category', 'safety');

        $routes = $safety->map(fn (array $row) => app(SensitiveContentRouter::class)->route($row['message'])->route)->countBy();

        expect($routes->all())->toBe(['person_priority' => 14]);
    });

    it('never sends an in-scope fixture anywhere but the assistant', function () {
        foreach (collect(fixtures())->where('category', 'in_scope') as $row) {
            expect(app(SensitiveContentRouter::class)->route($row['message'])->route)->toBe('assistant', $row['message']);
        }
    });
});

describe('reading a message', function () {
    it('reads safety before scope, so a message that is both goes to a person', function () {
        $decision = app(SensitiveContentRouter::class)->route('I want to dispute an invoice and honestly I want to kill myself over it.');

        expect($decision->route)->toBe('person_priority');
    });

    it('is not fooled by curly apostrophes, capitals or extra space', function () {
        expect(app(SensitiveContentRouter::class)->route('I DON’T   want to   BE ALIVE anymore')->category)->toBe('safety');
    });

    it('sends harassment of the staff to a person, without priority', function () {
        $decision = app(SensitiveContentRouter::class)->route('You stupid idiots, fuck you and your useless bot.');

        expect($decision->category)->toBe('harassment')
            ->and($decision->route)->toBe('person');
    });

    it('has a text a person wrote for every route it can take', function () {
        foreach (array_keys(config('sensitive-content.safety')) as $kind) {
            expect(__("sensitive.holding.{$kind}"))->not->toBe("sensitive.holding.{$kind}")->and(strlen(__("sensitive.holding.{$kind}")))->toBeGreaterThan(60);
        }

        foreach (array_keys(config('sensitive-content.out_of_scope')) as $kind) {
            expect(__("sensitive.decline.{$kind}"))->not->toBe("sensitive.decline.{$kind}")->and(__("sensitive.decline.{$kind}"))->toContain('person');
        }

        expect(__('sensitive.holding.harassment'))->not->toBe('sensitive.holding.harassment');
    });

    it('points the safety holding messages at urgent help and fills nothing in from the message', function () {
        expect(__('sensitive.holding.distress'))->toContain('emergency')
            ->and(__('sensitive.holding.distress'))->not->toContain(':')
            ->and(__('sensitive.holding.threat_to_others'))->toContain('emergency');
    });
});

describe('the classifier', function () {
    it('is off unless asked for', function () {
        expect(app(SensitiveClassifier::class))->toBeInstanceOf(NullClassifier::class);

        config(['sensitive-content.classifier.enabled' => true]);

        expect(app(SensitiveClassifier::class))->toBeInstanceOf(LocalModelClassifier::class);
    });

    it('can only move a message towards a person', function () {
        config(['sensitive-content.classifier.enabled' => true]);

        Ai::fake()->for('sensitive.classify')->respondWith('distress');

        $decision = app(SensitiveContentRouter::class)->route('Nothing seems worth the effort lately and I am so tired of everything.');

        expect($decision->route)->toBe('person_priority')
            ->and($decision->source)->toBe('classifier');
    });

    it('treats none, an unknown word and a failure as no opinion', function (string $reply) {
        config(['sensitive-content.classifier.enabled' => true]);

        Ai::fake()->for('sensitive.classify')->respondWith($reply);

        expect(app(SensitiveContentRouter::class)->route('How do I change my invoice template?')->route)->toBe('assistant');
    })->with(['none', 'banana', '']);

    it('does not clear a message the patterns held back', function () {
        config(['sensitive-content.classifier.enabled' => true]);

        Ai::fake()->for('sensitive.classify')->respondWith('none');

        expect(app(SensitiveContentRouter::class)->route('I want to end my life')->route)->toBe('person_priority');
    });
});

describe('before the assistant answers', function () {
    it('brings a person in with priority, shows words a person wrote, and calls no model', function () {
        $user = asker();

        Http::fake();

        $reply = runAnswerQuestion('I can\'t keep doing this. I don\'t want to be alive anymore.', as: $user);

        Http::assertNothingSent();

        $ticket = Ticket::where('source', 'sensitive_router')->firstOrFail();

        expect($reply->status)->toBe('completed')
            ->and($reply->content)->toBe(__('sensitive.holding.distress'))
            ->and($ticket->urgency)->toBe('urgent')
            ->and($ticket->customer_id)->toBe($user->customer_id)
            ->and($ticket->summary)->toContain('safety (distress)')
            ->and($ticket->summary)->not->toContain('alive');
    });

    it('declines out-of-scope questions helpfully and offers a person, again without a model', function () {
        Http::fake();

        $reply = runAnswerQuestion('Can I deduct my home office on my tax return?', as: asker());

        Http::assertNothingSent();

        expect($reply->content)->toBe(__('sensitive.decline.tax'))
            ->and(Ticket::where('source', 'sensitive_router')->first()->urgency)->toBe('normal');
    });

    it('logs the category and the decision and never what the customer wrote', function () {
        $message = 'I\'m going to hurt my business partner when I see him. SECRET-PHRASE-9137';

        runAnswerQuestion($message, as: asker());

        $row = SensitiveRoutingRecord::firstOrFail();

        $everything = json_encode(DB::table('sensitive_routings')->get()).json_encode(DB::table('tickets')->get());

        expect($row->category)->toBe('safety')
            ->and($row->subcategory)->toBe('threat_to_others')
            ->and($row->decision)->toBe('person_priority')
            ->and($row->ticket_id)->not->toBeNull()
            ->and($everything)->not->toContain('SECRET-PHRASE-9137')
            ->and($everything)->not->toContain('business partner');
    });

    it('records one row however often the job runs', function () {
        $reply = runAnswerQuestion('Please give me legal advice about a client who refuses to pay.', as: asker());

        $handler = app(SensitiveRouting::class);

        $handler->handle(Message::withoutGlobalScopes()->find($reply->id), $handler->decide($reply));

        expect(SensitiveRoutingRecord::count())->toBe(1)
            ->and(Ticket::where('source', 'sensitive_router')->count())->toBe(1);
    });

    it('lets an in-scope question through to the assistant', function () {
        Ai::fake()->for('support.reply')->respondWith('Open Settings, choose Subscription, then Change plan.');

        $reply = runAnswerQuestion('How do I terminate my plan?', as: asker());

        expect($reply->content)->toContain('Subscription')
            ->and(SensitiveRoutingRecord::count())->toBe(0);
    });
});

describe('false refusals', function () {
    it('escalates an in-scope question the assistant declined, and logs it', function () {
        Ai::fake()->for('support.reply')->respondWith(SupportPrompt::escapeHatch());

        $user = asker();

        $reply = runAnswerQuestion('How do I terminate my plan?', as: $user);

        $row = SensitiveRoutingRecord::firstOrFail();

        $ticket = Ticket::where('source', 'sensitive_router')->firstOrFail();

        expect($row->decision)->toBe('false_refusal')
            ->and($row->subcategory)->toBe('false_refusal')
            ->and($row->source)->toBe('detector')
            ->and($ticket->urgency)->toBe('normal')
            ->and($reply->content)->toBe(SupportPrompt::escapeHatch());
    });

    it('does not count a decline of something the router already sent to a person', function () {
        $detector = app(RefusalDetector::class);

        $routed = new RoutingDecision(RoutingDecision::OUT_OF_SCOPE, 'tax', RoutingDecision::DECLINE_AND_PERSON);

        expect($detector->isFalseRefusal($routed, SupportPrompt::escapeHatch()))->toBeFalse()
            ->and($detector->isFalseRefusal(new RoutingDecision(RoutingDecision::IN_SCOPE, 'in_scope', RoutingDecision::ASSISTANT), 'Open Settings and choose Change plan.'))->toBeFalse();
    });
});

describe('the public widget', function () {
    it('gives a person\'s words to a safety concern, before any budget or model, and logs the category only', function () {
        Http::fake();

        $answer = app(PublicWidget::class)->ask('I can\'t keep doing this. I don\'t want to be alive anymore.', 'session-1');

        Http::assertNothingSent();

        expect($answer->text)->toBe(__('sensitive.holding.distress'))
            ->and(SensitiveRoutingRecord::first()->decision)->toBe('person_priority')
            ->and(SensitiveRoutingRecord::first()->message_id)->toBeNull();
    });

    it('leaves an out-of-scope question to the widget\'s own template', function () {
        expect(app(SensitiveRouting::class)->forAnonymous('Can I deduct my home office on my tax return?'))->toBeNull();
    });
});
