<?php

use App\Ai\Prompts\PromptLock;
use App\Ai\Prompts\SupportPrompt;
use Illuminate\Support\Facades\File;

// Chapter 29, Topic 3: a prompt's text may only change together with its VERSION. prompts.lock holds the reviewed hash of each version.
it('has a reviewed hash for the current version of every prompt, and the text still matches it', function (string $class) {
    $version = $class::VERSION;

    $reviewed = PromptLock::reviewedHashFor($class, $version);

    $this->assertNotNull($reviewed, "{$class} {$version} is not in prompts.lock: run php artisan prompts:lock after review");

    $this->assertSame($reviewed, PromptLock::hashOf($class), "{$class}'s text changed but VERSION did not: bump VERSION, update its snapshot, run php artisan prompts:lock");
})->with(fn () => collect(PromptLock::prompts())->mapWithKeys(fn (string $class) => [class_basename($class) => [$class]])->all());

it('finds every prompt class', function () {
    expect(PromptLock::prompts())->toContain(SupportPrompt::class)->and(count(PromptLock::prompts()))->toBeGreaterThanOrEqual(10);
});

it('tells you which prompt changed without a new VERSION', function () {
    $original = File::get(PromptLock::path());

    $lock = json_decode($original, true);

    $lock['SupportPrompt'][SupportPrompt::VERSION] = str_repeat('0', 64);

    File::put(PromptLock::path(), json_encode($lock));

    try {
        $this->artisan('prompts:lock', ['--check' => true])
            ->expectsOutputToContain('SupportPrompt '.SupportPrompt::VERSION.': the text changed but VERSION did not')
            ->assertExitCode(1);
    } finally {
        File::put(PromptLock::path(), $original);
    }
});

it('accepts the lock as committed', function () {
    $this->artisan('prompts:lock', ['--check' => true])->assertExitCode(0);
});
