<?php

use App\Ai\Contracts\ChatModel;
use App\Ai\Tools\ToolGate;
use App\Console\Commands\DevPlantLogInjection;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Boost\Console\StartCommand;

// Chapter 49: the files an agent reads at the start of a session, and the permission settings that stop it, are tested as data.
function projectFile(string $path): string
{
    return file_get_contents(base_path($path));
}

function agentSettings(): array
{
    return json_decode(projectFile('.claude/settings.json'), true, flags: JSON_THROW_ON_ERROR);
}

describe('Laravel Boost', function () {
    it('is a development dependency, pinned exactly, and never a runtime one', function () {
        $composer = json_decode(projectFile('composer.json'), true);

        expect($composer['require-dev']['laravel/boost'])->toMatch('/^\d+\.\d+\.\d+$/')
            ->and($composer['require'])->not->toHaveKey('laravel/boost')
            ->and($composer['config']['platform']['php'])->toBe('8.3.0');
    });

    it('is locked as a development package, so composer install --no-dev leaves it out', function () {
        $lock = json_decode(projectFile('composer.lock'), true);

        $runtime = collect($lock['packages'])->pluck('name');

        $development = collect($lock['packages-dev'])->pluck('name');

        expect($development)->toContain('laravel/boost')
            ->and($runtime)->not->toContain('laravel/boost')
            ->and($runtime)->not->toContain('laravel/roster');
    });

    it('registers its MCP server by the command an agent starts', function () {
        $mcp = json_decode(projectFile('.mcp.json'), true);

        expect($mcp['mcpServers']['laravel-boost'])->toBe(['command' => 'php', 'args' => ['artisan', 'boost:mcp']])
            ->and(class_exists(StartCommand::class))->toBeTrue();
    });

    it('lists the agent it installed for, so boost:update works on a fresh clone', function () {
        $boost = json_decode(projectFile('boost.json'), true);

        expect($boost['agents'])->toBe(['claude_code'])
            ->and($boost['guidelines'])->toBeTrue()
            ->and($boost['mcp'])->toBeTrue();
    });
});

describe('the project guideline', function () {
    it('is the same file in docs/agents and in .ai/guidelines', function () {
        expect(projectFile('.ai/guidelines/ledgerly.blade.php'))->toBe(projectFile('docs/agents/ledgerly.blade.php'));
    });

    it('is in the instructions file Boost generated', function () {
        expect(projectFile('AGENTS.md'))->toContain('## Ledgerly: rules that apply everywhere')
            ->and(projectFile('AGENTS.md'))->toContain('`ToolGate::dispatch`');
    });

    it('names only classes, directories, files and commands that exist', function () {
        $rules = projectFile('.ai/guidelines/ledgerly.blade.php');

        expect(interface_exists(ChatModel::class))->toBeTrue()
            ->and(method_exists(ToolGate::class, 'dispatch'))->toBeTrue()
            ->and(is_dir(base_path('app/Ai/Prompts')))->toBeTrue()
            ->and(file_exists(base_path('config/ai-pricing.php')))->toBeTrue();

        preg_match_all('/`php artisan ([a-z:\-]+)`/', $rules, $commands);

        expect($commands[1])->toContain('eval:check', 'ai:contract-test');

        foreach ($commands[1] as $command) {
            expect(array_keys(Artisan::all()))->toContain($command);
        }

        preg_match_all('/`(vendor\/bin\/[a-z]+)/', $rules, $binaries);

        foreach ($binaries[1] as $binary) {
            expect(file_exists(base_path($binary)))->toBeTrue("{$binary} is not installed");
        }
    });

    it('carries no comment header and no secret', function () {
        $rules = projectFile('.ai/guidelines/ledgerly.blade.php');

        expect($rules)->not->toContain('{{--')
            ->and($rules)->not->toMatch('/(sk-|api[_-]?key|password)/i');
    });
});

