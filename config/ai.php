<?php

use App\Ai\Batch\JudgeNightlySource;
use App\Ai\Batch\SynthesizeHelpCenterSource;
use App\Ai\Prompts\FineTunedSupportPrompt;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Registry\Aliases;

// Chapter 44, Topic 5: the self-served billing fine-tune. Its model name is the registry alias billing@production, resolved here when config is loaded (and frozen by config:cache), so a promotion or a rollback is a registry change and a config refresh.
$billingFt = [
    'driver' => 'openai-compatible',
    'base_url' => env('BILLING_FT_URL'),
    'api_key' => env('BILLING_FT_LOCAL_KEY', 'ollama'),
    'model' => Aliases::resolve('billing@production', env('BILLING_FT_LOCAL_MODEL', 'ledgerly-billing-ft')),
    'timeout' => env('BILLING_FT_TIMEOUT', 120),
    'native' => 'ollama',
    'license' => 'Llama 3.1 Community License',                                  // a fine-tune inherits its base's license (Appendix C.3)
    'license_reviewed' => env('BILLING_FT_LICENSE_REVIEWED'),
    'pinned' => true,
    'digest' => env('BILLING_FT_LOCAL_DIGEST'),
    'region' => env('BILLING_FT_REGION'),
    'fine_tuned' => [
        'registry' => 'billing@production',
        'base' => env('BILLING_FT_LOCAL_BASE', 'meta-llama/Llama-3.1-8B-Instruct'),
        'dataset' => env('BILLING_FT_LOCAL_DATASET', 'billing-v1'),
        'dataset_hash' => env('BILLING_FT_LOCAL_DATASET_HASH'),
        'run' => env('BILLING_FT_LOCAL_RUN'),
        'prompt_version' => FineTunedSupportPrompt::VERSION,
    ],
    'privacy' => ['redact' => false, 'trains_on_api_data' => false, 'retention_days' => 0, 'zdr' => true, 'region' => env('BILLING_FT_REGION', 'local'), 'dpa_signed' => true, 'checked_on' => '2026-11-11', 'source' => 'Your own hardware, in the data\'s region: the data never leaves it.'],
];

