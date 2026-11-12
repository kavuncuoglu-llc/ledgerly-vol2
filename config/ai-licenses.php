<?php

// Chapter 51, Topic 6: what was reviewed for each license a served model carries, and where each obligation is met. `ai:license-check` (a CI step) fails when a model is served without a record, when a record
// has no reviewer, no date or no place for an obligation, or when a fine-tune or student does not carry its base's obligations. Providers in config/ai.php name their license; this table says what it asks.
// THE TEXT VERSIONS, DATES AND REVIEWER BELOW ARE THE FICTIONAL COMPANY'S: Ledgerly's "legal" is a role in a made-up business. Read the license text yourself, record your own reviewer and date, and change `use`
// to `distributed-weights` the day you hand a model to anyone else to run. None of this is legal advice.
$llama = [
    'group' => 'community',
    'use' => 'hosted-service',
    'reviewed' => '2026-10-28',
    'by' => 'legal',
    'obligations' => [
        'acceptable_use' => 'docs/policies/acceptable-use-mapping.md',
        'attribution' => 'resources/help/account/about-the-assistant.md',
        'derived_naming' => 'docs/policies/model-naming.md',
    ],
    'attribution_text' => 'Built with Llama',
];

return [
    'max_review_age_days' => 400,

    'licenses' => [
        'Llama 3.1 Community License' => $llama + ['text_version' => 'Llama 3.1 Community License Agreement, release of 2024-07-23'],
        'Llama 3.2 Community License' => $llama + ['text_version' => 'Llama 3.2 Community License Agreement, release of 2024-09-25'],
        'Apache 2.0' => [
            'group' => 'open-source',
            'text_version' => 'Apache License, Version 2.0, January 2004',
            'use' => 'hosted-service',
            'reviewed' => '2026-10-28',
            'by' => 'legal',
            'obligations' => ['notices' => 'docs/policies/model-naming.md'],
        ],
        'MIT' => [
            'group' => 'open-source',
            'text_version' => 'The MIT License (the text each repository ships)',
            'use' => 'hosted-service',
            'reviewed' => '2026-10-28',
            'by' => 'legal',
            'obligations' => ['notices' => 'docs/policies/model-naming.md'],
        ],
    ],

    // A provider whose model is served by someone else under the provider's own terms has no open-weight license to review here; the DPA and privacy terms are Chapter 34's data map.
    'hosted_terms' => ['Provider terms'],

    // The base model of each registered fine-tune (the registry's `base`) and the license it carries into every version trained from it.
    'bases' => [
        'meta-llama/Llama-3.1-8B-Instruct' => 'Llama 3.1 Community License',
        'HuggingFaceTB/SmolLM2-135M-Instruct' => 'Apache 2.0',
        'HuggingFaceTB/SmolLM2-360M-Instruct' => 'Apache 2.0',
        'Qwen/Qwen2.5-0.5B-Instruct' => 'Apache 2.0',
    ],
];
