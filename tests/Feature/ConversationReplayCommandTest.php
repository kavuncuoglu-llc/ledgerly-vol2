<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();

    $this->seed();
});

function scriptFile(array $messages, ?string $followUp = null): string
{
    $path = sys_get_temp_dir().'/replay-'.bin2hex(random_bytes(4)).'.json';

    file_put_contents($path, json_encode(['messages' => $messages, 'follow_up' => $followUp]));

    register_shutdown_function(fn () => @unlink($path));

    return $path;
}

it('ships a thirty-message script with a follow-up about the first invoice', function () {
    $script = json_decode(file_get_contents(base_path('storage/samples/long-conversation.json')), true);

    expect($script['messages'])->toHaveCount(30)
        ->and($script['messages'][1])->toContain('INV-4471')
        ->and($script['follow_up'])->toContain('first invoice');
});

it('replays the script as Maya, one turn per line, with the prompt size and the summary state', function () {
    Http::fake(['*/chat/completions' => fn () => chatReply('An answer.', usage: ['prompt_tokens' => 612, 'completion_tokens' => 30])]);

    $this->artisan('conversation:replay', ['path' => scriptFile(['one', 'two', 'three'], 'Back to the start?')])
        ->expectsOutputToContain('turn  1  prompt 612    summary: no')
        ->expectsOutputToContain('turn  3  prompt 612    summary: no')
        ->expectsOutputToContain('turn 4 question: Back to the start?')
        ->expectsOutputToContain('An answer.')
        ->assertSuccessful();

    $conversation = Conversation::sole();

    expect($conversation->user_id)->toBe(12)
        ->and(Message::where('conversation_id', $conversation->id)->count())->toBe(8)
        ->and(ModelCall::where('conversation_id', $conversation->id)->count())->toBe(4);
});

it('prints every Nth turn when asked', function () {
    Http::fake(['*/chat/completions' => fn () => chatReply('Ok.')]);

    Artisan::call('conversation:replay', ['path' => scriptFile(range(1, 6)), '--every' => 3]);

    $output = Artisan::output();

    expect($output)->toContain('turn  1 ')
        ->and($output)->toContain('turn  3 ')
        ->and($output)->toContain('turn  6 ')
        ->and($output)->not->toContain('turn  2 ')
        ->and($output)->not->toContain('turn  4 ');
});

it('can replay as another user', function () {
    Http::fake(['*/chat/completions' => fn () => chatReply('Ok.')]);

    $this->artisan('conversation:replay', ['path' => scriptFile(['one']), '--user' => 13])->assertSuccessful();

    expect(Conversation::sole()->user_id)->toBe(13);
});

it('reports a missing script and a missing user', function () {
    $this->artisan('conversation:replay', ['path' => 'storage/samples/nope.json'])->expectsOutputToContain('No script at storage/samples/nope.json.')->assertFailed();

    $this->artisan('conversation:replay', ['path' => scriptFile(['one']), '--user' => 99999])->expectsOutputToContain('There is no user 99999.')->assertFailed();
});

it('says so when the model server is down', function () {
    Http::fake(fn () => throw new ConnectionException('refused'));

    $this->artisan('conversation:replay', ['path' => scriptFile(['one'])])->expectsOutputToContain('Could not reach the model server')->assertFailed();
});

it('uses a real seeded user whose tenant is the customer account', function () {
    expect(User::find(12)->tenant_id)->toBe(12);
});
