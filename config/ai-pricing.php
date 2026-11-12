<?php

// Chapter 35, Figure 35-9: $ per `per` tokens, for the input, output, cache-read and cache-write sides of a call. THESE ARE ILLUSTRATIVE ROUND NUMBERS, not a quote: copy your provider's current rates
// into .env (Appendix C.7 says where each provider publishes them), and change `version` whenever you do, so a ledger row keeps the version that priced it (Chapter 36).
// A model with no entry here, and no provider rule below, fails ai:cost-audit loudly instead of counting as free.
$models = [];

// The hosted role is priced only once you have copied its rates into .env: a model with rates of 0 is a model nobody priced, and the audit says so.
if (filled(env('FRONTIER_MODEL')) && (float) env('FRONTIER_RATE_IN', 0) > 0) {
    $models[env('FRONTIER_MODEL')] = [
        'input' => (float) env('FRONTIER_RATE_IN'),
        'output' => (float) env('FRONTIER_RATE_OUT'),
        'cache_read' => (float) env('FRONTIER_RATE_CACHE_READ', env('FRONTIER_RATE_IN')),
        'cache_write' => (float) env('FRONTIER_RATE_CACHE_WRITE', env('FRONTIER_RATE_IN')),
        'per' => 1_000_000,
        'source' => env('FRONTIER_PRICING_URL', ''),
        'checked' => env('FRONTIER_PRICING_CHECKED'),          // Chapter 50: the day you read the price off the provider's page (YYYY-MM-DD); ai:quarterly-review flags one older than 90 days
    ];
}

// Chapter 41, Topic 5: a fine-tuned model has its own prices; providers commonly charge more per token for it than for its base. Priced only once ai:ft:register has written the model and you have copied the rates from the provider's page (Appendix F.8) into
// BILLING_FT_RATE_IN and BILLING_FT_RATE_OUT: a fine-tune with no price is unpriced, and ai:cost-audit says so on the first day.
if (filled(env('BILLING_FT_MODEL')) && (float) env('BILLING_FT_RATE_IN', 0) > 0) {
    $models[env('BILLING_FT_MODEL')] = [
        'input' => (float) env('BILLING_FT_RATE_IN'),
        'output' => (float) env('BILLING_FT_RATE_OUT'),
        'cache_read' => (float) env('BILLING_FT_RATE_IN'),
        'cache_write' => (float) env('BILLING_FT_RATE_IN'),
        'per' => 1_000_000,
        'source' => env('FRONTIER_PRICING_URL', ''),
        'checked' => env('BILLING_FT_PRICING_CHECKED', env('FRONTIER_PRICING_CHECKED')),
    ];
}

// Training is priced per million trained tokens on the base model. A hosted job's ledger row (kind `training`) reads it; with no rate the row is written at 0 and says so.
$training = [];

if (filled(env('BILLING_FT_BASE')) && (float) env('BILLING_FT_TRAIN_RATE', 0) > 0) {
    $training[env('BILLING_FT_BASE')] = ['per_million' => (float) env('BILLING_FT_TRAIN_RATE'), 'source' => env('FRONTIER_PRICING_URL', '')];
}

// The replays of Chapter 35 (ai:cost-replay) price their frontier calls with this illustrative entry when no FRONTIER_MODEL is set. Round numbers, not a quote.
$models['illustrative-frontier'] = [
    'input' => 3.00,
    'output' => 15.00,
    'cache_read' => 0.30,
    'cache_write' => 3.75,
    'per' => 1_000_000,
    'source' => 'illustrative: the round numbers of Chapters 35 to 37',
    'illustrative' => true,
];

return [
    'version' => '2026-11-03',
    'checked_on' => '2026-11-03',

    'models' => $models,

    'training' => $training,

    // Your own hardware: no token price. The hardware itself is tracked separately (ai.pricing.gpu, and the ledger's `hardware` rows in Chapter 36).
    'providers' => [
        'ollama' => ['input' => 0.0, 'output' => 0.0, 'per' => 1_000_000, 'source' => 'local'],
        'local' => ['input' => 0.0, 'output' => 0.0, 'per' => 1_000_000, 'source' => 'local'],
        'local-70b' => ['input' => 0.0, 'output' => 0.0, 'per' => 1_000_000, 'source' => 'local'],
        'local-billing-ft' => ['input' => 0.0, 'output' => 0.0, 'per' => 1_000_000, 'source' => 'local'],
        'local-billing-ft@canary' => ['input' => 0.0, 'output' => 0.0, 'per' => 1_000_000, 'source' => 'local'],
        'local-billing-ft@shadow' => ['input' => 0.0, 'output' => 0.0, 'per' => 1_000_000, 'source' => 'local'],
        'mid-in-region' => ['input' => 0.0, 'output' => 0.0, 'per' => 1_000_000, 'source' => 'local'],
        'local-support-ft' => ['input' => 0.0, 'output' => 0.0, 'per' => 1_000_000, 'source' => 'local'],
        'local-support-ft@canary' => ['input' => 0.0, 'output' => 0.0, 'per' => 1_000_000, 'source' => 'local'],
        'local-support-ft@shadow' => ['input' => 0.0, 'output' => 0.0, 'per' => 1_000_000, 'source' => 'local'],
        'local-embed' => ['input' => 0.0, 'output' => 0.0, 'per' => 1_000_000, 'source' => 'local'],
        'vllm' => ['input' => 0.0, 'output' => 0.0, 'per' => 1_000_000, 'source' => 'local'],
        'triton' => ['input' => 0.0, 'output' => 0.0, 'per' => 1_000_000, 'source' => 'local'],
        'vision' => ['input' => 0.0, 'output' => 0.0, 'per' => 1_000_000, 'source' => 'local'],
        'router' => ['input' => 0.0, 'output' => 0.0, 'per' => 1_000_000, 'source' => 'no model call'],
    ],
];
