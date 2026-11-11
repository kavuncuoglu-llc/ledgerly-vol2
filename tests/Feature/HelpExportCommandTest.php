<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/help-export-'.bin2hex(random_bytes(4));

    $this->target = $this->directory.'/help_chunks.jsonl';
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

function exportedRows(string $path): array
{
    return array_map(fn (string $line) => json_decode($line, true), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
}

it('writes one JSON line per embedded shared chunk, with the text the reranker scores', function () {
    chunkRow(['heading' => 'Late fees', 'content' => "Late fees\nA late fee is applied after thirty days."]);
    chunkRow(['heading' => 'Recurring', 'content' => 'Pick monthly and the invoice repeats.']);

    $this->artisan('help:export', ['--jsonl' => true, '--to' => $this->target])
        ->expectsOutputToContain('Wrote 2 chunks')
        ->assertSuccessful();

    $rows = exportedRows($this->target);

    expect($rows)->toHaveCount(2)
        ->and(array_keys($rows[0]))->toBe(['id', 'source', 'heading', 'heading_path', 'kind', 'tokens', 'text'])
        ->and($rows[0]['text'])->toBe("Late fees\nA late fee is applied after thirty days.")
        ->and($rows[1]['text'])->toBe("Recurring\nPick monthly and the invoice repeats.");
});

it('exports a child as the matched text with its context header, not the parent section', function () {
    $parent = chunkRow(['embedding' => null, 'kind' => 'parent', 'content' => 'The whole section, long.']);
    chunkRow(['kind' => 'child', 'parent_id' => $parent->id, 'context_header' => 'Billing › Late fees', 'content' => 'One small child.']);
    chunkRow(['kind' => 'child', 'parent_id' => $parent->id, 'context_header' => null, 'content' => 'A child with no header.']);

    $this->artisan('help:export', ['--to' => $this->target])->assertSuccessful();

    $rows = exportedRows($this->target);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['text'])->toBe("Billing › Late fees\n\nOne small child.")
        ->and($rows[1]['text'])->toBe('A child with no header.')
        ->and($rows[0]['kind'])->toBe('child');
});

it('leaves a tenant\'s private passages out', function () {
    chunkRow();
    chunkRow(['tenant_id' => 7, 'content' => 'Private to one tenant.']);

    $this->artisan('help:export', ['--to' => $this->target])->assertSuccessful();

    expect(exportedRows($this->target))->toHaveCount(1);
});

it('keeps unicode and slashes readable, and replaces the file it finds', function () {
    mkdir($this->directory);

    file_put_contents($this->target, "stale\nstale\n");

    chunkRow(['heading' => 'Billing › Fees', 'content' => 'See help/billing/late-fees for the 1.5% fee.']);

    $this->artisan('help:export', ['--to' => $this->target])->assertSuccessful();

    $line = file($this->target)[0];

    expect(file($this->target))->toHaveCount(1)
        ->and($line)->toContain('Billing › Fees')
        ->and($line)->toContain('help/billing/late-fees');
});

it('defaults to training/data/help_chunks.jsonl', function () {
    $default = base_path('training/data/help_chunks.jsonl');

    $existed = file_exists($default);

    chunkRow();

    $this->artisan('help:export', ['--jsonl' => true])->assertSuccessful();

    expect(file_exists($default))->toBeTrue();

    if (! $existed) {
        unlink($default);
    }
});

it('asks for help:ingest first when there is nothing to export', function () {
    $this->artisan('help:export', ['--to' => $this->target])
        ->expectsOutputToContain('Run php artisan help:ingest first')
        ->assertFailed();
});
