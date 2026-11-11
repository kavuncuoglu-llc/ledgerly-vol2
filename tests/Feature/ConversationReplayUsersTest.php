<?php

use App\Models\AnswerFeedback;
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

// Chapter 30, Build: a directory of short scripts is shared out among the simulated users, and a share of them click a thumb afterwards.
it('replays a directory of scripts among the users and rates a share of the answers', function () {
    Http::fake(['*/chat/completions' => streamReply(['An ', 'answer.'])]);

    $dir = sys_get_temp_dir().'/replay-seeded-'.bin2hex(random_bytes(4));

    mkdir($dir);
    file_put_contents("$dir/a.json", json_encode(['messages' => ['one', 'two']]));
    file_put_contents("$dir/b.json", json_encode(['messages' => ['three', 'four', 'five']]));

    $this->artisan('conversation:replay', ['path' => $dir, '--users' => 4, '--feedback-rate' => 1])
        ->expectsOutputToContain('4 users × up to 3 turns, 2 scripts')
        ->expectsOutputToContain('0 failures')
        ->expectsOutputToContain('feedback: 10 of 10 answers rated (100.0%)')
        ->assertSuccessful();

    expect(Message::withoutGlobalScopes()->whereNotNull('question')->where('status', 'completed')->count())->toBe(10)      // two users × 2 turns, two users × 3 turns
        ->and(AnswerFeedback::withoutGlobalScopes()->count())->toBe(10);
});

it('rates nothing without --feedback-rate', function () {
    Http::fake(['*/chat/completions' => streamReply(['An ', 'answer.'])]);

    $dir = sys_get_temp_dir().'/replay-seeded-'.bin2hex(random_bytes(4));

    mkdir($dir);
    file_put_contents("$dir/a.json", json_encode(['messages' => ['one']]));

    $this->artisan('conversation:replay', ['path' => $dir, '--users' => 2])->assertSuccessful();

    expect(AnswerFeedback::withoutGlobalScopes()->count())->toBe(0);
});

it('has seeded scripts in the repository', function () {
    $scripts = glob(base_path('storage/samples/seeded/*.json'));

    expect($scripts)->toHaveCount(3);

    foreach ($scripts as $script) {
        expect(json_decode(file_get_contents($script), true)['messages'])->toHaveCount(3);
    }
});
