<?php

// Chapter 51, Topics 7 and 9: what SensitiveContentRouter looks for. Patterns are lower-case regular expressions and are meant to be edited with the support team and the people trained for these conversations;
// tests/Fixtures/sensitive/fixtures.jsonl is the test set, and adding a pattern without a fixture is how a router drifts. Order matters: safety first, then harassment, then scope.
// `lookalikes` are ordinary support phrases that contain a sensitive word. They are removed before the rest is read, so "dispute an invoice and kill myself" still reaches a person.
return [
    'lookalikes' => [
        '/\bterminate (my|the|our) (plan|subscription|account|contract|workspace)\b/',
        '/\bdispute (an?|this|the|that|my) (invoice|charge|fee|payment)\b/',
        '/\bkill (a|the|this|that|my) (recurring|invoice|draft|subscription|schedule|reminder|template)\b/',
        '/\b(attack(ed)?|blow(n)? up|slam(med)?) (the|this|my) (invoice|total|bill|fee)\b/',
        '/\bblow away (my|the|old) (drafts?|invoices?|data)\b/',
        '/\bfurious about (this|the|my) (charge|fee|invoice|bill)\b/',
    ],

    'safety' => [
        'distress' => [
            '/\b(kill(ing)? myself|end(ing)? my (own )?life|suicid\w*|hurt(ing)? myself|harm(ing)? myself)\b/',
            '/\b(don\'?t|do not) want to (be alive|live|wake up|be here anymore)\b/',
            '/\b(better off dead|want to disappear|no (way|point) (out|in living)|can\'?t go on|can\'?t keep doing this)\b/',
            '/\bhopeless\b/',
        ],
        'threat_to_others' => [
            '/\b(hurt|harm|kill|attack|stab|shoot|beat up|make .{1,20} pay for this)\b.{0,30}\b(him|her|them|you|partner|client|staff|someone|people|boss|employee)\b/',
            '/\b(find|know) where (he|she|they|you|your \w+|\w+) (live|lives|park|parks|work|works)\b/',
            '/\bmake (him|her|them|you) (sorry|regret)\b/',
            '/\b(bring|bringing|have|carry) (a )?(weapon|gun|knife|bomb)\b/',
        ],
        'danger' => [
            '/\bwith a (knife|gun|weapon)\b/',
            '/\b(threatening to (hurt|harm|kill)|i\'?m scared for my (life|safety)|in danger)\b/',
            '/\bneed help now\b/',
        ],
    ],

    'harassment' => [
        '/\b(you|ledgerly) (stupid|useless|worthless|pathetic) (idiots?|bot|people|company|morons?)\b/',
        '/\bf+u+c+k+ (you|off)\b/',
        '/\b(piece of (shit|garbage)|shut (the f\w+ )?up you)\b/',
    ],

    'out_of_scope' => [
        'legal' => [
            '/\b(sue|suing|lawsuit|lawyer|attorney|legal advice|bankruptcy|cease and desist|liable|liability)\b/',
            '/\b(legally|enforceable|my will)\b/',
        ],
        'tax' => [
            '/\b(tax returns?|deduct(ible|ion|ions)?|vat registration|register for vat|income tax(es)?|corporate tax(es)?|estimated tax(es)?|tax advice)\b/',
            '/\bhow much (sales )?tax (should|do) i (charge|owe|pay)\b/',
        ],
        'medical' => [
            '/\b(medicine|medication|rash|symptoms?|diagnos\w+|prescription|headache)\b/',
        ],
        'other' => [
            '/\b(write me an? (poem|story|song|essay)|who will win|election|weather forecast|recipe for)\b/',
        ],
    ],

    'classifier' => [
        'enabled' => (bool) env('AI_SENSITIVE_CLASSIFIER', false),
        'provider' => env('AI_SENSITIVE_CLASSIFIER_PROVIDER', 'local'),
    ],

    'log' => [
        'false_refusals' => true,
    ],
];
