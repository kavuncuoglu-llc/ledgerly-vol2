<?php

namespace App\Console\Commands;

use App\Ai\Review\Reliance;
use Illuminate\Console\Command;

class AiReliance extends Command
{
    protected $signature = 'ai:reliance {--weeks=12 : How many recent weeks}';

    protected $description = 'Weekly: is review keeping pace with reliance? Unedited-send share, median review time, and the audit\'s error rate in unedited sends (Chapter 51, Topic 8)';

    public function handle(Reliance $reliance): int
    {
        $rows = $reliance->weekly((int) $this->option('weeks'));

        if ($rows->isEmpty()) {
            $this->line('No draft reviews on record. (php artisan db:seed --class=DraftReviewSeeder adds a month of fictional ones.)');

            return self::SUCCESS;
        }

        $this->table(
            ['week', 'reviews', 'sent unedited', 'median review', 'audited unedited: errors'],
            $rows->map(fn ($row) => [
                $row->week,
                $row->reviews,
                round($row->unedited_share * 100).'%',
                round($row->median_review_s).' s',
                $row->audited_unedited_error_rate === null ? 'no audit' : round($row->audited_unedited_error_rate * 100).'%',
            ])->all(),
        );

        $this->warnings($rows);

        return self::SUCCESS;
    }

    private function warnings($rows): void
    {
        $recent = $rows->first();

        $earlier = $rows->last();

        if ($rows->count() < 2) {
            return;
        }

        if ($recent->unedited_share > $earlier->unedited_share && ($recent->audited_unedited_error_rate ?? 0) > 0) {
            $this->warn('Unedited sends climbed while the audit still finds errors in them: coach and change the review screen; do not blame.');
        }
    }
}
