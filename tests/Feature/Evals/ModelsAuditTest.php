<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

// Chapter 30, Figure 30-7: every model pinned, every deprecation dated, and the replacement's place on the ladder.
beforeEach(function () {
    Carbon::setTestNow('2026-11-03 09:00:00');
});

it('lists the roles with what each is pinned to', function () {
    config(['ai.providers.frontier.model' => 'frontier-2026-06-01', 'ai.providers.frontier.base_url' => 'http://frontier.test/v1', 'ai.providers.frontier.api_key' => 'k']);

    [$code, $out] = artisanOutput('ai:models:audit');

    expect($code)->toBe(0)
        ->and($out)->toContain('frontier-2026-06-01')->toContain('fixed ID')->toContain('judge')->toContain('ladder stage');
});

it('checks the local digest against what Ollama reports for the tag', function () {
    config(['ai.providers.local.digest' => 'sha256:9f2c0000aaaa', 'ai.providers.local.model' => 'ledgerly-support']);

    Http::fake(['*/api/tags' => Http::response(['models' => [['name' => 'ledgerly-support', 'digest' => 'sha256:9f2c0000aaaa']]])]);

    expect(artisanOutput('ai:models:audit')[1])->toContain('sha256:9f2c0000… ✓');

    Http::swap(new Factory);                                                         // a new fake does not replace the earlier stubs

    Http::fake(['*/api/tags' => Http::response(['models' => [['name' => 'ledgerly-support', 'digest' => 'sha256:41ab1111bbbb']]])]);

    expect(artisanOutput('ai:models:audit')[1])->toContain('DIGEST CHANGED: config 9f2c0000…, server 41ab1111…');

    Http::swap(new Factory);                                                         // a new fake does not replace the earlier stubs

    Http::fake(['*/api/tags' => Http::response('', 500)]);

    expect(artisanOutput('ai:models:audit')[1])->toContain('(server not reached)');
});

it('counts the days to a retirement and shows where the replacement is on the ladder', function () {
    config(['ai.providers.frontier.model' => 'frontier-2026-06-01', 'ai.providers.frontier.deprecates_on' => '2027-02-01', 'ai.providers.frontier.replacement_candidate' => 'worse-prompt']);

    [$code, $out] = artisanOutput('ai:models:audit');

    expect($code)->toBe(0)->and($out)->toContain('2027-02-01')->toContain('90')->toContain('named; offline evaluation not started');

    $file = sys_get_temp_dir().'/cand-'.uniqid().'.php';

    file_put_contents($file, "<?php\nreturn ['name' => 'worse-prompt', 'version' => 'v1', 'system' => 'x'];\n");

    artisanOutput('ai:experiment', ['name' => 'x', '--mode' => 'shadow', '--percent' => 10, '--candidate' => $file]);

    expect(artisanOutput('ai:models:audit')[1])->toContain('shadowing');
});

it('fails when a model retires soon and nobody has named a replacement', function () {
    config(['ai.providers.frontier.model' => 'frontier-2026-06-01', 'ai.providers.frontier.deprecates_on' => '2026-11-20']);

    [$code, $out] = artisanOutput('ai:models:audit');

    expect($code)->toBe(1)->and($out)->toContain('retires in 17 days and has no replacement candidate');
});

it('says when a model is an alias or is not pinned', function () {
    config(['ai.providers.frontier.model' => 'provider-chat-latest']);

    expect(artisanOutput('ai:models:audit')[1])->toContain('ALIAS: provider-chat-latest');

    config(['ai.providers.frontier.pinned' => false]);

    expect(artisanOutput('ai:models:audit')[1])->toContain('NOT PINNED');
});
