<?php

use App\Ai\Privacy\Ner;
use App\Ai\Privacy\NerUnavailable;
use App\Ai\Privacy\NullNer;
use App\Ai\Privacy\PresidioClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// Chapter 34, Topic 4: the client of the privacy profile's service. The service itself is Python (ops/privacy) and has its own tests; this is the PHP side of the door, with the HTTP faked.
beforeEach(function () {
    Cache::flush();

    $this->client = new PresidioClient('http://presidio.test:8000', cooldownSeconds: 30);
})->group('privacy');

it('turns the service\'s character offsets into byte offsets', function () {
    Http::fake(['http://presidio.test:8000/*' => Http::response(['spans' => [['type' => 'PERSON', 'start' => 7, 'end' => 21, 'score' => 0.85]]])]);

    $text = 'Grüße, Dana Whitfield wrote';

    $spans = (new PresidioClient('http://presidio.test:8000'))->find($text);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->type)->toBe('PERSON')
        ->and($spans[0]->text)->toBe('Dana Whitfield')
        ->and(substr($text, $spans[0]->offset, $spans[0]->length))->toBe('Dana Whitfield');
});

it('posts the text and the threshold to /pii/analyze, once per distinct text', function () {
    Http::fake(['http://presidio.test:8000/*' => Http::response(['spans' => []])]);

    $client = new PresidioClient('http://presidio.test:8000', threshold: 0.6);

    $client->find('Hello there');
    $client->find('Hello there');

    Http::assertSentCount(1);

    Http::assertSent(fn (Request $request) => $request->url() === 'http://presidio.test:8000/pii/analyze'
        && $request['text'] === 'Hello there'
        && $request['score_threshold'] === 0.6);
});

it('closes the door for a cool-down after a failure, so a dead service costs one timeout', function () {
    $attempts = 0;

    Http::fake(['http://presidio.test:8000/*' => function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('refused');
    }]);

    expect(fn () => $this->client->find('one'))->toThrow(NerUnavailable::class)
        ->and(fn () => $this->client->find('two'))->toThrow(NerUnavailable::class)
        ->and($this->client->available())->toBeFalse()
        ->and($attempts)->toBe(1);
});

it('treats an error answer as unavailable', function () {
    Http::fake(['http://presidio.test:8000/*' => Http::response('boom', 500)]);

    expect(fn () => $this->client->find('one'))->toThrow(NerUnavailable::class, 'Presidio answered 500');
});

it('is available when /health answers, and remembers that for a few seconds', function () {
    Http::fake(['http://presidio.test:8000/health' => Http::response(['status' => 'ok'])]);

    expect($this->client->available())->toBeTrue()
        ->and($this->client->available())->toBeTrue();

    Http::assertSentCount(1);
});

it('is not available when /health does not answer', function () {
    Http::fake(['http://presidio.test:8000/health' => fn () => throw new ConnectionException('refused')]);

    expect($this->client->available())->toBeFalse();
});

it('is never available without a URL, and says why', function () {
    expect((new NullNer)->available())->toBeFalse()
        ->and((new PresidioClient(''))->available())->toBeFalse()
        ->and(fn () => (new PresidioClient(''))->find('x'))->toThrow(NerUnavailable::class, 'PRESIDIO_URL');
});

it('is what the container hands out when PRESIDIO_URL is set, and the null detector when it is not', function () {
    app()->forgetInstance(Ner::class);

    config(['ai.privacy.presidio_url' => 'http://presidio:8000']);

    expect(app(Ner::class))->toBeInstanceOf(PresidioClient::class);

    config(['ai.privacy.presidio_url' => '']);

    expect(app(Ner::class))->toBeInstanceOf(NullNer::class);
});
