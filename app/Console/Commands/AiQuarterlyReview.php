<?php

namespace App\Console\Commands;

use App\Ai\Review\QuarterlyReview;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class AiQuarterlyReview extends Command
{
    protected $signature = 'ai:quarterly-review
        {--quarter= : The quarter, such as 2026-Q4 (default: the current one)}
        {--today= : Count days and ages from this date (YYYY-MM-DD) instead of today}
        {--offline : Audit packages from the lock files only: no Packagist, no advisory check}
        {--without-candidates : Skip the watch list (no model is called)}';

    protected $description = 'Collect the quarter\'s review into one dated document, docs/reviews/<quarter>.md, organized by the review\'s five stages (Chapter 50, Topic 9)';

    public function handle(QuarterlyReview $review): int
    {
        try {
            $today = $this->today();

            $quarter = (string) ($this->option('quarter') ?: QuarterlyReview::quarterOf($today));

            $data = $review->collect($quarter, $today, (bool) $this->option('offline'), ! $this->option('without-candidates'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $path = $review->write($data);

        $this->line('wrote '.ltrim(str_replace(base_path(), '', $path), '/'));

        foreach ($review->summary($data) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    private function today(): CarbonImmutable
    {
        $option = $this->option('today');

        if ($option === null) {
            return CarbonImmutable::now()->startOfDay();
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', (string) $option);
        } catch (Throwable) {
            throw new InvalidArgumentException("--today takes YYYY-MM-DD, not [{$option}].");
        }
    }
}
