<?php

// Chapter 48, Topics 6 and 7: the quarterly audit list. Every AI package the app has adopted, deferred or rejected, with the decision record that says why and the date it is read again. ai:package-audit reads it (`--all`), and Chapter 50's quarterly review runs the audit on each entry and lists the ones whose review date has passed.
// `decision` is one of adopted, baseline (the thing the others are measured against), deferred (a recorded "not now") or rejected. A deferred package without a review date is not a decision, and a test refuses it.
return [
    // Gate 1 of Figure 48-6: licences the app may ship, as Composer reports them (SPDX identifiers).
    'licenses_allowed' => ['MIT', 'Apache-2.0', 'BSD-2-Clause', 'BSD-3-Clause', 'ISC', '0BSD'],

    // A package is "AI-related" when its name contains one of these. The audit lists the AI-related packages in a package's dependency tree, because Chapter 47, Topic 5's risk travels down it.
    'ai_patterns' => ['prism', 'laravel/ai', 'neuron', 'laragent', 'llphant', 'openai', 'anthropic', 'gemini', 'ollama', 'mistral', 'langchain', 'toon', 'laravel/mcp', 'php-mcp', 'inspector'],

    // The adopted packages that are used as a ChatModel driver, so the audit can run the contract suite on them (Chapter 47). The value is the suite's driver name.
    'drivers' => [
        'prism-php/prism' => 'prism',
        'laravel/ai' => 'ai-sdk',
    ],

    // Chapter 34's data map, as far as packages go: what each package stores that the app has declared (and user:forget clears). A package that stores state and has no entry here is an open item in its audit.
    'data_map' => [
        'prism-php/prism' => [],
        'laravel/ai' => [],
        'helgesverre/toon' => [],
        'laravel/mcp' => [],
    ],

    // Packages that implement a versioned specification: the audit lists "which spec version" as an open item for them (Chapter 48, Topic 4).
    'spec_backed' => ['helgesverre/toon' => 'TOON', 'laravel/mcp' => 'MCP'],

    // Settings whose names suggest a package sends data somewhere you did not choose.
    'outbound_keys' => ['telemetry', 'analytics', 'monitoring', 'ingestion', 'tracking', 'inspector', 'sentry', 'beacon', 'phone_home'],

    'health' => [
        'recent_days' => 183,         // "releases in the last six months"
        'quiet_days' => 120,          // no release for this long while the package is not abandoned is worth a look (Figure 47-5)
    ],

    'packagist' => [
        'metadata' => env('PACKAGIST_METADATA_URL', 'https://repo.packagist.org/p2'),
        'advisories' => env('PACKAGIST_ADVISORIES_URL', 'https://packagist.org/api/security-advisories/'),
        'timeout' => 20,
        'max_archive_bytes' => 30_000_000,
    ],

    'packages' => [
        ['name' => 'prism-php/prism', 'decision' => 'adopted', 'where' => 'PrismDriver, for hosted providers', 'adr' => 'docs/adr/ai-packages-behind-model-client.md', 'review' => '2027-02-11'],
        ['name' => 'laravel/ai', 'decision' => 'adopted', 'where' => 'AiSdkDriver, measured and kept off the support path', 'adr' => 'docs/adr/ai-packages-behind-model-client.md', 'review' => '2027-02-11'],
        ['name' => 'helgesverre/toon', 'decision' => 'adopted', 'where' => 'ToonEncoder, spec 4.4', 'adr' => 'docs/adr/toon-spec-pinned.md', 'review' => '2027-02-11'],
        ['name' => 'laravel/mcp', 'decision' => 'adopted', 'where' => 'the MCP server (Volume 1, Chapter 11)', 'adr' => null, 'review' => '2027-02-11'],
        ['name' => 'neuron-core/neuron-ai', 'decision' => 'deferred', 'where' => 'not adopted now; re-evaluated when Volume 3 builds workflow-heavy projects', 'adr' => 'docs/adr/ai-packages-behind-model-client.md', 'review' => '2027-02-11'],
        ['name' => 'maestroerror/laragent', 'decision' => 'deferred', 'where' => 'not now: it would duplicate the app\'s memory', 'adr' => 'docs/adr/laragent-on-the-support-path.md', 'review' => '2027-02-11'],
    ],
];