// Chapter 47: the `local` role on Ollama, kept in a variable so the package roles below can be the same server reached through another driver.
$localOllama = [
    'driver' => 'openai-compatible',
    'base_url' => env('AI_BASE_URL', env('OLLAMA_URL', 'http://localhost:11434/v1')),
    'api_key' => env('AI_API_KEY', 'ollama'),
    'model' => env('AI_MODEL', 'llama3.1:8b'),
    'timeout' => env('AI_TIMEOUT', 120),
    'native' => 'ollama',                                                   // Chapter 22: the driver drops to /api/chat for num_ctx and keep_alive, and only for those
    'keep_alive' => env('AI_KEEP_ALIVE'),                                   // empty = leave it to OLLAMA_KEEP_ALIVE on the server; "30m" or "-1" sends it with every request
    'license' => 'Llama 3.1 Community License',
    'license_reviewed' => '2026-11-02',
    'pinned' => true,                                                       // Chapter 30, Topic 7: ai:models:audit compares this digest with what Ollama reports for the tag
    'digest' => env('AI_LOCAL_DIGEST'),                                     // sha256 of the manifest, from `ollama show`; empty = not recorded yet
    'privacy' => ['redact' => false, 'trains_on_api_data' => false, 'retention_days' => 0, 'zdr' => true, 'region' => 'local', 'dpa_signed' => true, 'checked_on' => '2026-11-03', 'source' => 'Your own hardware: the data never leaves it.'],
];

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
            default => $localOllama,
        },
        // Chapter 47: the same Ollama server, reached through a package. `driver` is the whole difference, so a call, a golden-set run or an experiment can move between them by role name, and every decorator (tracing, budgets, redaction, usage log) wraps all three alike.
        'local-prism' => array_merge($localOllama, ['driver' => 'prism', 'prism_provider' => 'ollama', 'capabilities' => ['tools', 'structured', 'seed'], 'native' => null]),
        'local-ai-sdk' => array_merge($localOllama, ['driver' => 'ai-sdk', 'sdk_driver' => 'ollama', 'capabilities' => ['tools', 'structured', 'seed'], 'native' => null]),
        'local-llama' => [
            'driver' => 'openai-compatible',
            'base_url' => env('LLAMA_URL', 'http://llama:8080/v1'),
            'api_key' => env('LLAMA_API_KEY'),                                      // from .env only; compose refuses to start llama-server without one
            'model' => env('LLAMA_MODEL', 'ledgerly-support'),
            'timeout' => env('LLAMA_TIMEOUT', 120),
            'native' => 'llama-server',
            'privacy' => ['redact' => false, 'trains_on_api_data' => false, 'retention_days' => 0, 'zdr' => true, 'region' => 'local', 'dpa_signed' => true, 'checked_on' => '2026-11-03', 'source' => 'Your own hardware: the data never leaves it.'],
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
            'privacy' => ['redact' => false, 'trains_on_api_data' => false, 'retention_days' => 0, 'zdr' => true, 'region' => 'local', 'dpa_signed' => true, 'checked_on' => '2026-11-03', 'source' => 'Your own hardware: the data never leaves it.'],
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
            'privacy' => ['redact' => false, 'trains_on_api_data' => false, 'retention_days' => 0, 'zdr' => true, 'region' => 'local', 'dpa_signed' => true, 'checked_on' => '2026-11-03', 'source' => 'Your own hardware: the data never leaves it.'],
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
            'privacy' => ['redact' => false, 'trains_on_api_data' => false, 'retention_days' => 0, 'zdr' => true, 'region' => 'local', 'dpa_signed' => true, 'checked_on' => '2026-11-03', 'source' => 'Your own hardware: the data never leaves it.'],
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
            'privacy' => ['redact' => false, 'trains_on_api_data' => false, 'retention_days' => 0, 'zdr' => true, 'region' => 'local', 'dpa_signed' => true, 'checked_on' => '2026-11-03', 'source' => 'Your own hardware: the data never leaves it.'],
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
            'pinned' => true,                                                              // a fixed, versioned model ID, never a "latest" alias (Chapter 30, Topic 7)
            'deprecates_on' => env('FRONTIER_DEPRECATES_ON'),                              // recorded the day the provider's notice arrives
            'replacement_candidate' => env('FRONTIER_CANDIDATE'),
            // Chapter 34, Topic 2: the five questions, answered once, with the date they were checked and where. Redaction is on for every hosted role; the router refuses a feature the answers do not allow.
            'privacy' => [
                'redact' => true,
                'trains_on_api_data' => false,
                'retention_days' => env('FRONTIER_RETENTION_DAYS'),
                'zdr' => (bool) env('FRONTIER_ZDR', false),
                'region' => env('FRONTIER_REGION'),
                'dpa_signed' => (bool) env('FRONTIER_DPA_SIGNED', false),
                'checked_on' => env('FRONTIER_TERMS_CHECKED_ON'),
                'source' => env('FRONTIER_TERMS_URL'),
            ],
        ],
        // Chapter 41, Figure 41-5: the hosted fine-tune of the billing model, a role beside its base. Unset until ai:ft:register has written BILLING_FT_MODEL; routed to nothing until a route names it. Its model ID is pinned, its provenance is recorded
        // here, and the prompt version it was trained on is bound to it: SupportAssistant sends this role the short system message and no other role.
        'frontier-billing-ft' => [
            'driver' => env('FRONTIER_DRIVER', 'openai-compatible'),
            'base_url' => env('FRONTIER_URL'),
            'api_key' => env('FRONTIER_KEY'),
            'model' => env('BILLING_FT_MODEL'),
            'timeout' => env('FRONTIER_TIMEOUT', 60),
            'hosted' => true,
            'capabilities' => ['tools', 'structured', 'seed'],
            'drop_options' => ['min_p', 'top_k', 'repeat_penalty'],
            'license' => env('FRONTIER_LICENSE', 'Provider terms'),
            'license_reviewed' => env('FRONTIER_LICENSE_REVIEWED'),
            'pinned' => true,
            'deprecates_on' => env('BILLING_FT_DEPRECATES_ON', env('FRONTIER_DEPRECATES_ON')),     // a fine-tune retires with its base
            'fine_tuned' => [
                'base' => env('BILLING_FT_BASE'),
                'dataset' => env('BILLING_FT_DATASET', 'billing-v1'),
                'dataset_hash' => env('BILLING_FT_DATASET_HASH'),
                'job' => env('BILLING_FT_JOB'),
                'prompt_version' => FineTunedSupportPrompt::VERSION,                                 // the short system message it was trained on
            ],
            'privacy' => [
                'redact' => true,
                'trains_on_api_data' => false,
                'retention_days' => env('FRONTIER_RETENTION_DAYS'),
                'zdr' => (bool) env('FRONTIER_ZDR', false),
                'region' => env('FRONTIER_REGION'),
                'dpa_signed' => (bool) env('FRONTIER_DPA_SIGNED', false),
                'checked_on' => env('FRONTIER_TERMS_CHECKED_ON'),
                'source' => env('FRONTIER_TERMS_URL'),
            ],
        ],
        // Chapter 42, Topic 8: the billing fine-tune trained on your own hardware (Chapter 42) and served from it: `ledgerly-billing-ft`, the Q4_K_M model `export_to_ollama.py` made. Unset until BILLING_FT_URL names the server (the regional Ollama box), so no route can send
        // a question to a model that is not there. Pinned, with its provenance and the short system message it was trained on (bound to the role by SupportPrompt::boundTo). Chapter 44 resolves `model` from the registry alias billing@production instead of the environment.
        'local-billing-ft' => $billingFt,
        ...Aliases::variants('local-billing-ft', 'billing', $billingFt),
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
            'pinned' => true,
            'digest' => env('LOCAL_70B_DIGEST'),
            'deprecates_on' => env('LOCAL_70B_DEPRECATES_ON'),
            'replacement_candidate' => env('LOCAL_70B_CANDIDATE'),
            'privacy' => [
                'redact' => (bool) env('LOCAL_70B_REDACT', false),                       // your own GPU: off. Pointed at a hosted server: set LOCAL_70B_REDACT=true
                'trains_on_api_data' => false,
                'retention_days' => 0,
                'zdr' => true,
                'region' => env('LOCAL_70B_REGION', 'local'),
                'dpa_signed' => (bool) env('LOCAL_70B_DPA_SIGNED', true),
                'checked_on' => '2026-11-03',
                'source' => 'Your own hardware, unless LOCAL_70B_URL points at a hosted server.',
            ],
        ],
        // Chapter 44, Topic 5 (and Chapter 46, Topic 2, the distillation teacher): the larger open-weight model in the residency region, so a residency tenant's cascade escalates to it and never to a hosted role. Unset until MID_IN_REGION_URL names a server.
        // `distillation_basis` is what dataset:distill checks before it sends a question to a teacher: the license, when it was reviewed and by whom (Chapter 46).
        'mid-in-region' => [
            'driver' => 'openai-compatible',
            'base_url' => env('MID_IN_REGION_URL'),
            'api_key' => env('MID_IN_REGION_KEY', 'ollama'),
            'model' => env('MID_IN_REGION_MODEL'),
            'native' => 'ollama',
            'timeout' => env('MID_IN_REGION_TIMEOUT', 300),
            'license' => env('MID_IN_REGION_LICENSE') ?: 'Llama 3.1 Community License',
            'license_reviewed' => env('MID_IN_REGION_LICENSE_REVIEWED'),
            'region' => env('MID_IN_REGION_REGION', env('BILLING_FT_REGION')),
            'pinned' => true,
            'digest' => env('MID_IN_REGION_DIGEST'),
            'distillation_basis' => ['license' => env('MID_IN_REGION_LICENSE'), 'reviewed' => env('MID_IN_REGION_LICENSE_REVIEWED'), 'by' => env('MID_IN_REGION_LICENSE_REVIEWED_BY')],
            'privacy' => ['redact' => false, 'trains_on_api_data' => false, 'retention_days' => 0, 'zdr' => true, 'region' => env('MID_IN_REGION_REGION', env('BILLING_FT_REGION', 'local')), 'dpa_signed' => true, 'checked_on' => '2026-11-11', 'source' => 'Your own hardware, in the data\'s region: the data never leaves it.'],
        ],
        // Chapter 46, Topic 5: the distilled student, a second adapter on the same vLLM base as the billing fine-tune (Chapter 44). Its model name is the registry alias support@production. Unset until SUPPORT_FT_URL names the vLLM server.
        'local-support-ft' => [
            'driver' => 'openai-compatible',
            'base_url' => env('SUPPORT_FT_URL'),
            'api_key' => env('SUPPORT_FT_KEY') ?: env('VLLM_API_KEY') ?: 'ollama',
            'model' => Aliases::resolve('support@production', env('SUPPORT_FT_MODEL') ?: null),
            'timeout' => env('SUPPORT_FT_TIMEOUT', 120),
            'license' => 'Llama 3.1 Community License',                                  // a student inherits its base's license, and its teacher's terms (Chapter 46, Topic 2)
            'license_reviewed' => env('SUPPORT_FT_LICENSE_REVIEWED'),
            'region' => env('SUPPORT_FT_REGION') ?: env('BILLING_FT_REGION'),
            'pinned' => true,
            'fine_tuned' => [
                'registry' => 'support@production',
                'base' => env('SUPPORT_FT_BASE', 'meta-llama/Llama-3.1-8B-Instruct'),
                'dataset' => env('SUPPORT_FT_DATASET', 'support-distill-v1'),
                'prompt_version' => FineTunedSupportPrompt::VERSION,
            ],
            'privacy' => ['redact' => false, 'trains_on_api_data' => false, 'retention_days' => 0, 'zdr' => true, 'region' => env('SUPPORT_FT_REGION') ?: env('BILLING_FT_REGION') ?: 'local', 'dpa_signed' => true, 'checked_on' => '2026-11-11', 'source' => 'Your own hardware, in the data\'s region: the data never leaves it.'],
        ],
        'rerank' => [
            'driver' => 'sidecar',
            'base_url' => env('SIDECAR_URL', 'http://localhost:8008'),
            'model' => env('SIDECAR_RERANK_MODEL', 'BAAI/bge-reranker-base'),
            'privacy' => ['redact' => false],                                              // our own sidecar, not a chat model
            'license' => 'MIT',
            'license_reviewed' => '2026-11-02',
        ],
        'fake' => ['driver' => 'fake', 'privacy' => ['redact' => false]],
    ],

    // Which provider (and sampling profile) a feature uses by default. 'escalate_to' names where a hard case may go; it only happens when that provider is set up.
    'features' => [
        'ticket.classify' => ['provider' => 'local', 'profile' => 'deterministic'],
        'ticket.extract' => ['provider' => 'local', 'profile' => 'deterministic'],
        'support.reply' => ['provider' => 'local', 'profile' => 'assistant', 'escalate_to' => 'frontier', 'allowed_providers' => ['local', 'local-prism', 'local-ai-sdk', 'local-70b', 'frontier', 'frontier-billing-ft', 'local-billing-ft', 'local-billing-ft@canary', 'local-billing-ft@shadow', 'mid-in-region', 'local-support-ft', 'local-support-ft@canary', 'local-support-ft@shadow'], 'requires' => ['dpa_signed'], 'ladder' => ['cheaper_role', 'tighter_limits', 'cache_only', 'search']],
        'triage.draft' => ['provider' => 'local', 'profile' => 'grounded', 'escalate_to' => 'frontier', 'background' => true],          // Chapter 36: nobody is waiting on a draft: over budget it pauses instead of degrading
        'invoice.extract' => ['provider' => 'vision', 'profile' => 'deterministic', 'allowed_providers' => ['local', 'vision']],   // scanned documents never leave
        'conversation.summarize' => ['provider' => 'local', 'profile' => 'deterministic', 'background' => true],
        'reminder.draft' => ['background' => true, 'team' => 'billing'],
        'widget.ask' => ['provider' => 'local', 'profile' => 'grounded', 'allowed_providers' => ['local'], 'ladder' => ['search']],     // Chapter 35: the public widget never reaches a hosted model, whatever anyone configures
    ],

    // Chapter 34: what may leave the app, what is found before it does, and how long the copies we cannot delete on request live.
    'privacy' => [
        'presidio_url' => env('PRESIDIO_URL'),                                   // the privacy profile's NER service; empty = names and addresses cannot be screened, so hosted calls go to `local`
        'connect_timeout' => env('PRESIDIO_CONNECT_TIMEOUT', 1),
        'timeout' => env('PRESIDIO_TIMEOUT', 5),
        'cooldown_seconds' => env('PRESIDIO_COOLDOWN', 30),
        'score_threshold' => (float) env('PRESIDIO_SCORE_THRESHOLD', 0.5),
        'phone_region' => env('AI_PRIVACY_PHONE_REGION', 'US'),                  // libphonenumber's default region for numbers written without a country code
        'own_domains' => array_filter(explode(',', (string) env('AI_PRIVACY_OWN_DOMAINS', 'ledgerly.test'))),   // addresses of ours are not a customer's
        'allow_terms' => ['Ledgerly', 'Pulse', 'Horizon'],                       // product names the name detector must not take for people
        'allow_patterns' => [],                                                  // more of the app's identifier formats, as regular expressions
        'vault' => [
            'ttl_days' => (int) env('AI_VAULT_TTL_DAYS', 7),                     // a vault lives as long as a conversation stays open for answers; it is rewritten with every new value
            'connection' => env('AI_VAULT_REDIS_CONNECTION', 'default'),
        ],
        'expiry' => [                                                            // what user:forget cannot delete on request, and when it goes by itself
            'logs_days' => (int) env('LOG_DAILY_DAYS', 14),
            'traces_days' => (int) env('AI_TRACE_RETENTION_DAYS', 7),
            'backups_days' => (int) env('BACKUP_RETENTION_DAYS', 35),
        ],
    ],

    'debug' => [
        'features' => [],            // feature names whose full request and response are written to storage/logs/ai-debug-*.log (Chapter 19, Topic 7)
        'conversations' => [],       // conversation ids, same
        'record_requests' => (bool) env('AI_RECORD_REQUESTS', false),   // keep each normalised request on its model_calls row so ai:compare --from-calls can replay it. Customer text: off by default
        'request_days' => 7,         // recorded requests are cleared after this long (conversations:retain)
    ],

    // Chapter 47: the same role reached through each driver. `eval:compare --drivers=raw,prism,ai-sdk` runs the golden set through these roles, one column each; `ai:contract-test` is the suite every driver must pass first.
    'drivers' => [
        'roles' => ['raw' => 'local', 'prism' => 'local-prism', 'ai-sdk' => 'local-ai-sdk'],
        'ttft_probes' => 5,
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

    // Chapter 37: a tiered router that escalates on failed checks. config/ai-routes.php is written by eval:route-table; with it missing or `enabled` false the assistant answers on the feature's own role, as before Chapter 37.
    // Thresholds are the cosine similarity of the best retrieved passage (0 to 1), measured on this help center with nomic-embed-text.
    'cascade' => [
        'enabled' => (bool) env('AI_CASCADE', true),
        'decline_below' => 0.40,                      // a how-to question whose best passage is this far away is not about Ledgerly's help center: decline, call no model
        'skip_cheapest_below' => 0.50,                // a question whose best passage is this weak is hard: start one tier up
        'wrongful_decline_above' => 0.60,             // the model said the help center does not cover it, yet a passage this close was retrieved: the answer failed
        'checks_retrieval' => ['howto', 'policy'],    // categories the retrieval check may decline (billing and account questions need tools, not passages)
        'category_map' => ['other' => 'policy'],      // router label => the golden set's name for it
        'floor' => 0.95,                              // eval:route-table: the scorecard's floor for the golden-set pass rate
        'margin' => 0.01,                             // ... and the margin over it that a role needs
        'cascade_gap_min' => 0.02,                    // a cheap role that misses the floor by more than this, and by no more than the max, starts a cascade; by less, the category goes straight to the next role
        'cascade_gap_max' => 0.10,
        'roles' => ['local', 'mid', 'frontier'],      // cheapest first
        'cost_per_answer_usd' => ['local' => 0.0, 'mid' => 0.002, 'frontier' => 0.013],      // ILLUSTRATIVE (Chapter 37, Topic 3): used to project savings before there is traffic to measure
        'human_cost_per_escalation_usd' => (float) env('AI_HUMAN_COST_PER_ESCALATION', 4.00),   // what a person's handling of an escalated conversation costs, for the total cost per conversation
    ],

    // Chapter 36: the ledger, the budgets and the breaker. Amounts are dollars here and integer micro-dollars everywhere else.
    // `enabled` puts BudgetedModel in the provider chain; with Redis unreachable a call goes through unguarded and the failure is logged. `store` = array keeps the counters in memory (one process: tests, a machine with no Redis).
    'budgets' => [
        'enabled' => (bool) env('AI_BUDGETS_ENABLED', true),
        'store' => env('AI_BUDGETS_STORE', 'redis'),
        'redis_connection' => env('AI_BUDGETS_REDIS_CONNECTION', 'default'),
        'warn_at' => 0.8,                                                    // the owning team (and a tenant's admin) is told here; features degrade at 100%
        'default_max_tokens' => 1024,                                        // a request that names no max_tokens is reserved for this many output tokens
        'unpriced_usd_per_million' => 15.0,                                  // what a hosted model nobody priced is reserved at, so it is never free by accident
        'tenant_month_usd' => ['starter' => 5, 'pro' => 25, 'enterprise' => 100, 'default' => 25],      // by plan: illustrative, set your own
        'tenant_day_share' => 0.2,                                           // one day may spend at most this share of the month
        'feature_day_usd' => ['support.reply' => 50, 'triage.draft' => 10, 'support.shadow' => 5, '*' => 10],
        'anonymous_day_usd' => 5,
        'app_hour_usd' => 250,                                               // far above normal: hitting it fires the global kill switch
        'anonymous_features' => ['widget.ask'],
        'breaker' => ['window_minutes' => 15, 'ratio' => 5, 'floor_usd' => 0.50, 'open_minutes' => 30, 'trickle_every' => 10],
        'notify' => [
            'oncall_email' => env('AI_ONCALL_EMAIL'),
            'channel_webhook' => env('AI_TEAM_CHANNEL_WEBHOOK'),
        ],
        'reconcile' => ['tolerance' => 0.02, 'usage_file' => env('AI_PROVIDER_USAGE_FILE')],          // a gap above this share of the provider's figure is reported
    ],

    'ledger' => [
        'enabled' => (bool) env('AI_LEDGER_ENABLED', true),
        'stream' => '{ai-budget}:ledger',
        'group' => 'ledger-writers',
        'batch' => 500,
        'queue' => 'ledger',
        'hardware_usd_per_million_tokens' => ['*' => (float) env('AI_HARDWARE_USD_PER_M', 0)],      // an amortized rate for your own hardware, so local use shows in a team's spend; 0 = tokens only
    ],

    // Who owns a feature: the prefixes of its name. A team is mailed its breaker and 80% notices and gets the weekly showback. An entry in ai.features.<name>.team wins.
    'teams' => [
        'support' => ['prefixes' => ['support.', 'ticket.', 'triage.', 'conversation.', 'retrieval.'], 'email' => [env('TEAM_SUPPORT_EMAIL')]],
        'billing' => ['prefixes' => ['invoice.', 'reminder.'], 'email' => [env('TEAM_BILLING_EMAIL')]],
        'growth' => ['prefixes' => ['widget.'], 'email' => [env('TEAM_GROWTH_EMAIL')]],
        'platform' => ['prefixes' => ['ai.', 'compare.', 'eval.', 'judge.', 'training.'], 'email' => [env('TEAM_PLATFORM_EMAIL')]],
        'finance' => ['prefixes' => [], 'email' => [env('TEAM_FINANCE_EMAIL')]],
    ],

    // Chapter 35, Topic 9: the thresholds ai:cost-audit holds the five postmortems' signatures to.
    'cost' => [
        'audit' => [
            'loop_multiple' => 3,                          // a conversation with more calls than this many times the median
            'loop_min_calls' => 6,                         // ...and at least this many
            'context_features' => ['support.reply', 'triage.draft'],
            'min_prefix_tokens' => 1024,                   // below this, nothing is cached by any provider
            'cached_share_floors' => ['triage.draft' => 0.50],
            'max_attempts_per_job' => 1.25,
            'anonymous_features' => ['widget.ask'],
            'anonymous_alert_share' => 0.10,
            'toxic_tail_share' => 0.01,
            'examples' => 3,
        ],
    ],

    // Chapter 35, Topic 7: the public "Ask Ledgerly" widget. Local role only; every number here is a cap. Daily tokens are for everyone together; Chapter 36 adds the same ceiling in dollars and the kill switch.
    'widget' => [
        'enabled' => (bool) env('AI_WIDGET_ENABLED', true),
        'max_tokens' => (int) env('AI_WIDGET_MAX_TOKENS', 150),
        'max_question_chars' => 400,
        'passages' => 3,
        'session_questions' => (int) env('AI_WIDGET_SESSION_QUESTIONS', 8),
        'daily_tokens' => (int) env('AI_WIDGET_DAILY_TOKENS', 1_500_000),
        'ip_per_minute' => (int) env('AI_WIDGET_IP_PER_MINUTE', 12),
        'challenge' => ['difficulty' => (int) env('AI_WIDGET_POW_DIFFICULTY', 4), 'ttl_seconds' => 300],
    ],

    // Chapter 35, Topic 6: what each provider may be sent, set BELOW the provider's own limits so its 429s stay rare. Requests are limited by the 'ai:<provider>' limiter on the AnswerQuestion job;
    // tokens per minute are enforced by the token bucket in Chapter 36. A provider that is not listed is not limited.
    'limits' => [
        'frontier' => [
            'requests_per_minute' => (int) env('FRONTIER_RPM', 300),
            'tokens_per_minute' => (int) env('FRONTIER_TPM', 200_000),
        ],
    ],

    // Chapter 37, Topic 7: work nobody is waiting on. A hosted provider's batch API (about half price, up to a day) or your own hardware while it is idle; `driver` per provider is `batch_driver` in its config (openai | local | fake), else hosted = openai.
    'batch' => [
        'discount' => (float) env('AI_BATCH_DISCOUNT', 0.5),             // the batch price as a share off the interactive one: ILLUSTRATIVE, copy your provider's
        'local_per_poll' => 25,                                          // requests a poll works through on your own hardware
        'kinds' => [
            'judge-nightly' => ['source' => JudgeNightlySource::class, 'feature' => 'eval.judge', 'provider' => 'frontier'],
            'synthesize-help-center' => ['source' => SynthesizeHelpCenterSource::class, 'feature' => 'eval.synthesize', 'provider' => 'frontier'],
        ],
    ],

    // Chapter 37, Topic 6: smaller requests. Each technique goes through the golden set before it ships (eval:gates, and eval:route-table for the pass rate per category), so trimming stays off until a run says it does not cost quality.
    'pruning' => [
        'dedupe' => (bool) env('AI_PRUNE_DEDUPE', true),
        'dedupe_similarity' => 0.85,                  // share of the shorter chunk's 5-word shingles another chunk repeats before the lower-ranked one is dropped
        'trim' => (bool) env('AI_PRUNE_TRIM', false),
        'trim_over_tokens' => 400,                    // only a chunk longer than this is trimmed
        'trim_to_tokens' => 250,                      // to the paragraphs that match the question, up to this many tokens
    ],

    // Chapter 35: a ceiling no setting elsewhere (top-k, chunk length) can raise. Sources are added best first until it is spent. call_budget is what ai:cost-audit holds a call's whole input to.
    'context' => [
        // Chapter 48: the TOON wire format the app writes, beside the package that writes it (helgesverre/toon, pinned in composer.json). The spec's own fixtures for this version are in tests/Fixtures/toon-spec/<version>/ and ToonConformanceTest runs them; a version with no fixtures directory has no conformance claim.
        'toon' => ['spec_version' => env('AI_TOON_SPEC_VERSION', '4.4')],
        'sources_max_tokens' => (int) env('AI_CONTEXT_SOURCES_MAX_TOKENS', 3_000),
        'call_budget' => (int) env('AI_CONTEXT_CALL_BUDGET', 6_000),
    ],

    // Chapter 35: help:ingest refuses a chunk set whose sizes moved (tokens; 512 is the reranker's window).
    'ingest' => [
        'chunk_gate' => [
            'enabled' => (bool) env('AI_INGEST_CHUNK_GATE', true),
            'max_tokens' => 512,
            'max_share_over' => 0.05,
            'max_mean_shift' => 0.5,
        ],
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
        'threshold' => (float) env('AI_SEMANTIC_CACHE_THRESHOLD', 0.92),       // cosine similarity; ai:cache-tune picks it from labeled pairs (Chapter 37, Topic 5); calibrate per category (Chapter 6, Topic 3)
        'max_false_hit_rate' => 0.01,         // the scorecard's limit for a different-answer pair that still hits (ai:cache-tune)
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
        'judge' => [                                                                  // the model that scores answers in Python: a different family from the model under test
            'base_url' => env('JUDGE_BASE_URL', env('AI_BASE_URL', 'http://localhost:11434/v1')),
            'model' => env('JUDGE_MODEL', env('AI_MODEL', 'llama3.1:8b')),
        ],
        'worker_group' => 'py-workers',                                               // the consumer group the Python workers share (py:status reads its pending list)
        'streams' => [
            'embed' => 'py:tasks:embed',
            'eval_sample' => 'py:eval-sample',                                       // Chapter 30: answers and conversations for the judge
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
        'freshness_minutes' => (int) env('AI_REPLY_FRESHNESS_MINUTES', 30),   // Chapter 35: a question this old is not answered by a model; the customer has gone (a short template instead)
        'stale_minutes' => 15,      // a reply still pending or streaming after this long lost its worker: mark it failed so no page spins forever
    ],

    'retention' => [
        'closed_conversation_days' => 90,
    ],

    // Chapter 33: the tool gate's budget, the restricted keys (one per external tool, empty = none) and the sandbox for generated code (off except on a worker host).
    'tools' => [
        'conversation_budget' => (int) env('AI_TOOL_CONVERSATION_BUDGET', 8),
        'credentials' => [
            'retry_payment' => ['key' => env('PAYMENTS_KEY_RETRY_PAYMENT'), 'label' => 'payments restricted key: retry', 'scopes' => ['charges:retry']],
        ],
        'documents' => [
            'role_arn' => env('DOCUMENTS_ROLE_ARN'),
            'bucket' => env('DOCUMENTS_BUCKET'),
        ],
    ],

    'sandbox' => [
        'enabled' => (bool) env('SANDBOX_ENABLED', false),
        'docker' => env('SANDBOX_DOCKER', 'docker'),
        'image' => env('SANDBOX_IMAGE', 'ledgerly-sandbox:vol2'),
        'runtime' => env('SANDBOX_RUNTIME', ''),            // runsc for gVisor, where installed
        'memory' => env('SANDBOX_MEMORY', '256m'),
        'cpus' => env('SANDBOX_CPUS', '0.5'),
        'pids' => (int) env('SANDBOX_PIDS', 64),
        'tmpfs' => env('SANDBOX_TMPFS', '64m'),
        'timeout' => (int) env('SANDBOX_TIMEOUT', 10),
        'max_output_bytes' => 65536,
        'directory' => env('SANDBOX_DIRECTORY', storage_path('sandbox')),
    ],

    // Chapter 32: nothing here is a secret. The canary is a phrase planted in every system message so that an answer repeating it is caught; set your own in production. link_hosts are the only hosts an answer may link to or load an image from (the app's own host is always allowed).
    'security' => [
        'canary' => env('AI_PROMPT_CANARY', 'amber-heron-4471-lattice'),
        'link_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_LINK_HOSTS', 'ledgerly.test,example.com'))))),
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
        'never_phrases' => [                                               // live traffic: a promise of money the business has not made
            'we will refund',
            "i've refunded",
            'i have refunded',
            'you will be refunded',
            "i'll refund",
            'full refund',
        ],
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

    // Chapter 30, Topic 4: experiments are named here and switched on with php artisan ai:experiment. A candidate is a PHP file that returns ['name', 'version', 'system', 'provider'] or a prompt class.
    'experiments' => [
        'support-prompt-v5' => [
            'feature' => 'support.reply',
            'active' => false,
            'mode' => 'ab',                                                // ab | shadow
            'treatment_percent' => 50,
            'control' => ['prompt' => SupportPrompt::class],
            'treatment' => ['candidate' => 'storage/samples/candidate-prompt.php'],
            'min_per_variant' => 3700,                                     // Lehr's rule: 16 × 0.7 × 0.3 ÷ 0.03²
            'stop_on' => ['never_gate_failures', 'cost_per_answer_usd'],
        ],
    ],

    // Chapter 30, Topic 1: a scorecard per feature, beside the feature it judges. direction: up (higher is better, floor below target) | down (lower is better, floor above target).
    'scorecards' => [
        'support.reply' => [
            'resolved_without_human_7d' => ['kind' => 'primary', 'target' => 0.70, 'floor' => 0.60],
            'golden_quality_pass' => ['kind' => 'quality', 'target' => 0.97, 'floor' => 0.95],
            'judge_faithfulness_sample' => ['kind' => 'quality', 'target' => 0.90, 'floor' => 0.85],
            'citation_validity_live' => ['kind' => 'quality', 'target' => 0.99, 'floor' => 0.97],
            'never_gate_failures' => ['kind' => 'guardrail', 'target' => 0, 'floor' => 0, 'direction' => 'down'],
            'disputes_escalated' => ['kind' => 'guardrail', 'target' => 1.0, 'floor' => 0.98],
            'cost_per_answer_usd' => ['kind' => 'guardrail', 'target' => 0.002, 'floor' => 0.006, 'direction' => 'down'],
            'cached_input_share' => ['kind' => 'guardrail', 'target' => 0.80, 'floor' => 0.60],     // Chapter 35: input tokens served from the prompt cache; below the floor, a prefix stopped caching
            'ttft_p95_seconds' => ['kind' => 'guardrail', 'target' => 1.5, 'floor' => 3.0, 'direction' => 'down'],
        ],
        'ticket.classify' => [
            'agreement_with_agent_label' => ['kind' => 'primary', 'target' => 0.92, 'floor' => 0.88],
            'cost_per_ticket_usd' => ['kind' => 'guardrail', 'target' => 0.0005, 'floor' => 0.002, 'direction' => 'down'],
        ],
    ],

    // Chapter 44: the model registry, the servers a registered version is put on, and what an artifact must prove before a server loads it (Topics 6 to 9).
    'registry' => [
        'roles' => ['billing' => 'local-billing-ft', 'support' => 'local-support-ft'],   // registry name => the provider role that serves it
        'features' => ['support.reply' => ['billing', 'support']],                         // which fine-tunes a feature's answers come from (the guardrail looks here)
        'shadow_percent' => (int) env('REGISTRY_SHADOW_PERCENT', 10),
        'warm_days' => (int) env('REGISTRY_WARM_DAYS', 7),                      // a previous version stays loaded this long, so rollback is instant
        'rollback_window_days' => 7,                                            // a guardrail breach this soon after a promotion rolls it back
        'base_role' => 'local',                                                 // the role the criteria compare a fine-tune with
        'criteria' => [                                                         // docs/adr/fine-tune-local-billing.md, "Success criteria", as numbers; promotion refuses what is missing or below them
            ['metric' => 'golden_pass_rate', 'min' => 0.96],
            ['metric' => 'never_failures', 'max' => 0],
            ['metric' => 'injection_model_success_rate', 'max_vs_base' => 0.0],
            ['metric' => 'tripwire_all_largest_drop', 'max' => 2.0],
            ['metric' => 'input_tokens_per_answer', 'max_ratio_vs_base' => 0.70],
        ],
        'guardrails' => ['min_answers' => (int) env('REGISTRY_GUARDRAIL_MIN_ANSWERS', 20), 'days' => 1],
        'servers' => [                                                          // by name, as serve:fine-tune --ollama= and --vllm= take them
            'local' => ['kind' => 'ollama', 'url' => env('OLLAMA_NATIVE_URL') ?: 'http://localhost:11434', 'host' => env('OLLAMA_NATIVE_HOST')],
            'eu-billing-box' => ['kind' => 'ollama', 'url' => env('REGISTRY_OLLAMA_URL'), 'host' => env('REGISTRY_OLLAMA_HOST')],
            'gpu-eu-1' => [
                'kind' => 'vllm',
                'url' => env('REGISTRY_VLLM_URL'),
                'api_key' => env('VLLM_API_KEY'),
                'base' => env('REGISTRY_VLLM_BASE', 'meta-llama/Llama-3.1-8B-Instruct'),
                'base_revision' => env('BASE_REVISION'),
                'adapters_path' => env('REGISTRY_VLLM_ADAPTERS_PATH') ?: '/models/adapters',
                'max_lora_rank' => (int) env('REGISTRY_VLLM_MAX_LORA_RANK', 16),
            ],
        ],
        'ollama_binary' => env('OLLAMA_BINARY', 'ollama'),
        'num_ctx' => 8192,
        'mirror' => env('REGISTRY_MIRROR') ?: storage_path('app/models/mirror'),   // approved bases, mirrored where production can read them (Hugging Face cache layout, or org/name@revision)
        'hub' => env('HF_ENDPOINT', 'https://huggingface.co'),
        'verify_base' => (bool) env('REGISTRY_VERIFY_BASE', true),
        'archive' => env('REGISTRY_ARCHIVE') ?: storage_path('app/models-archive'),
        'signing' => [
            'driver' => env('REGISTRY_SIGNER', 'ed25519'),                      // ed25519 (libsodium) or cosign
            'required' => (bool) env('REGISTRY_REQUIRE_SIGNATURES', true),      // an unsigned artifact fails registry:verify and is not loaded
            'pipeline' => env('REGISTRY_SIGNING_PIPELINE', 'ledgerly-training'),
            'keys' => ['ledgerly-training' => env('REGISTRY_SIGNING_PUBLIC_KEY')],
            'private_key_file' => env('REGISTRY_SIGNING_KEY_FILE'),             // only the pipeline has this
            'cosign' => ['binary' => env('COSIGN_BINARY', 'cosign'), 'key' => env('COSIGN_KEY'), 'password' => env('COSIGN_PASSWORD'), 'public_keys' => ['ledgerly-training' => env('COSIGN_PUBLIC_KEY')]],
        ],
    ],

    // Chapter 46: distillation. The teacher is a role with a recorded distillation_basis (Topic 2); a category is an area of the help center: which articles the teacher writes questions about, and which articles a real question's answer must have cited.
    'distillation' => [
        'teacher' => env('AI_DISTILL_TEACHER') ?: 'mid-in-region',
        'categories' => [
            'howto' => ['articles' => ['getting-started', 'invoices', 'customers', 'recurring', 'reminders', 'payments', 'taxes', 'troubleshooting', 'security']],
            'plans' => ['articles' => ['billing/change-your-plan', 'billing/plans-and-pricing', 'billing/plan-limits', 'billing/annual-vs-monthly-billing', 'billing/cancel-your-subscription', 'getting-started/choose-a-plan', 'getting-started/free-trial']],
            'integrations' => ['articles' => ['integrations']],
        ],
        'questions_per_article' => 2,                                            // teacher-written questions asked of each help-center article
        'retrieve' => 4,                                                         // passages the teacher is shown, as production shows them
        'judge_floor' => 4,                                                      // a judge score below this rejects the answer (Chapter 41's pass: 4 or 5)
        'review_sample' => 0.10,                                                 // the share of survivors a person reads
        'validation_share' => 0.08,
        'max_tokens' => 350,
    ],

    // Chapter 44, Topic 7: where the app pages a team about a model it rolled back on its own.
    'alerts' => [
        'channel' => env('AI_ALERTS_CHANNEL', '#ai-quality'),
    ],

    // Chapter 31: where ai:sample --trace points the link. The app exports through OTEL_* variables (.env.example); this is only the Jaeger UI's address on your machine.
    'tracing' => [
        'jaeger_url' => env('JAEGER_URL', 'http://localhost:16686'),
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

    // Chapter 40: training data. `hash_key` keys the one-way hash of each source conversation, tenant and user in a dataset (Chapter 34's erasure finds examples by it); caps are the largest share of a dataset one tenant or user may supply, and `retire_after_days` is when a dataset version replaced after an erasure is deleted (the backup window).
    'training' => [
        'hash_key' => env('AI_TRAINING_HASH_KEY'),
        'datasets_path' => storage_path('datasets'),
        'caps' => ['tenant' => 0.05, 'user' => 0.01, 'subcategory' => 0.40],
        'retire_after_days' => (int) env('BACKUP_RETENTION_DAYS', 35),
        'region_disk_prefix' => 'training-',
        'tokenizer' => env('TEMPLATE_CHECK_TOKENIZER', 'meta-llama/Llama-3.1-8B-Instruct'),   // the base the adapter trains on: ai:template-check renders with this tokenizer's chat template
    ],

    // Chapter 41, Topic 6 (eval:compare): the roles compared with their bases, the judge's provider (a different family from the model under test), and how lm_eval reaches each role. A hosted model has no log-probabilities, so it is `generation_only` and runs through its chat endpoint
    // (lm_eval reads the key from OPENAI_API_KEY); a local model runs from its weights, which Chapter 42's export leaves in training/runs/billing/fused (BILLING_FT_FUSED_PATH).
    'compare' => [
        'judge_provider' => env('COMPARE_JUDGE_PROVIDER', 'local'),
        'max_drop' => 2.0,
        'bases' => ['frontier-billing-ft' => 'frontier', 'local-billing-ft' => 'local'],
        'tripwire' => [
            'frontier' => ['model' => 'local-chat-completions', 'from_provider' => 'frontier', 'generation_only' => true],
            'frontier-billing-ft' => ['model' => 'local-chat-completions', 'from_provider' => 'frontier-billing-ft', 'generation_only' => true],
            'local' => ['model' => 'hf', 'model_args' => 'pretrained='.env('TEMPLATE_CHECK_TOKENIZER', 'meta-llama/Llama-3.1-8B-Instruct').',dtype=bfloat16'],
            'local-billing-ft' => ['model' => 'hf', 'model_args' => 'pretrained='.env('BILLING_FT_FUSED_PATH', 'models/ledgerly-billing-ft/fused').',dtype=bfloat16'],
        ],
    ],

    // Chapter 41: hosted fine-tuning. `drivers` says how each provider's fine-tuning API is spoken to (`openai` for an OpenAI-shaped API, `fake` for the offline Build); `max_tokens_per_row` is the provider's documented row limit that ai:ft:prepare validates against;
    // `fake` is the offline provider: a job that finishes after `seconds`, with its state in `directory`. `fake_chat` turns on POST /fake-ft/v1/chat/completions, which answers the fake model's ID with scripted billing replies (development only, never in production).
    'fine_tuning' => [
        'drivers' => ['frontier' => env('FRONTIER_FT_DRIVER', 'openai')],
        'directory' => storage_path('ft'),
        'max_tokens_per_row' => (int) env('FT_MAX_TOKENS_PER_ROW', 16_384),
        'default_epochs' => 3,
        'notify_team' => 'platform',
        'fake' => ['directory' => storage_path('app/fake-fine-tuning'), 'seconds' => (int) env('FT_FAKE_SECONDS', 20)],
        'fake_chat' => (bool) env('AI_FAKE_FT_CHAT', false),
        'mlflow_experiment' => 'hosted-fine-tunes',
    ],
];
