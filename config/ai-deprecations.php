<?php

// Chapter 50, Figure 50-4: every dated dependency in one table, checked daily (`php artisan ai:deprecations`, scheduled) with alerts at 90, 30 and 7 days.
// kind: model | finetune | api | package | local. retires: YYYY-MM-DD, or `abandoned` for a package. review: a date for something that never retires on its own (a local model).
// An entry whose `id` is empty is not configured on this machine and is skipped. `follows` names another entry's id: a fine-tune of a hosted model retires with its base.
// Entries marked `example` show the shape; replace them with the dates from your providers' notices, each with the day you read it and the notice's URL.
return [
    'alert_days' => [90, 30, 7],

    'entries' => [
        ['kind' => 'model', 'id' => env('FRONTIER_MODEL'), 'retires' => env('FRONTIER_DEPRECATES_ON'), 'replacement' => env('FRONTIER_CANDIDATE'), 'read' => env('FRONTIER_DEPRECATION_READ'), 'source' => env('FRONTIER_DEPRECATION_URL')],
        ['kind' => 'finetune', 'id' => env('BILLING_FT_MODEL'), 'follows' => env('FRONTIER_MODEL')],
        ['kind' => 'api', 'id' => 'provider-x/v1/completions', 'retires' => '2027-01-15', 'replacement' => '/v1/responses', 'read' => '2026-09-02', 'source' => 'https://provider-x.example.com/deprecations', 'example' => true],
        ['kind' => 'package', 'id' => 'vendor/abandoned-thing', 'retires' => 'abandoned', 'replacement' => 'vendor/successor', 'example' => true],
        ['kind' => 'local', 'id' => 'llama3.1:8b', 'review' => '2027-01-31'],
    ],
];
