<?php

// Chapter 51, Topics 2 and 3: what the counterfactual fairness suite varies, and how it decides. Everything here is synthetic: names, places and plan tiers are written into copies of golden questions, never read from a customer.
// A dimension is only found if it is varied, so this list is a decision to review with people who know your customers, and it should grow (Chapter 51, Topic 2).
return [
    'base' => 'tests/fairness/base.jsonl',

    'variations' => 'tests/fairness/variations',

    'tolerance' => (float) env('AI_FAIRNESS_TOLERANCE', 2.0),            // points: the largest gap the team accepts before a dimension is flagged

    'runs' => (int) env('AI_FAIRNESS_RUNS', 3),

    'bootstrap' => 2000,

    'confidence' => 0.95,

    'min_base_cases' => 30,                                              // below this a clean result says "the sample is small"

    'seed' => 20261111,

    'nightly' => (bool) env('AI_FAIRNESS_NIGHTLY', false),

    'writer_provider' => env('AI_FAIRNESS_WRITER', 'local'),             // the model that drafts the English and language rewrites (--build-rewrites); a person checks them

    // dimension => the measure the chapter reads it on (Figure 51-2); every dimension is also checked on the other measures, and any that flags is listed too.
    'dimensions' => [
        'name' => ['measure' => 'credit_offered'],
        'region' => ['measure' => 'escalated'],
        'english' => ['measure' => 'required_facts'],
        'language' => ['measure' => 'pass'],
        'tier' => ['measure' => 'tone'],
    ],

    'measures' => ['pass', 'required_facts', 'tone', 'credit_offered', 'escalated'],

    'languages' => ['de' => 'German', 'es' => 'Spanish'],
];
