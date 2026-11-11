<?php

use App\Ai\Privacy\Span;
use App\Ai\Training\SurrogateRedactor;
use Faker\Factory;

// Chapter 40, Topic 3: realistic, consistent, and never a placeholder.
function trSurrogates(): SurrogateRedactor
{
    return new SurrogateRedactor(Factory::create());
}

function trSpansOf(string $text, array $values): array
{
    $spans = [];

    foreach ($values as $value => $type) {
        $offset = 0;

        while (($at = strpos($text, $value, $offset)) !== false) {
            $spans[] = new Span($type, $at, strlen($value), $value);

            $offset = $at + strlen($value);
        }
    }

    usort($spans, fn (Span $a, Span $b) => $a->offset <=> $b->offset);

    return $spans;
}

it('replaces every mention of the same value with the same invented one', function () {
    $redactor = trSurrogates();

    $redactor->startExample('conversation-1');

    $map = [];

    $text = 'Dana Whitfield wrote (dana@acme.example). Dana Whitfield is waiting; write to dana@acme.example.';

    $out = $redactor->redact($text, trSpansOf($text, ['Dana Whitfield' => 'PERSON', 'dana@acme.example' => 'EMAIL']), $map);

    expect($out)->not->toContain('Dana Whitfield')->not->toContain('dana@acme.example')
        ->and(substr_count($out, $map['Dana Whitfield']))->toBe(2)
        ->and(substr_count($out, $map['dana@acme.example']))->toBe(2);
});

it('uses an invented value of the same kind, never a placeholder', function () {
    $redactor = trSurrogates();

    $redactor->startExample('x');

    $map = [];

    $text = 'Mail a@b.example, call +44 20 7946 0958, card 4242 4242 4242 4242.';

    $out = $redactor->redact($text, trSpansOf($text, ['a@b.example' => 'EMAIL', '+44 20 7946 0958' => 'PHONE', '4242 4242 4242 4242' => 'CARD']), $map);

    expect($out)->not->toMatch('/<[A-Z]+_\d+>/')
        ->and($map['a@b.example'])->toContain('@')
        ->and(preg_replace('/\D/', '', $map['4242 4242 4242 4242']))->toHaveLength(16);
});

it('is the same invented value every time for the same example, and another for another example', function () {
    $text = 'Dana Whitfield asked.';

    $run = function (string $seed) use ($text) {
        $redactor = trSurrogates();

        $redactor->startExample($seed);

        $map = [];

        return $redactor->redact($text, trSpansOf($text, ['Dana Whitfield' => 'PERSON']), $map);
    };

    expect($run('a'))->toBe($run('a'))
        ->and($run('a'))->not->toBe($run('b'));
});

it('keeps a type it has no generator for as a bracketed type, and leaves a text with no spans alone', function () {
    $redactor = trSurrogates();

    $map = [];

    expect($redactor->redact('Lives in Lisbon.', trSpansOf('Lives in Lisbon.', ['Lisbon' => 'ADDRESS']), $map))->toBe('Lives in [ADDRESS].')
        ->and($redactor->redact('Nothing here.', [], $map))->toBe('Nothing here.');
});
