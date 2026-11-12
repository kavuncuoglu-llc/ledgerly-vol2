<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Database\Seeders\Support\SeededRandom;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// Chapter 51, Exercise 5: a month of fictional draft reviews, anchored to the seed date, in which agents edit less each week while the weekly audit keeps finding errors in what they send unedited. Run on demand:
// php artisan db:seed --class=DraftReviewSeeder. It is not part of DatabaseSeeder, so the standard seed data is unchanged.
class DraftReviewSeeder extends Seeder
{
    private const SEED = 5101;

    public function run(): void
    {
        $rng = new SeededRandom(self::SEED);

        $monday = CarbonImmutable::parse('2026-03-23 09:00:00');

        $rows = [];

        $id = 1;

        foreach (range(0, 3) as $week) {
            $uneditedShare = 0.35 + 0.15 * $week;

            foreach (range(1, 30) as $n) {
                $unedited = $rng->chance($uneditedShare);

                $rows[] = [
                    'draft_type' => 'triage_reply',
                    'draft_id' => $id++,
                    'reviewer_id' => null,
                    'sent_at' => $monday->addWeeks($week)->addHours($rng->int(0, 100))->format('Y-m-d H:i:s'),
                    'edit_distance' => $unedited ? 0 : $rng->int(12, 240),
                    'review_seconds' => max(8, 75 - 15 * $week + $rng->int(-10, 10)),
                    'audited' => $n % 5 === 0,
                    'audit_found_error' => $n % 5 === 0 && $unedited && $rng->chance(0.1 + 0.1 * $week),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        DB::table('draft_reviews')->insert($rows);
    }
}
