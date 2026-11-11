<?php

use App\Ai\Privacy\ArrayVaultStore;
use App\Ai\Privacy\Span;
use App\Ai\Privacy\StreamRestorer;
use App\Ai\Privacy\Vault;
use App\Ai\Privacy\VaultFactory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

pest()->group('privacy');

function vaultFor(int|string|null $conversation = 14, ?ArrayVaultStore $store = null): Vault
{
    return (new VaultFactory($store ?? new ArrayVaultStore, 3600))->for($conversation);
}

function spanOf(string $text, string $value, string $type): Span
{
    return new Span($type, strpos($text, $value), strlen($value), $value);
}

describe('the vault', function () {
    it('swaps values for typed, numbered placeholders, right to left so offsets hold', function () {
        $text = 'Send it to dana@acme.example or call +1 415 555 0142.';

        $tokenized = vaultFor()->tokenize($text, [spanOf($text, 'dana@acme.example', 'EMAIL'), spanOf($text, '+1 415 555 0142', 'PHONE')]);

        expect($tokenized)->toBe('Send it to <EMAIL_1> or call <PHONE_1>.');
    });

    it('gives the same value the same placeholder every time, and a second value the next number', function () {
        $vault = vaultFor();

        $first = 'a dana@acme.example b';
        $second = 'c sam@acme.example d dana@acme.example';

        expect($vault->tokenize($first, [spanOf($first, 'dana@acme.example', 'EMAIL')]))->toBe('a <EMAIL_1> b');

        $tokenized = $vault->tokenize($second, [spanOf($second, 'sam@acme.example', 'EMAIL'), new Span('EMAIL', strrpos($second, 'dana@acme.example'), 17, 'dana@acme.example')]);

        expect($tokenized)->toBe('c <EMAIL_2> d <EMAIL_1>');
    });

    it('is shared by every call in one conversation and by nobody else', function () {
        $store = new ArrayVaultStore;

        $text = 'dana@acme.example';

        vaultFor(14, $store)->tokenize($text, [spanOf($text, $text, 'EMAIL')]);

        expect(vaultFor(14, $store)->restore('<EMAIL_1>'))->toBe('dana@acme.example')
            ->and(vaultFor(15, $store)->restore('<EMAIL_1>'))->toBe('<EMAIL_1>');
    });

    it('keeps the map encrypted at rest', function () {
        $store = new ArrayVaultStore;

        $text = 'dana@acme.example';

        vaultFor(14, $store)->tokenize($text, [spanOf($text, $text, 'EMAIL')]);

        $stored = $store->get('vault:14');

        expect($stored)->not->toContain('dana')
            ->and(json_decode(Crypt::decryptString($stored), true))->toBe(['EMAIL_1' => 'dana@acme.example']);
    });

    it('lets a vault expire with the conversation', function () {
        $store = new ArrayVaultStore;

        $factory = new VaultFactory($store, -1);

        $text = 'dana@acme.example';

        $factory->for(14)->tokenize($text, [spanOf($text, $text, 'EMAIL')]);

        expect($factory->for(14)->restore('<EMAIL_1>'))->toBe('<EMAIL_1>');
    });

    it('gives a call with no conversation a vault that dies with it', function () {
        $store = new ArrayVaultStore;

        $factory = new VaultFactory($store, 3600);

        $text = 'dana@acme.example';

        $vault = $factory->for(null);

        $vault->tokenize($text, [spanOf($text, $text, 'EMAIL')]);

        expect($vault->restore('<EMAIL_1>'))->toBe('dana@acme.example')
            ->and($store->keys())->toBe([])
            ->and($factory->for(null)->restore('<EMAIL_1>'))->toBe('<EMAIL_1>');
    });

    it('forgets a conversation\'s vault', function () {
        $store = new ArrayVaultStore;

        $text = 'dana@acme.example';

        vaultFor(14, $store)->tokenize($text, [spanOf($text, $text, 'EMAIL')]);

        (new VaultFactory($store, 3600))->forget(14);

        expect($store->keys())->toBe([]);
    });
});

