<?php

it('prints the ratio estimate for some text', function () {
    $this->artisan('ai:tokens', ['text' => 'Invoice #4471 is overdue by 30 days.', '--no-exact' => true, '--ratio' => '4'])
        ->expectsOutputToContain('Characters: 36')
        ->expectsOutputToContain('ratio (4 chars/token)')
        ->assertSuccessful();
});

it('reads the text from a file', function () {
    $path = tempnam(sys_get_temp_dir(), 'tokens');
    file_put_contents($path, str_repeat('a', 40));

    $this->artisan('ai:tokens', ['--file' => $path, '--no-exact' => true, '--ratio' => '4'])
        ->expectsOutputToContain('Characters: 40')
        ->assertSuccessful();

    unlink($path);
});

it('fails when there is nothing to count', function () {
    $this->artisan('ai:tokens', ['--no-exact' => true])->assertFailed();
});
