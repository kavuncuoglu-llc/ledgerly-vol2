<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();

    $this->seed();
});

function usersScript(array $messages): string
{
    $path = sys_get_temp_dir().'/replay-users-'.bin2hex(random_bytes(4)).'.json';

    file_put_contents($path, json_encode(['messages' => $messages]));

    register_shutdown_function(fn () => @unlink($path));

    return $path;
}

it('drives N simulated users, one tenant each, through the queue and reports the numbers of Figure 24-8', function () {
    Http::fake(['*/chat/completions' => streamReply(['An ', 'answer.'], usage: ['prompt_tokens' => 612, 'completion_tokens' => 30])]);

    $this->artisan('conversation:replay', ['path' => usersScript(['one', 'two', 'three']), '--users' => 4])
        ->expectsOutputToContain('4 users × 3 turns through the sync queue')
        ->expectsOutputToContain('QUEUE_CONNECTION is sync')
        ->expectsOutputToContain('0 failures · aggregate')
        ->assertSuccessful();

    $conversations = Conversation::withoutGlobalScopes()->get();

    expect($conversations)->toHaveCount(4)
        ->and($conversations->pluck('user_id')->unique())->toHaveCount(4)
        ->and($conversations->pluck('tenant_id')->unique())->toHaveCount(4)                      // four different tenants, so nobody shares a cache row or a memory
        ->and($conversations->pluck('user_id')->all())->toContain(12)
        ->and(Message::withoutGlobalScopes()->whereNotNull('question')->where('status', 'completed')->count())->toBe(12)
        ->and(ModelCall::where('feature', 'support.reply')->count())->toBe(12);
});

it('keeps each user\'s turns in order and waits for each answer before the next question', function () {
    Http::fake(['*/chat/completions' => streamReply(['Ok.'])]);

    $this->artisan('conversation:replay', ['path' => usersScript(['first', 'second']), '--users' => 2])->assertSuccessful();

    foreach (Conversation::withoutGlobalScopes()->get() as $conversation) {
        $questions = Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)->where('role', 'user')->orderBy('seq')->pluck('content')->all();

        expect($questions)->toBe(['first', 'second']);
    }
});

it('counts a failed reply as a failure and exits non-zero', function () {
    Http::fake(['*/chat/completions' => Http::response(['error' => 'engine dead'], 500)]);

    $this->artisan('conversation:replay', ['path' => usersScript(['one']), '--users' => 2])
        ->expectsOutputToContain('2 failures · aggregate')
        ->assertFailed();
});

it('warns when fewer owners exist than users asked for', function () {
    Http::fake(['*/chat/completions' => streamReply(['Ok.'])]);

    $owners = User::where('role', 'owner')->whereNotNull('tenant_id')->count();

    $this->artisan('conversation:replay', ['path' => usersScript(['one']), '--users' => $owners + 5])
        ->expectsOutputToContain("Only {$owners} users can be simulated")
        ->assertSuccessful();
});
