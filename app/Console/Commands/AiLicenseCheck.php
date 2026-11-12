<?php

namespace App\Console\Commands;

use App\Ai\Licenses\LicenseCheck;
use Illuminate\Console\Command;

class AiLicenseCheck extends Command
{
    protected $signature = 'ai:license-check {--json : Print the rows as JSON}';

    protected $description = 'Fail when a model is served without a reviewed license record, or a derived model does not carry its base\'s obligations (Chapter 51, Topic 6; a CI step)';

    public function handle(LicenseCheck $check): int
    {
        $rows = $check->rows();

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if (! $this->option('json')) {
            foreach ($rows as $row) {
                $this->line(sprintf('%s %s · %s', $row['ok'] ? '✓' : '✗', $row['served'], $row['why']));
            }
        }

        $failures = $check->failures();

        if ($failures !== []) {
            $this->error(count($failures).' model(s) served without what their license asks for.');
        }

        return $failures === []
            ? self::SUCCESS
            : self::FAILURE;
    }
}
