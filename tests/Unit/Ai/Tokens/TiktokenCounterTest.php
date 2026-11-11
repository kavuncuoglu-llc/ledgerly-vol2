<?php

use App\Ai\Tokens\TiktokenCounter;
use Yethee\Tiktoken\EncoderProvider;
use Yethee\Tiktoken\Vocab\Vocab;
use Yethee\Tiktoken\Vocab\VocabLoader;

function offlineEncoderProvider(): EncoderProvider
{
    $lines = [];

    foreach (range(0, 255) as $byte) {
        $lines[] = base64_encode(chr($byte)).' '.$byte;
    }

    foreach (['In', 'Inv', 'Invo', 'Invoi', 'Invoic', 'Invoice'] as $rank => $token) {
        $lines[] = base64_encode($token).' '.(256 + $rank);
    }

    $file = tempnam(sys_get_temp_dir(), 'vocab');
    file_put_contents($file, implode("\n", $lines)."\n");
    register_shutdown_function(fn () => @unlink($file));

    $loader = new class($file) implements VocabLoader
    {
        public function __construct(private string $file) {}

        public function load(string $uri, ?string $checksum = null): Vocab
        {
            return Vocab::fromFile($this->file);
        }

        public function loadFile(string $uri, ?string $checksum = null): string
        {
            return $this->file;
        }
    };

    $provider = new EncoderProvider;
    $provider->setVocabLoader($loader);

    return $provider;
}

it('counts the tokens an encoder produces without touching the network', function () {
    $counter = new TiktokenCounter('o200k_base', offlineEncoderProvider());

    expect($counter->count('Invoice'))->toBe(1)
        ->and($counter->count('Invoice overdue'))->toBe(9)
        ->and($counter->count(''))->toBe(0);
});

it('counts bytes for characters the vocabulary can only spell out', function () {
    $counter = new TiktokenCounter('o200k_base', offlineEncoderProvider());

    expect($counter->count('€'))->toBe(3);
});

it('reuses the encoder between calls', function () {
    $counter = new TiktokenCounter('o200k_base', offlineEncoderProvider());

    $counter->count('Invoice');

    expect($counter->count('Invoice'))->toBe(1);
});
