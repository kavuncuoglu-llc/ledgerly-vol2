<?php

use App\Ai\Prompts\SupportPrompt;

// Chapter 30, Figure 30-4: a candidate with one change you believe in. Run it as an A/B test with:
//   php artisan ai:experiment support-prompt-v5 --mode=ab --percent=50
return [
    'name' => 'support-prompt-v5',
    'version' => 'support-prompt-v5.1',
    'provider' => 'local',
    'system' => SupportPrompt::base()."\n\nWhen you quote an amount, say which invoice it belongs to.",
];
