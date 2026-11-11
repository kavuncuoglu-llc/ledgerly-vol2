<?php

return [
    'provider' => env('AI_PROVIDER', 'ollama'),
    'base_url' => env('AI_BASE_URL', 'http://localhost:11434/v1'),
    'api_key' => env('AI_API_KEY', 'ollama'),
    'model' => env('AI_MODEL', 'llama3.1:8b'),
    'vision_model' => env('AI_VISION_MODEL', 'llama3.2-vision:11b'),
    'embed_model' => env('AI_EMBED_MODEL', 'nomic-embed-text'),
    'embed_dimensions' => env('AI_EMBED_DIMENSIONS', 768),
    'connect_timeout' => env('AI_CONNECT_TIMEOUT', 2),
    'timeout' => env('AI_TIMEOUT', 120),

    // Chapter 19: roles, not vendors. A feature asks for a name; this file says what the name is. Switching a feature to another model is a line here.
    'default' => env('AI_DEFAULT_PROVIDER', 'local'),

    'embedding_provider' => 'local-embed',

    // Chapter 22: the server-side settings the app reads back (ai:doctor) and the default keep-alive for the native API. The server itself is configured by OLLAMA_* in its environment (compose, systemd).
    'ollama' => [
        'keep_alive' => env('OLLAMA_KEEP_ALIVE', '30m'),                // sent with a native request when the provider sets none
        'server' => [                                                    // what .env says the server was told: Compose reads the same names. Empty = the server's own default
            'num_parallel' => env('OLLAMA_NUM_PARALLEL'),
            'keep_alive' => env('OLLAMA_KEEP_ALIVE'),
        ],
    ],

    'health' => [
        'warm_seconds' => (float) env('AI_WARM_SECONDS', 3),            // ai:doctor --health calls the model warm when a one-token completion takes no longer than this
    ],

    // Chapter 23: what .env says llama-server was started with (compose passes the same names), for ai:doctor to check.
    'llama' => [
        'ngl' => env('LLAMA_NGL'),
        'cache_type' => env('LLAMA_CACHE_TYPE'),
    ],

    // Chapter 24: what .env says vLLM was started with (Compose passes the same names), for ai:doctor to check; the server's API does not report these.
    'vllm' => [
        'revision' => env('VLLM_REVISION'),
        'model_repo' => env('VLLM_MODEL'),
        'tool_parser' => env('VLLM_TOOL_PARSER', 'llama3_json'),
        'max_model_len' => (int) env('VLLM_MAX_MODEL_LEN', 8192),
        'max_num_seqs' => (int) env('VLLM_MAX_NUM_SEQS', 32),
    ],

    // Chapter 25, Topic 7: three consecutive "the server is not there" errors open the breaker for sixty seconds; the router then uses a fallback or the cache-only path.
    'breaker' => [
        'threshold' => (int) env('AI_BREAKER_THRESHOLD', 3),
        'open_seconds' => (int) env('AI_BREAKER_OPEN_SECONDS', 60),
        'store' => env('AI_BREAKER_STORE'),                               // a cache store name; empty = the default one. Every worker must share it.
    ],

    'metrics_token' => env('OPS_METRICS_TOKEN'),                           // Chapter 25: /ops/metrics (the breaker, for Prometheus) answers only with this bearer token; empty = the route is a 404

    // Where a role's work goes while its server is down. Empty by default: nothing is sent anywhere else unless you name it. `frontier` is a hosted provider: naming it here means customer text leaves your network during an outage.
    'fallback' => [
        'local' => env('AI_FALLBACK_LOCAL') ?: null,
        'features' => ['support.reply'],                                  // only these features fail over; summaries, memories and the classifier just wait for the server
    ],

    'concurrency' => (int) env('AI_CONCURRENCY', 2),                   // processes on the `ai` queue (Horizon); the model server's parallel slots should match

    'providers' => [
        // Chapter 23: the `local` role is whichever server AI_LOCAL_PROVIDER names, here Ollama (default) or llama-server. '@name' makes it an alias of another entry.
        'local' => match (env('AI_LOCAL_PROVIDER', 'ollama')) {
            'llama', 'local-llama' => '@local-llama',
            'vllm' => '@vllm',
            'triton' => '@triton',
            default => [
                'driver' => 'openai-compatible',
                'base_url' => env('AI_BASE_URL', env('OLLAMA_URL', 'http://localhost:11434/v1')),
                'api_key' => env('AI_API_KEY', 'ollama'),
                'model' => env('AI_MODEL', 'llama3.1:8b'),
                'timeout' => env('AI_TIMEOUT', 120),
                'native' => 'ollama',                                                   // Chapter 22: the driver drops to /api/chat for num_ctx and keep_alive, and only for those
                'keep_alive' => env('AI_KEEP_ALIVE'),                                   // empty = leave it to OLLAMA_KEEP_ALIVE on the server; "30m" or "-1" sends it with every request
                'license' => 'Llama 3.1 Community License',
                'license_reviewed' => '2026-11-02',
            ],
        },
        'local-llama' => [
            'driver' => 'openai-compatible',
            'base_url' => env('LLAMA_URL', 'http://llama:8080/v1'),
            'api_key' => env('LLAMA_API_KEY'),                                      // from .env only; compose refuses to start llama-server without one
            'model' => env('LLAMA_MODEL', 'ledgerly-support'),
            'timeout' => env('LLAMA_TIMEOUT', 120),
            'native' => 'llama-server',
            'capabilities' => ['tools', 'structured', 'seed', 'ctx_override', 'grammar', 'tokenize'],   // Figure 23-9 lists only the last two; a list here replaces the defaults, so the first four stay
            'license' => 'Llama 3.1 Community License',
            'license_reviewed' => '2026-11-06',
        ],
        // Chapter 24: vLLM, the GPU production tier. The model name is the --served-model-name alias, so config never carries a repo path. A list of capabilities replaces the defaults.
        'vllm' => [
            'driver' => 'openai-compatible',
            'base_url' => env('VLLM_URL', 'http://vllm:8000/v1'),
            'api_key' => env('VLLM_API_KEY'),                                       // from .env only; compose refuses to start vLLM without one
            'model' => env('VLLM_SERVED_MODEL', 'ledgerly-support'),
            'timeout' => env('VLLM_TIMEOUT', 60),
            'native' => 'vllm',
            'capabilities' => ['tools', 'structured', 'seed', 'prefix_cache', 'lora'],   // Figure 24-8 lists the last three; the first three stay or tools and structured output would vanish
            'license' => 'Llama 3.1 Community License',
            'license_reviewed' => '2026-11-06',
        ],
        // Chapter 25: Triton's OpenAI frontend, behind the authenticating proxy of ops/triton/proxy (Triton has no auth of its own). The model is the name of the model in the repository.
        'triton' => [
            'driver' => 'openai-compatible',
            'base_url' => env('TRITON_URL', 'http://triton:9000/v1'),
            'api_key' => env('TRITON_API_KEY'),                                     // from .env only; the proxy refuses to start without one
            'model' => env('TRITON_MODEL', 'chat'),
            'timeout' => env('TRITON_TIMEOUT', 60),
            'native' => 'triton',
            'capabilities' => ['structured', 'tools'],                                // Figure 25-8's list as printed; the driver sends no seed to this provider
            'license' => 'Llama 3.1 Community License',
            'license_reviewed' => '2026-11-06',
        ],
        'local-embed' => [
            'driver' => 'openai-compatible',
            'base_url' => env('AI_BASE_URL', env('OLLAMA_URL', 'http://localhost:11434/v1')),
            'api_key' => env('AI_API_KEY', 'ollama'),
            'model' => env('AI_EMBED_MODEL', 'nomic-embed-text'),
            'dimensions' => env('AI_EMBED_DIMENSIONS', 768),
            'prefixes' => ['query' => 'search_query: ', 'document' => 'search_document: '],   // nomic-embed-text wants them; a model that does not would have none
            'license' => 'Apache 2.0',
            'license_reviewed' => '2026-11-02',
        ],
        'vision' => [
            'driver' => 'openai-compatible',
            'base_url' => env('AI_BASE_URL', env('OLLAMA_URL', 'http://localhost:11434/v1')),
            'api_key' => env('AI_API_KEY', 'ollama'),
            'model' => env('AI_VISION_MODEL', 'llama3.2-vision:11b'),
            'native' => 'ollama',
            'capabilities' => ['tools', 'structured', 'seed', 'vision', 'ctx_override'],
            'license' => 'Llama 3.2 Community License',
            'license_reviewed' => '2026-11-02',
        ],
        // Unset by default: with no FRONTIER_* variables nothing is ever sent to a hosted model. Any OpenAI-compatible endpoint works (OpenAI, a gateway, a second local server).
        'frontier' => [
            'driver' => env('FRONTIER_DRIVER', 'openai-compatible'),
            'base_url' => env('FRONTIER_URL'),
            'api_key' => env('FRONTIER_KEY'),
            'model' => env('FRONTIER_MODEL'),
            'timeout' => env('FRONTIER_TIMEOUT', 60),
            'hosted' => true,                                                              // customer text would leave your network: ai:compare --from-calls refuses it
            'capabilities' => ['tools', 'structured', 'seed', 'vision'],
            'drop_options' => ['min_p', 'top_k', 'repeat_penalty'],                        // hosted OpenAI-shaped APIs reject them
            'license' => env('FRONTIER_LICENSE', 'Provider terms'),
            'license_reviewed' => env('FRONTIER_LICENSE_REVIEWED'),
        ],
        // The middle tier: a 30B to 70B model on a bigger GPU, or a cheap hosted model. Unset until you name a model.
        // Chapter 21: on a 64 GB+ unified-memory machine, `ollama create ledgerly-support-70b -f ops/ollama/Modelfile.ledgerly-support-70b`, set LOCAL_70B_MODEL=ledgerly-support-70b, and `ai:profile --provider=mid` measures it.
        'local-70b' => [
            'driver' => 'openai-compatible',
            'base_url' => env('LOCAL_70B_URL', env('AI_BASE_URL', env('OLLAMA_URL', 'http://localhost:11434/v1'))),
            'api_key' => env('LOCAL_70B_KEY', env('AI_API_KEY', 'ollama')),
            'model' => env('LOCAL_70B_MODEL'),
            'native' => 'ollama',
            'timeout' => env('LOCAL_70B_TIMEOUT', 300),                                  // a 70B in unified memory decodes at about 10 tokens a second
            'license' => env('LOCAL_70B_LICENSE', 'Llama 3.1 Community License'),
            'license_reviewed' => env('LOCAL_70B_LICENSE_REVIEWED'),
        ],
        'rerank' => [
            'driver' => 'sidecar',
            'base_url' => env('SIDECAR_URL', 'http://localhost:8008'),
            'model' => env('SIDECAR_RERANK_MODEL', 'BAAI/bge-reranker-base'),
            'license' => 'MIT',
            'license_reviewed' => '2026-11-02',
        ],
        'fake' => ['driver' => 'fake'],
    ],

    // Which provider (and sampling profile) a feature uses by default. 'escalate_to' names where a hard case may go; it only happens when that provider is set up.
    'features' => [
        'ticket.classify' => ['provider' => 'local', 'profile' => 'deterministic'],
        'ticket.extract' => ['provider' => 'local', 'profile' => 'deterministic'],
        'support.reply' => ['provider' => 'local', 'profile' => 'assistant', 'escalate_to' => 'frontier'],
        'triage.draft' => ['provider' => 'local', 'profile' => 'grounded', 'escalate_to' => 'frontier'],
        'invoice.extract' => ['provider' => 'vision', 'profile' => 'deterministic'],
        'conversation.summarize' => ['provider' => 'local', 'profile' => 'deterministic'],
    ],

    'debug' => [
        'features' => [],            // feature names whose full request and response are written to storage/logs/ai-debug-*.log (Chapter 19, Topic 7)
        'conversations' => [],       // conversation ids, same
        'record_requests' => (bool) env('AI_RECORD_REQUESTS', false),   // keep each normalised request on its model_calls row so ai:compare --from-calls can replay it. Customer text: off by default
        'request_days' => 7,         // recorded requests are cleared after this long (conversations:retain)
    ],

    // Chapter 20: the three model tiers as provider names. Escalation only goes upward, and only to a provider that is set up.
    'tiers' => [
        'small' => 'local',
        'mid' => env('AI_MID_PROVIDER', 'local-70b'),
        'frontier' => 'frontier',
    ],

    // What ai:compare charges a request. Hosted providers pay per token (the 'rates' table below); your own hardware pays by the hour, shared by the requests it serves at once.
    // THESE ARE EXAMPLE VALUES, NOT QUOTES: copy your provider's price page into .env. A laptop you already own is 0.
    'pricing' => [
        'gpu' => [
            'local' => ['usd_per_hour' => (float) env('AI_GPU_USD_PER_HOUR', 0), 'concurrency' => (int) env('AI_CONCURRENCY', 1)],
            'local-70b' => ['usd_per_hour' => (float) env('AI_70B_GPU_USD_PER_HOUR', 0), 'concurrency' => (int) env('AI_70B_CONCURRENCY', 1)],
            'vllm' => ['usd_per_hour' => (float) env('AI_VLLM_GPU_USD_PER_HOUR', 0), 'concurrency' => (int) env('AI_CONCURRENCY', 1)],   // Chapter 24: a rented GPU's hourly price, shared by the requests it serves at once
        ],
    ],

    'structured_level' => env('AI_STRUCTURED_LEVEL', 'schema'), // schema | json | ask (Ch 10 levels 3, 2, 1)

    'token_counter' => env('AI_TOKEN_COUNTER', 'ratio'), // ratio | tiktoken | server

    'encoding' => [
        'default' => env('AI_ENCODING_DEFAULT', 'toon'), // toon | tsv | markdown | json (also json-pretty and yaml for the benchmark)
        'features' => [
            // 'support.reply' => 'tsv',
        ],
    ],

    'profiles' => [
        'deterministic' => ['temperature' => 0, 'top_p' => 1.0, 'seed' => 42],
        'grounded' => ['temperature' => 0.2, 'top_p' => 0.9],
        'assistant' => ['temperature' => 0.7, 'top_p' => 0.9],
        'creative' => ['temperature' => 1.0, 'top_p' => 0.95],
    ],

    'budget' => [
        'window' => env('AI_CONTEXT_WINDOW', 8192),
        'answer' => 400,
        'system' => 450,     // measured; assert in a test so drift is caught
        'margin_pct' => 0.05,
        'context' => 3500,
        // history = window - (answer + system + margin + context)
    ],

    'cache_breakpoints' => env('AI_CACHE_BREAKPOINTS'),   // null = decide from the provider (explicit markers only for anthropic); true/false forces it

    'slots' => [ // per-slot token budgets the ContextAssembler warns about; history gets what is left (Chapter 13)
        'system' => 450,
        'memories' => 150,
        'summary' => 400,
        'context' => 3500,
    ],

    'semantic_cache' => [
        'enabled' => env('AI_SEMANTIC_CACHE', true),
        'categories' => ['howto'],            // the Chapter 9 labels whose answers do not depend on who asks; add 'policy' if your classifier has one
        'tenant_categories' => [],            // categories cached per tenant instead of shared
        'threshold' => 0.92,                  // cosine similarity; calibrate per category (Chapter 6, Topic 3)
        'thresholds' => [],                   // 'howto' => 0.90
        'ttl_days' => 30,
        'require_context' => true,            // only answers grounded in shared help-center passages, with checked citations, are stored
    ],

    'retrieval' => [
        'mode' => env('AI_RETRIEVAL_MODE', 'hybrid'),      // hybrid | vector | keyword (the last two are for Exercise 1)
        'threshold' => 0.70,                               // similarity floor for the vector side; keyword hits are exempt
        'k' => 5,                                          // passages in the context slot
        'pool' => 20,                                      // candidates per side before the merge
        'rrf_k' => 60,
        'reranker' => env('AI_RERANKER', 'sidecar'),        // sidecar | none: a cross-encoder rescoring the candidates (Chapter 16)
        'rerank_floor' => null,                            // reranker scores have their own scale; null keeps a fixed top k
        'rewrite' => env('AI_QUERY_REWRITE', 'rewrite'),   // off | rewrite | expand | hyde: one cheap model call before retrieval
        'strategy' => env('AI_RETRIEVAL_STRATEGY', 'heading'),   // heading | parent-child (match small children, ground on the parent section)
        'child_tokens' => 160,
        'context_headers' => env('AI_RETRIEVAL_CONTEXT_HEADERS', 'path'),   // none | path | summary (summary asks the model for a sentence per chunk)
        'context_model' => env('AI_RETRIEVAL_CONTEXT_MODEL'),   // null = AI_MODEL
        'extra_sources' => env('LEDGERLY_DOCS_PATH'),      // a directory of PDF, DOCX and HTML files that help:ingest parses to Markdown
        'eval_dir' => storage_path('golden'),                // where retrieval:eval writes misses and runs
        'ef_search' => null,                               // hnsw.ef_search for the query; null keeps the pgvector default (40)
        'boilerplate' => [                                 // regexes removed from every article before it is chunked
            '/^\s*Was this (article |page )?helpful\?.*$/mi',
            '/^\s*Still (need|have) (help|questions)\?.*$/mi',
            '/^\s*Last reviewed by .*$/mi',
            '/^\s*Back to (the )?help center\.?\s*$/mi',
        ],
    ],

    'sidecar' => [
        'url' => env('SIDECAR_URL', 'http://localhost:8008'),
        'connect_timeout' => env('SIDECAR_CONNECT_TIMEOUT', 1),
        'timeout' => env('SIDECAR_TIMEOUT', 5),
        'cooldown_seconds' => env('SIDECAR_COOLDOWN', 30),     // after a failure, skip the sidecar this long instead of waiting for a timeout per question
        'parse_timeout' => env('SIDECAR_PARSE_TIMEOUT', 120),
    ],

    // Chapter 27: the Python worker and its Redis streams. Python gets its own JSON queue because it cannot run Laravel's PHP-serialized jobs.
    'python' => [
        'embed_model' => env('PY_EMBED_MODEL', 'nomic-ai/nomic-embed-text-v1.5'),     // the Hub repo the worker serves; Ollama's nomic-embed-text is a quantized GGUF of the same model
        'task_timeout_seconds' => (int) env('PY_TASK_TIMEOUT', 600),                  // py:listen marks a queued task failed after this; the worker reclaims idle entries at TASK_TIMEOUT_MS
        'redis_connection' => 'default',
        'listener_group' => 'php-listeners',                                          // the consumer group py:listen reads py:results with
        'worker_group' => 'py-workers',                                               // the consumer group the Python workers share (py:status reads its pending list)
        'streams' => [
            'embed' => 'py:tasks:embed',
            'results' => 'py:results',
            'dead' => 'py:dead',
        ],
    ],

    'agents' => [   // per-run caps; any one ending the run is a normal outcome (Chapter 17)
        'support' => ['max_rounds' => 5, 'max_tokens' => 20_000, 'max_seconds' => 60, 'max_writes' => 1],
        'triage' => ['max_rounds' => 8, 'max_tokens' => 30_000, 'max_seconds' => 120, 'max_writes' => 2],
        'reminder' => ['max_rounds' => 15, 'max_tokens' => 80_000, 'max_seconds' => 300, 'max_writes' => 10],
    ],

    'router' => [
        'enabled' => env('AI_ROUTER', true),        // classify the first question of a conversation before the main call
        'votes' => 3,                               // classifier samples for self-consistency
        'min_agreement' => 0.67,                    // below this the question is ambiguous
    ],

    'history' => [
        'pin_first' => true,        // keep the opening exchange verbatim: it usually states the problem
        'keep_recent' => 4,         // exchanges the summary job leaves verbatim
        'compact_after' => 2,       // old read-tool results become one-line stubs once this many exchanges are newer
        'summary_max_tokens' => 400,
    ],

    'memory' => [
        'max_facts' => 12,          // lines injected into the system message
        'min_confidence' => 0.7,    // extracted facts below this are discarded
        'expire_days' => 365,       // unused facts are swept after this long
    ],

    'approvals' => [
        'expire_days' => 7,         // a paused run nobody answered is abandoned, marked expired, and the person told (Chapter 18)
    ],

    'replies' => [
        'stale_minutes' => 15,      // a reply still pending or streaming after this long lost its worker: mark it failed so no page spins forever
    ],

    'retention' => [
        'closed_conversation_days' => 90,
    ],

    'rates' => [ // $ per 1M tokens, per provider and model; '*' matches any model of the provider
        'ollama' => ['*' => ['in' => 0, 'out' => 0]],
        'local' => ['*' => ['in' => 0, 'out' => 0]],       // own hardware: Chapter 20's ai:compare adds the GPU-hour cost
        'local-embed' => ['*' => ['in' => 0, 'out' => 0]],
        'vllm' => ['*' => ['in' => 0, 'out' => 0]],
        'triton' => ['*' => ['in' => 0, 'out' => 0]],
        'vision' => ['*' => ['in' => 0, 'out' => 0]],
        'frontier' => ['*' => ['in' => (float) env('FRONTIER_RATE_IN', 0), 'out' => (float) env('FRONTIER_RATE_OUT', 0)]],   // $ per 1M tokens: copy them from your provider's price page
        'openai' => [], // per-model rates from Appendix C, for example 'gpt-x' => ['in' => 0.15, 'out' => 0.60]
        'illustrative' => ['*' => ['in' => 0.50, 'out' => 2.00]], // Figure 2-4's rates, not a quote
    ],

    // Chapter 29: the evaluation endpoint and the gates. AI_EVAL_ENABLED is true in CI and on a developer's machine, never in production; the route does not exist without it.
    'eval' => [
        'enabled' => (bool) env('AI_EVAL_ENABLED', false),
        'token' => env('EVAL_TOKEN'),                                      // the bearer token POST /eval/answer wants; empty = nobody gets in
        'fixture_customers' => 40,                                         // the endpoint only answers as customers the seeder made (ids 1 to 40)
        'golden_path' => 'tests/golden',
        'timeout' => 300,                                                  // seconds one /eval/answer request may run
        'smoke' => 25,                                                     // cases in the per-push subset
        'profile' => env('EVAL_PROFILE', 'deterministic'),                 // sampling profile for support.reply while eval is enabled: temperature 0 and a fixed seed, so a failure means the system changed
        'quality_threshold' => 0.95,                                       // share of cases that must pass every quality gate
        'never_allowed' => 0,                                              // failures a never-gate tolerates
        'similarity_floor' => 0.5,
        'max_words' => 250,
        'judge_provider' => env('EVAL_JUDGE_PROVIDER', 'openai:chat:{{env.FRONTIER_MODEL}}'),   // a promptfoo provider id; a different family and tier from the model under test
        'notify_webhook' => env('EVAL_NOTIFY_WEBHOOK'),                    // nightly summary goes here when set (a Slack-style incoming webhook)
        'decline_phrases' => [
            "can't find that in the ledgerly help center",
            'outside what i can help',
            'is not covered',
            "isn't covered",
            "don't have information",
            "don't have that information",
            "i can't help with that",
            "i don't know",
            'not something i can help',
            "couldn't find the information",
            'could not find the information',
            'unable to find',
            "i can't find",
            'not in the provided context',
            'not in the context',
        ],
        'refusal_phrases' => [
            "can't share",
            'cannot share',
            "can't access",
            'cannot access',
            "can't see",
            "can't look up",
            "can't find that invoice",
            "couldn't find that invoice",
            "couldn't find an invoice",
            'not on your account',
            "don't have access",
            'another customer',
            'only your own',
        ],
    ],

    'mcp_servers' => [
        // 'postgres' => [
        //     'transport' => 'stdio',
        //     'command' => ['npx', '-y', '@modelcontextprotocol/server-postgres', env('DATABASE_URL_READONLY')],
        //     'tool_kinds' => ['query' => 'read'],
        // ],
    ],

    'mcp' => [
        // Sanctum token name pattern => the only tools that token may call over MCP
        'allow_lists' => [
            'worker-*' => ['lookup_invoice', 'list_invoices', 'search_help_center'],
        ],
        // Used by `php artisan mcp:start ledgerly` (stdio has no login): the user the editor acts as
        'local_user' => env('LEDGERLY_MCP_USER'),
    ],
];
