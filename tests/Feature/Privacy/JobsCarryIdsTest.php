<?php

use Illuminate\Contracts\Queue\ShouldQueue;
use Symfony\Component\Finder\Finder;

// Chapter 34, Topic 6: a job's properties are serialized into its payload, and from there into Redis, Horizon and failed_jobs. Jobs carry ids and load the text inside handle().
function queuedClasses(): array
{
    $classes = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 3).'/app')->name('*.php') as $file) {
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (! class_exists($class)) {
            continue;
        }

        if (! is_subclass_of($class, ShouldQueue::class)) {
            continue;
        }

        $classes[] = $class;
    }

    return $classes;
}

function contentProperties(string $class): array
{
    $found = [];

    $framework = collect(class_uses_recursive($class))
        ->flatMap(fn (string $trait) => array_map(fn (ReflectionProperty $p) => $p->getName(), (new ReflectionClass($trait))->getProperties()))
        ->all();                                                              // Queueable's own (queue, connection, messageGroup) are not ours

    foreach ((new ReflectionClass($class))->getProperties() as $property) {
        if (in_array($property->getName(), $framework, true)) {
            continue;
        }

        $type = (string) $property->getType();

        $textual = in_array(ltrim($type, '?'), ['string', 'mixed', ''], true);

        if (! $textual) {
            continue;
        }

        if (preg_match('/content|text|body|question|answer|message|prompt|subject|summary|email|name/i', $property->getName()) === 1) {
            $found[] = $property->getName();
        }
    }

    return $found;
}

it('has queued classes to look at', function () {
    expect(queuedClasses())->toContain('App\Jobs\AnswerQuestion', 'App\Jobs\ResumeAgentRun', 'App\Jobs\ShadowAnswer');
})->group('privacy');

it('has no job or queued listener that carries message content', function (string $class) {
    expect(contentProperties($class))->toBe([], "{$class} carries text in its payload: pass an id and load the text in handle()");
})->with(fn () => queuedClasses())->group('privacy');

it('catches a job that does', function () {
    $job = new class implements ShouldQueue
    {
        public function __construct(public string $question = 'Why is INV-4471 late?') {}
    };

    expect(contentProperties($job::class))->toBe(['question']);
})->group('privacy');
