<?php

use App\Ai\Prompts\InvoicePrompt;

it('builds a system turn and one user turn with the text and the image', function () {
    $messages = InvoicePrompt::messages('data:image/jpeg;base64,AAAA');

    expect($messages)->toBe([
        ['role' => 'system', 'content' => InvoicePrompt::system()],
        ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => InvoicePrompt::extract()],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,AAAA']],
        ]],
    ]);
});

it('is versioned with its text', function () {
    expect(md5(InvoicePrompt::system().'|'.InvoicePrompt::extract()))->toBe('ee8e8aa3331e7e5069808782e300301d');
});