describe('the committed permission settings', function () {
    it('allows the routine commands without asking', function () {
        expect(agentSettings()['permissions']['allow'])->toContain(
            'Bash(php artisan test:*)',
            'Bash(php artisan ai:contract-test:*)',
            'Bash(php artisan eval:check:*)',
            'Bash(git diff:*)',
            'Bash(git status)',
        );
    });

    it('asks before anything that changes state outside the working tree', function () {
        expect(agentSettings()['permissions']['ask'])->toContain(
            'Bash(composer require:*)',
            'Bash(php artisan migrate:*)',
            'Bash(php artisan tinker:*)',
            'Bash(git push:*)',
            'mcp__laravel-boost__database-query',
        );
    });

    it('denies what an agent should never do', function () {
        expect(agentSettings()['permissions']['deny'])->toContain(
            'Read(.env.production)',
            'Bash(php artisan db:wipe:*)',
            'Bash(git push --force:*)',
            'Bash(*deploy*)',
        );
    });

    it('never lists the same rule as allowed and as asked or denied', function () {
        $permissions = agentSettings()['permissions'];

        expect(array_intersect($permissions['allow'], $permissions['ask']))->toBe([])
            ->and(array_intersect($permissions['allow'], $permissions['deny']))->toBe([])
            ->and(array_intersect($permissions['ask'], $permissions['deny']))->toBe([]);
    });

    it('never allows Tinker, a migration, an install or a push without asking', function () {
        $allowed = implode(' ', agentSettings()['permissions']['allow']);

        foreach (['tinker', 'migrate', 'composer require', 'composer update', 'git push', 'db:wipe', 'deploy'] as $word) {
            expect($allowed)->not->toContain($word);
        }
    });

    it('allows only artisan commands that exist', function () {
        foreach (agentSettings()['permissions']['allow'] as $rule) {
            if (preg_match('/^Bash\(php artisan ([a-z:\-]+):\*\)$/', $rule, $found) !== 1) {
                continue;
            }

            $known = array_keys(Artisan::all());

            $exists = in_array($found[1], $known, true) || collect($known)->contains(fn (string $name) => str_starts_with($name, $found[1].':'));

            expect($exists)->toBeTrue("{$found[1]} is not an artisan command");
        }
    });
});

describe('dev:plant-log-injection', function () {
    beforeEach(function () {
        $this->log = tempnam(sys_get_temp_dir(), 'ledgerly-log-');

        config(['logging.default' => 'single', 'logging.channels.single.path' => $this->log]);
    });

    afterEach(function () {
        @unlink($this->log);
    });

    it('plants one line that tells an agent to run Tinker, and changes nothing else', function () {
        $this->seed();

        $role = User::find(12)->role;

        $this->artisan('dev:plant-log-injection')->assertSuccessful();

        $lines = array_filter(explode("\n", file_get_contents($this->log)), fn (string $line) => str_contains($line, DevPlantLogInjection::MARKER));

        expect($lines)->toHaveCount(1)
            ->and(reset($lines))->toContain('php artisan tinker')
            ->and(reset($lines))->toContain('ERROR')
            ->and(User::find(12)->role)->toBe($role);
    });

    it('removes what it planted and leaves the rest of the log', function () {
        file_put_contents($this->log, "[2026-04-20 10:00:00] local.INFO: an ordinary line\n");

        $this->artisan('dev:plant-log-injection')->assertSuccessful();

        $this->artisan('dev:plant-log-injection', ['--clear' => true])->assertSuccessful();

        expect(file_get_contents($this->log))->toContain('an ordinary line')
            ->and(file_get_contents($this->log))->not->toContain(DevPlantLogInjection::MARKER);
    });

    it('refuses to run in production', function () {
        $this->app['env'] = 'production';

        $this->artisan('dev:plant-log-injection')->assertFailed();

        expect(file_get_contents($this->log))->toBe('');
    });
});

describe('the agent pull request workflow', function () {
    it('needs a human owner for a pull request labeled agent-authored', function () {
        $workflow = projectFile('.github/workflows/agent-pr.yml');

        expect($workflow)->toContain('agent-authored')
            ->and($workflow)->toContain('[bot]')
            ->and($workflow)->toContain('pull_request:')
            ->and($workflow)->not->toContain('pull_request_target')
            ->and($workflow)->not->toContain('actions/checkout')
            ->and($workflow)->not->toContain('secrets.');
    });

    it('passes untrusted values through env, never into a command line', function () {
        preg_match_all('/run: \|(.*?)(?=\n\s{0,6}-|\z)/s', projectFile('.github/workflows/agent-pr.yml'), $runs);

        expect(implode("\n", $runs[1]))->not->toContain('${{');
    });

    it('is described in the pull request template', function () {
        expect(projectFile('.github/pull_request_template.md'))->toContain('agent-authored');
    });
});
