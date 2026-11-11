<?php

// Written by `php artisan eval:route-table`: the cheapest role that clears the scorecard's floor for each category, from golden-set pass rates.
// Review it like any other diff. `rates` is the evidence: the pass rate of each role that was measured.

return [
    '_meta' => [
        'written_on' => '2026-11-11',
        'golden_cases' => null,
        'floor' => 0.95,
        'margin' => 0.01,
        'roles' => [
            'local',
            'mid',
            'frontier',
        ],
        'source' => 'a pass-rate file',
    ],
    'account' => [
        'default' => [
            'start' => 'mid',
            'chain' => [
                'mid',
                'frontier',
            ],
            'buffers' => true,
            'cascade' => false,
            'rates' => [
                'local' => 0.94,
                'mid' => 0.97,
                'frontier' => 0.98,
            ],
            'why' => 'local is a point or two under the floor; mid is the cheapest role that clears it',
        ],
    ],
    'billing' => [
        'default' => [
            'start' => 'local-billing-ft',
            'chain' => [
                'local-billing-ft',
                'mid',
                'frontier',
            ],
            'buffers' => true,
            'cascade' => true,
            'rates' => [
                'local' => 0.91,
                'local-billing-ft' => 0.97,
                'mid' => 0.96,
                'frontier' => 0.98,
            ],
            'why' => 'the billing fine-tune clears the floor where the base did not (91%): start there and escalate on failed checks. Chapter 44\'s figure; rerun eval:route-table with local-billing-ft measured to replace it',
        ],
        'residency' => [
            'start' => 'local-billing-ft',
            'chain' => [
                'local-billing-ft',
                'mid-in-region',
            ],
            'then' => 'person',
            'why' => 'a residency tenant\'s question never leaves its region: the fine-tune, a larger model in the same region, then a person (hand-written; eval:route-table keeps it)',
        ],
    ],
    'howto' => [
        'default' => [
            'start' => 'local',
            'chain' => [
                'local',
                'mid',
                'frontier',
            ],
            'buffers' => true,
            'cascade' => false,
            'rates' => [
                'local' => 0.97,
                'mid' => 0.98,
                'frontier' => 0.99,
            ],
            'why' => 'local clears the floor with the margin',
        ],
    ],
    'policy' => [
        'default' => [
            'start' => 'mid',
            'chain' => [
                'mid',
                'frontier',
            ],
            'buffers' => true,
            'cascade' => false,
            'rates' => [
                'local' => 0.93,
                'mid' => 0.96,
                'frontier' => 0.97,
            ],
            'why' => 'local is a point or two under the floor; mid is the cheapest role that clears it',
        ],
    ],
    'safety' => [
        'default' => [
            'start' => 'frontier',
            'chain' => [
                'frontier',
            ],
            'buffers' => false,
            'cascade' => false,
            'rates' => [
                'local' => 0.78,
                'mid' => 0.88,
                'frontier' => 0.96,
            ],
            'why' => 'local is far under the floor; frontier is the cheapest role that clears it',
        ],
    ],
];
