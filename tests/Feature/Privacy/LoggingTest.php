<?php

use App\Ai\Privacy\Ner;
use App\Evals\Redactor;
use App\Logging\ErrorReportScrubber;
use App\Logging\ScrubLogs;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeNer;

// Chapter 34, Topic 6: the app's own copies. A stray Log::info($message) is scrubbed before it is written, an error report before it is sent, a captured evaluation case before it is committed.
beforeEach(function () {
    $this->path = sys_get_temp_dir().'/ledgerly-scrub-'.bin2hex(random_bytes(4)).'.log';

    config(['logging.channels.single.path' => $this->path, 'logging.channels.single.level' => 'debug']);

    Log::forgetChannel('single');
})->group('privacy');

afterEach(function () {
    @unlink($this->path);
});

it('taps the scrubber onto every channel that has a driver', function () {
    $channels = collect(config('logging.channels'))->filter(fn (array $channel) => isset($channel['driver']));

    expect($channels->keys()->all())->toContain('stack', 'single', 'daily', 'stderr')
        ->and($channels->filter(fn (array $channel) => ! in_array(ScrubLogs::class, $channel['tap'] ?? [], true))->keys()->all())->toBe([]);
});

it('scrubs the message and the context of a line written to a channel', function () {
    Log::channel('single')->info('Customer wrote from dana@acme.example about INV-4471', [
        'phone' => '+1 415 555 0142',
        'nested' => ['card' => '4242 4242 4242 4242', 'note' => 'ticket T-2291'],
        'count' => 3,
    ]);

    $written = file_get_contents($this->path);

    expect($written)->toContain('Customer wrote from [email] about INV-4471')
        ->and($written)->toContain('[phone]')
        ->and($written)->toContain('[card]')
        ->and($written)->toContain('T-2291')
        ->and($written)->not->toContain('dana@acme.example')
        ->and($written)->not->toContain('415 555 0142')
        ->and($written)->not->toContain('4242 4242');
});

it('scrubs an exception\'s message, whatever somebody put in it', function () {
    Log::channel('single')->error('The send failed', ['exception' => new RuntimeException('Could not deliver to dana@acme.example')]);

    expect(file_get_contents($this->path))->toContain('Could not deliver to [email]')->not->toContain('dana@acme.example');
});

it('scrubs through the default stack as well', function () {
    config(['logging.default' => 'stack', 'logging.channels.stack.channels' => ['single']]);

    Log::forgetChannel('stack');

    Log::info('Mail dana@acme.example');

    expect(file_get_contents($this->path))->toContain('Mail [email]');
});

describe('an error tracker\'s before_send', function () {
    it('scrubs an event array, strings at any depth', function () {
        $scrubbed = app(ErrorReportScrubber::class)([
            'message' => 'Failed for dana@acme.example',
            'request' => ['body' => ['text' => 'my card is 4242 4242 4242 4242'], 'url' => 'https://ledgerly.test/support'],
            'level' => 'error',
            'line' => 42,
        ]);

        expect($scrubbed)->toBe([
            'message' => 'Failed for [email]',
            'request' => ['body' => ['text' => 'my card is [card]'], 'url' => 'https://ledgerly.test/support'],
            'level' => 'error',
            'line' => 42,
        ]);
    });

    it('scrubs an SDK event object through its getters and setters', function () {
        $event = new class
        {
            public ?string $message = 'Failed for dana@acme.example';

            public array $extra = ['question' => 'call +1 415 555 0142'];

            public function getMessage(): ?string
            {
                return $this->message;
            }

            public function setMessage(?string $message): void
            {
                $this->message = $message;
            }

            public function getExtra(): array
            {
                return $this->extra;
            }

            public function setExtra(array $extra): void
            {
                $this->extra = $extra;
            }
        };

        $result = ErrorReportScrubber::beforeSend($event);

        expect($result)->toBe($event)
            ->and($event->message)->toBe('Failed for [email]')
            ->and($event->extra)->toBe(['question' => 'call [phone]']);
    });

    it('passes anything else through', function () {
        expect(ErrorReportScrubber::beforeSend(null))->toBeNull();
    });
});

describe('captured evaluation cases', function () {
    it('are redacted with the full pipeline, names included when the service is there', function () {
        app()->instance(Ner::class, FakeNer::knowing(['Dana Whitfield' => 'PERSON']));

        expect(Redactor::scrub('Dana Whitfield (dana@acme.example, card 4242 4242 4242 4242) asks about INV-4471 and T-2291'))
            ->toBe('[person] ([email], card [card]) asks about INV-4471 and T-2291');
    });

    it('still mask what the patterns find when the service is down', function () {
        app()->instance(Ner::class, tap(new FakeNer, fn ($ner) => $ner->up = false));

        expect(Redactor::scrub('Dana Whitfield, dana@acme.example'))->toBe('Dana Whitfield, [email]');
    });
});