describe('restoring', function () {
    beforeEach(function () {
        $text = 'dana@acme.example';

        $this->vault = vaultFor();

        $this->vault->tokenize($text, [spanOf($text, $text, 'EMAIL')]);
    });

    it('swaps a placeholder back', function () {
        expect($this->vault->restore('Send it to <EMAIL_1>.'))->toBe('Send it to dana@acme.example.');
    });

    it('tolerates a model that changes a placeholder, and logs a warning for it', function (string $mangled) {
        Log::spy();

        expect($this->vault->restore("Send it to {$mangled}."))->toBe('Send it to dana@acme.example.');

        Log::shouldHaveReceived('warning')->once()->with('redaction.placeholder_mangled', Mockery::on(fn ($context) => $context['placeholder'] === $mangled));
    })->with(['<Email 1>', 'EMAIL_1', '<email-1>', '< EMAIL_1 >']);

    it('does not log for a well-formed placeholder', function () {
        Log::spy();

        $this->vault->restore('<EMAIL_1>');

        Log::shouldNotHaveReceived('warning');
    });

    it('leaves a placeholder it never issued', function () {
        expect($this->vault->restore('<EMAIL_2> and <PHONE_1>'))->toBe('<EMAIL_2> and <PHONE_1>');
    });

    it('restores arguments at any depth, strings only', function () {
        expect($this->vault->restoreArguments(['new_email' => '<EMAIL_1>', 'meta' => ['note' => 'to <EMAIL_1>', 'count' => 3]]))
            ->toBe(['new_email' => 'dana@acme.example', 'meta' => ['note' => 'to dana@acme.example', 'count' => 3]]);
    });
});

describe('the stream restorer', function () {
    function streamed(Vault $vault, array $deltas): array
    {
        $restorer = new StreamRestorer($vault);

        $out = array_map(fn (string $delta) => $restorer->feed($delta), $deltas);

        $out[] = $restorer->flush();

        return $out;
    }

    beforeEach(function () {
        $text = 'dana@acme.example +1 415 555 0142';

        $this->vault = vaultFor();

        $this->vault->tokenize($text, [spanOf($text, 'dana@acme.example', 'EMAIL'), spanOf($text, '+1 415 555 0142', 'PHONE')]);
    });

    it('passes ordinary text straight through', function () {
        expect(streamed($this->vault, ['Hello ', 'there']))->toBe(['Hello ', 'there', '']);
    });

    it('restores a placeholder split across two deltas, once', function () {
        $out = streamed($this->vault, ['Write to <EMA', 'IL_1> now']);

        expect(implode('', $out))->toBe('Write to dana@acme.example now')
            ->and($out[0])->toBe('Write to ');
    });

    it('restores a placeholder split across three deltas', function () {
        expect(implode('', streamed($this->vault, ['Write to <', 'EMAIL', '_1>.'])))->toBe('Write to dana@acme.example.');
    });

    it('holds a bare upper-case placeholder that is split in the middle', function () {
        expect(implode('', streamed($this->vault, ['Write to EMA', 'IL_1 now'])))->toBe('Write to dana@acme.example now');
    });

    it('lets a "<" that is not a placeholder go once the buffer has to', function () {
        expect(implode('', streamed($this->vault, ['if a < b then ', 'carry on'])))->toBe('if a < b then carry on');
    });

    it('flushes what is left when the stream ends inside a placeholder', function () {
        expect(implode('', streamed($this->vault, ['Write to <EMA'])))->toBe('Write to <EMA');
    });

    it('restores two placeholders in one delta', function () {
        expect(implode('', streamed($this->vault, ['<EMAIL_1> and <PHONE_1>'])))->toBe('dana@acme.example and +1 415 555 0142');
    });
});
