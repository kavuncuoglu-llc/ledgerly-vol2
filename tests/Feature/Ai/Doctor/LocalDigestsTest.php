<?php

use App\Ai\Doctor\LocalDigestRow;
use App\Ai\Doctor\LocalDigests;
use App\Models\ModelRegistryEntry;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

// Chapter 50, Topic 5: a local tag can move; the digest on record cannot.
function ollamaTags(array $models): void
{
    Http::swap(new Factory);

    Http::fake(['*/api/tags' => Http::response(['models' => array_map(fn (string $name, string $digest) => ['name' => $name, 'digest' => $digest], array_keys($models), array_values($models))])]);
}

beforeEach(function () {
    config([
        'ai.providers.local.base_url' => 'http://ollama.test:11434/v1',
        'ai.providers.local.model' => 'llama3.1:8b',
        'ai.providers.local.native' => 'ollama',
        'ai.providers.local.digest' => 'sha256:3f1c9a0000000000',
        'ai.registry.servers.local' => ['kind' => 'ollama', 'url' => 'http://ollama.test:11434', 'host' => null],
    ]);
});

describe('a base model', function () {
    it('matches the digest in config', function () {
        ollamaTags(['llama3.1:8b' => '3f1c9a0000000000']);

        $row = app(LocalDigests::class)->rows()[0];

        expect($row->status)->toBe(LocalDigestRow::OK)
            ->and($row->text)->toBe('matches config')
            ->and($row->line())->toContain('digest sha256:3f1c…')
            ->and($row->line())->toContain('✓ matches config');
    });

    it('notices when the tag now points at other weights', function () {
        ollamaTags(['llama3.1:8b' => '9a02ffff00000000']);

        $row = app(LocalDigests::class)->rows()[0];

        expect($row->status)->toBe(LocalDigestRow::CHANGED)
            ->and($row->isProblem())->toBeTrue()
            ->and($row->text)->toContain('DIGEST CHANGED')
            ->and($row->text)->toContain('3f1c')
            ->and($row->text)->toContain('9a02');
    });

    it('says when no digest is on record, and that is not a failure', function () {
        config(['ai.providers.local.digest' => null]);

        ollamaTags(['llama3.1:8b' => '3f1c9a0000000000']);

        $row = app(LocalDigests::class)->rows()[0];

        expect($row->status)->toBe(LocalDigestRow::UNRECORDED)
            ->and($row->isProblem())->toBeFalse()
            ->and($row->line())->toContain('! no digest recorded');
    });

    it('says when the server does not have the model, and when it cannot be reached', function () {
        ollamaTags(['other:1b' => 'aaaa']);

        expect(app(LocalDigests::class)->rows()[0]->status)->toBe(LocalDigestRow::MISSING);

        Http::swap(new Factory);

        Http::fake(['*' => Http::response('', 500)]);

        expect(app(LocalDigests::class)->rows()[0]->status)->toBe(LocalDigestRow::UNREACHABLE);
    });
});

describe('a fine-tune in the registry', function () {
    beforeEach(function () {
        config(['ai.registry.roles' => ['billing' => 'local-billing-ft']]);

        ModelRegistryEntry::create([
            'name' => 'billing',
            'version' => 1,
            'model' => 'ledgerly-billing-ft',
            'base' => 'meta-llama/Llama-3.1-8B-Instruct',
            'dataset' => 'billing-v1',
            'prompt_version' => 'ft-v1',
            'stage' => 'production',
            'served_on' => ['local' => ['digest' => 'sha256:9a02ffff00000000']],
        ]);
    });

    it('matches the digest the registry recorded when it was loaded', function () {
        ollamaTags(['llama3.1:8b' => '3f1c9a0000000000', 'ledgerly-billing-ft:latest' => '9a02ffff00000000']);

        $row = collect(app(LocalDigests::class)->rows())->firstWhere('role', 'local-billing-ft');

        expect($row->status)->toBe(LocalDigestRow::OK)
            ->and($row->text)->toBe('matches registry billing@production')
            ->and($row->model)->toBe('ledgerly-billing-ft');
    });

    it('fails when the server now holds other weights under that name', function () {
        ollamaTags(['llama3.1:8b' => '3f1c9a0000000000', 'ledgerly-billing-ft:latest' => '1111111111111111']);

        $row = collect(app(LocalDigests::class)->rows())->firstWhere('role', 'local-billing-ft');

        expect($row->status)->toBe(LocalDigestRow::CHANGED);
    });

    it('is the exit code of ai:doctor --local', function () {
        ollamaTags(['llama3.1:8b' => '3f1c9a0000000000', 'ledgerly-billing-ft:latest' => '9a02ffff00000000']);

        $this->artisan('ai:doctor', ['--local' => true])->expectsOutputToContain('✓ matches registry billing@production')->assertSuccessful();

        ollamaTags(['llama3.1:8b' => 'ffffffffffffffff', 'ledgerly-billing-ft:latest' => '9a02ffff00000000']);

        $this->artisan('ai:doctor', ['--local' => true])->assertFailed();
    });
});
