<?php

return [

    'as_of' => env('LEDGERLY_AS_OF', '2026-04-20'),

    'help_path' => env('LEDGERLY_HELP_PATH') ?: resource_path('help'),

    'help_version' => env('LEDGERLY_HELP_VERSION'), // empty = a hash of the help articles; Chapter 15 uses the docs commit

    'late_fee' => [
        'grace_days' => 30,
        'daily_rate' => 0.0005,
        'minimum_cents' => 100,
    ],

    'per_page' => 25,

];
