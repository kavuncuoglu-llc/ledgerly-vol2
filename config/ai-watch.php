<?php

// Chapter 50, Topic 9: candidate models the team wants to try. ai:quarterly-review runs each through the golden set (a sample) and reports the pass rate beside the role it would replace.
// `from` is an entry of config/ai.php `providers` to copy (its server, key and privacy terms); `model` is the candidate's name on that server. `tripwire` is optional:
// the directories of two lm_eval outputs (the base and the candidate) for eval:tripwire. A candidate is never routed to by being listed here.
return [
    'directory' => 'docs/reviews',                                // where ai:quarterly-review writes <quarter>.md, relative to the project

    'owner' => env('AI_REVIEW_OWNER', 'platform team'),            // the feature owner the review is assigned to

    'sample' => (int) env('AI_WATCH_SAMPLE', 12),

    'candidates' => [
        [
            'name' => 'llama3.2-1b',
            'from' => 'local',
            'model' => 'llama3.2:1b',
            'replaces' => 'local',
            'why' => 'A much smaller local model for the how-to route: would it clear the floor on a fifth of the memory?',
            'added' => '2026-11-11',
        ],
    ],
];
