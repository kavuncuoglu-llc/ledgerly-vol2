<?php

use App\Ai\Prompts\TicketClassifyPrompt;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function fakeClassifier(array $overrides = []): void
{
    $labels = collect(ticketSamples())->pluck('label', 'message')->all();

    Http::fake(['*/chat/completions' => function (Request $request) use ($labels, $overrides) {
        $message = unwrapped(collect($request['messages'])->last()['content']);

        $label = $overrides[$message] ?? $labels[$message] ?? 'other';

        return chatReply(ticketJson($label));
    }]);
}

it('ships twenty sample tickets with a human label from the allowed set', function () {
    $samples = ticketSamples();

    expect($samples)->toHaveCount(20);

    foreach ($samples as $sample) {
        expect($sample['label'])->toBeIn(TicketClassifyPrompt::LABELS)
            ->and($sample['message'])->toBeString()->not->toBeEmpty();
    }
});

it('reports 18/20 and lists the two misses', function () {
    fakeClassifier([
        'Invoice shows paid but my bank shows nothing' => 'bug',
        'Can I invite my accountant?' => 'account',
    ]);

    $this->artisan('ticket:classify')
        ->expectsOutputToContain('18/20 agree with human labels (90%)')
        ->expectsOutputToContain('misses:')
        ->expectsOutputToContain(sprintf('%-52s model: %-8s human: %s', '"Invoice shows paid but my bank shows nothing"', 'bug', 'billing'))
        ->expectsOutputToContain(sprintf('%-52s model: %-8s human: %s', '"Can I invite my accountant?"', 'account', 'howto'))
        ->assertSuccessful();

    Http::assertSentCount(20);
});

it('reports a perfect score without a misses list', function () {
    fakeClassifier();

    $this->artisan('ticket:classify')
        ->expectsOutputToContain('20/20 agree with human labels (100%)')
        ->doesntExpectOutputToContain('misses:')
        ->assertSuccessful();
});

it('reads another file', function () {
    $path = sys_get_temp_dir().'/ledgerly-tickets-'.bin2hex(random_bytes(4)).'.json';

    file_put_contents($path, json_encode([['message' => 'Export is broken', 'label' => 'bug']]));

    Http::fake(['*/chat/completions' => chatReply(ticketJson('bug'))]);

    $this->artisan('ticket:classify', ['path' => $path])
        ->expectsOutputToContain('1/1 agree with human labels (100%)')
        ->assertSuccessful();

    unlink($path);
});

it('fails on a missing file', function () {
    Http::fake();

    $this->artisan('ticket:classify', ['path' => '/no/such/file.json'])->assertFailed();

    Http::assertNothingSent();
});

it('says so when the model server is down', function () {
    Sleep::fake();

    Http::fake(['*/chat/completions' => Http::failedConnection()]);

    $this->artisan('ticket:classify')
        ->expectsOutputToContain('Could not reach the model server')
        ->assertFailed();
});
