<?php

use App\Ai\Security\AnswerRenderer;

beforeEach(function () {
    config(['app.url' => 'https://app.ledgerly.test', 'ai.security.link_hosts' => ['help.ledgerly.test']]);
});

it('removes an image from an unknown host and keeps nothing of it', function () {
    expect((new AnswerRenderer)->render('Done. ![x](https://attacker.example/c?d=4471)'))->toBe('Done.');
});

it('keeps the words of a link to an unknown host and drops the address', function () {
    expect((new AnswerRenderer)->render('See [the guide](https://attacker.example/guide) now'))->toBe('See the guide now');
});

it('keeps links to listed hosts, the app host, their subdomains and relative paths', function (string $text) {
    expect((new AnswerRenderer)->render($text))->toBe($text);
})->with([
    'help' => 'Read [this](https://help.ledgerly.test/billing).',
    'app' => 'Open [it](https://app.ledgerly.test/invoices).',
    'subdomain' => 'Open [it](https://eu.help.ledgerly.test/x).',
    'relative' => 'Read [this](/help/billing/late-fees).',
]);

it('removes bare addresses, html images and html links to unknown hosts', function () {
    $out = (new AnswerRenderer)->render('Go to https://attacker.example/x <img src="https://attacker.example/p.png"> <a href="https://attacker.example/y">click</a>');

    expect($out)->not->toContain('attacker.example')->toContain('click');
});

it('treats other schemes as unknown', function (string $text) {
    expect((new AnswerRenderer)->render($text))->not->toContain('javascript:')->not->toContain('data:');
})->with([
    'javascript' => '[x](javascript:alert(1))',
    'data' => '![x](data:text/html;base64,AAAA)',
]);

it('names the hosts it would strip', function () {
    expect((new AnswerRenderer)->strippedHosts('![a](https://Attacker.example/x) and [b](https://help.ledgerly.test/y)'))->toBe(['attacker.example']);
});
