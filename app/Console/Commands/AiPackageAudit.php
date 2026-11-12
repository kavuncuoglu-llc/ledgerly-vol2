<?php

namespace App\Console\Commands;

use App\Ai\Packages\InstalledPackages;
use App\Ai\Packages\PackageAudit;
use App\Ai\Packages\PackageAuditor;
use Illuminate\Console\Command;
use Throwable;

class AiPackageAudit extends Command
{
    protected $signature = 'ai:package-audit
        {package? : A Composer package name, installed or not, such as maestroerror/laragent}
        {--all : Every package on the audit list (config/ai-packages.php): adopted, baseline and deferred}
        {--lock : Every AI-related package in the lock file, transitive ones included}
        {--offline : Read only what is installed: no Packagist, no advisory check, no download}
        {--strict : Exit 1 when a package fails a gate (licence, advisories, abandonment, contract suite)}
        {--json : Print the audits as JSON}';

    protected $description = 'Audit a package against the checkable half of the adoption rubric: licence, advisories, maintenance, dependencies, stored state, outbound defaults and the contract suite (Chapter 48)';

    public function handle(PackageAuditor $auditor, InstalledPackages $installed): int
    {
        $names = $this->names($installed);

        if ($names === []) {
            $this->error('Give a package name, or --all, or --lock.');

            return self::FAILURE;
        }

        foreach ($names as $name) {
            if (preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#', $name) !== 1) {
                $this->error("[{$name}] is not a Composer package name (vendor/package, lowercase).");

                return self::FAILURE;
            }
        }

        $audits = [];

        foreach ($names as $name) {
            try {
                $audits[] = $auditor->audit($name, (bool) $this->option('offline'));
            } catch (Throwable $e) {
                $this->error("{$name}: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(fn (PackageAudit $audit) => $audit->toArray(), $audits), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($audits as $audit) {
                $this->print($audit);
            }
        }

        $failed = array_filter($audits, fn (PackageAudit $audit) => $audit->failed());

        return $this->option('strict') && $failed !== []
            ? self::FAILURE
            : self::SUCCESS;
    }

    /** @return list<string> */
    private function names(InstalledPackages $installed): array
    {
        $names = [];

        if ($this->argument('package') !== null) {
            $names[] = (string) $this->argument('package');
        }

        if ($this->option('all')) {
            array_push($names, ...array_column((array) config('ai-packages.packages'), 'name'));
        }

        if ($this->option('lock')) {
            array_push($names, ...$installed->matching((array) config('ai-packages.ai_patterns')));
        }

        return array_values(array_unique($names));
    }

    private function print(PackageAudit $audit): void
    {
        $this->line("<options=bold>\$ php artisan ai:package-audit {$audit->package}</>  ({$audit->version}, ".($audit->installed ? 'installed' : 'not installed').')');

        foreach ($audit->lines() as $label => [$status, $text]) {
            $mark = match ($status) {
                'ok' => ' ✓',
                'warn' => ' !',
                'fail' => ' ✗',
                default => '',
            };

            $this->line(str_pad($label, 16).' '.$text.$mark);
        }

        $this->line(str_pad('open items', 16).' '.implode(' · ', $audit->openItems()));

        $since = $audit->signals()['since'] ?? null;

        if ($since !== null) {
            $this->line(str_pad('since', 16).' '.($since['changes'] === [] ? "nothing moved since {$since['date']}" : "since {$since['date']}: ".implode(', ', $since['changes'])));
        }

        $this->newLine();
    }
}
