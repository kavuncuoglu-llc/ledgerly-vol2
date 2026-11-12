# AI packages behind ModelClient

Status: accepted (Prism adopted as a driver, the Laravel AI SDK measured and kept off the support path, Neuron deferred) · Owner: platform team · Review: 2027-02-11

(`ModelClient` is this repository's `App\Ai\Contracts\ChatModel`; the raw driver the book calls `RawHttpDriver` is `OpenAiCompatibleDriver`.)

## Context

The app reached Chapter 47 on raw HTTP: one driver class for every OpenAI-shaped server, with tracing, budgets, redaction, retries and the usage log as decorators around `ChatModel`. Three packages could take over some of that code: Prism (`prism-php/prism`), the Laravel AI SDK (`laravel/ai`) and Neuron (`neuron-core/neuron-ai`). Each was put through the four questions of Figure 47-1 and, where it could sit behind the interface, installed as a driver: `PrismDriver` and `AiSdkDriver`, both pinned exactly in `composer.json` (0.101.0 and 1.3.0, the newest releases that were more than two weeks old on the day of the build). Neuron was read from Packagist's metadata and its release archive and not installed.

Every driver must pass one suite, `php artisan ai:contract-test`: normalised text and finish reasons, exact usage, tool calls handed back unrun for `ToolGate`, a reply that validates against its schema, streams in the app's event order, provider failures as the app's own exception types, a faithful request, and nothing added that the app did not ask for. The suite runs on the two wire formats a driver can speak (Ollama's `/api/chat`, recorded from a live Ollama, and `/chat/completions`) with the HTTP faked, so it needs no server and no key. A line a package cannot pass is written down in `ContractSuite::GAPS` with the reason, and the suite fails if the gap ever closes without the line being removed.

## Decision

- **`OpenAiCompatibleDriver` stays the driver for local models and the fallback for every provider.** It is the only driver that passes every line, and the only one the app can read end to end.
- **`PrismDriver` is adopted as an available driver, and the role to move first is a hosted one.** It passes the suite with two known gaps (below) and matches the raw driver on the golden slice. Nothing hosted is set up on this machine and no hosted API was called, so the claim the book makes for it, that it removes most of the per-provider request code without moving prompts, memory or controls, rests on the Ollama measurements and the contract suite and is not yet measured against a hosted provider. The role `local-prism` is in `config/ai.php` for experiments; no feature is moved onto it.
- **The Laravel AI SDK is not used on the support path.** Behind `ChatModel` it is a poor fit for an app that runs its own tool loop: its `prompt()` needs a final user message, so a history that ends with a tool result goes out with an empty user turn after it. On the golden slice that cost 37 points of pass rate and 2.5 times the input tokens (below). Its memory tables would also duplicate `conversations` and `messages`; they are published, never run, and the app creates none of them. The SDK stays in view for Volume 3's transcription and audio services.
- **Neuron is not adopted now.** It is re-evaluated when Volume 3 builds workflow-heavy projects. It is on the audit list.

## What the packages did that could not be seen

Found by reading the packages and recording requests, in the order Figure 47-1 asks:

- `laravel/ai` 1.3.0 does not depend on Prism (the book says its tree includes Prism at the pinned versions): its tree is `illuminate/*`, `laravel/prompts`, `laravel/serializable-closure` and `symfony/yaml`. Provider differences live in the SDK's own gateways.
- `laravel/ai` merges its own `config/ai.php` into the application's `ai` key, the same key this app's `config/ai.php` uses. The app's values win, SDK-only keys (`default_for_*`, `caching`) are added, and the SDK would read its providers from `ai.providers`, which is the app's list of roles. `AiSdkDriver` therefore builds an on-demand SDK provider per role (`Ai::build`) and never reads `ai.providers`.
- The SDK retries a 429 that carries `Retry-After` inside its own gateway: two HTTP attempts per driver attempt, so up to six with the app's `RetryingModel` around it.
- Prism's Ollama provider throws away `Retry-After` on a 429, and sends `num_predict: 2048` whenever the request names no limit, a cap on every answer that the app never chose. Prism's `openai` provider speaks the Responses API, not `/chat/completions`, so it is not a drop-in for an OpenAI-compatible server and Prism has no generic provider for one.
- Neither package writes anything of its own: Prism has no tables and no stores, and the SDK's migrations are offered for publishing and not run. Neither sends anywhere but the role's own base URL (a test asserts it). Prism Server is off by default.

## Contract suite

`php artisan ai:contract-test`, 2026-11-11: 68 passed, 4 known gaps, 0 failed, over raw (both wires), Prism (Ollama wire) and the SDK (both wires).

| Driver | Result |
| --- | --- |
| raw | all lines pass on both wires |
| prism | passes with two known gaps: Retry-After is lost on a 429; `num_predict: 2048` is added when no limit is asked for |
| ai-sdk | passes with one known gap on each wire: the empty user turn after a tool result. A 429 takes two HTTP attempts. The cached-token mapping the book expected to need (Figure 47-9) was not needed: the SDK reports `cached_tokens` from an OpenAI-compatible server |

## What would change this

- A hosted provider set up behind `PrismDriver`, measured with `eval:compare --drivers`: a pass rate or a cost per answer worse than the raw driver's, or a hosted-provider line the suite cannot express.
- Prism fixing either gap upstream (the suite then fails until its line is removed), or the SDK accepting a history that ends with a tool result: re-measure the SDK on the support path.
- A feature the app cannot build on `ChatModel` (transcription, audio, managed vector stores), which is where the SDK earns its place.
- A release gap or an abandoned flag on Prism, or a fork that passes the suite (`particle-academy/prism`, a drop-in fork at 0.147.2 today, is the fallback to try first): swapping is one driver and a suite run.

## Measurements

`php artisan eval:compare --set=golden:support --drivers=raw,prism,ai-sdk --with=gates --metrics=pass,facts,input_tokens,ttft,p50 --limit=8`, native Ollama 0.40.15 serving `llama3.1:8b`, the support slice's first eight cases (a quick look, not a study: one case is 12.5 points), semantic cache off, reranker off. Input tokens are what Ollama reports as evaluated, so its prefix cache makes them depend on the order the runs went in. The time to first token is the median of five probes through the whole decorator chain.

| | raw | prism | ai-sdk |
| --- | --- | --- | --- |
| Golden pass rate | 50% | 50% | 13% |
| Input tokens per answer | ~3,436 | ~3,204 | ~8,450 |
| Answer latency, p50 | 31.2 s | 20.8 s | 31.1 s |
| Time to first token, p50 | 0.132 s | 0.121 s | 0.121 s |
| Contract suite | pass | pass, 2 known gaps | pass, 1 known gap |
| Driver code | `OpenAiCompatibleDriver`, 477 lines (chat, stream, embeddings, native Ollama, grammar) and `ProviderErrors` | `PrismDriver` and `PrismTranslator`, 447 lines | `AiSdkDriver` and four helpers, 565 lines |

The prediction the chapter asks for: the SDK adds the most tokens by far. The empty user turn is the likely cause (the model is asked a question that is not there and answers it again), but the run did not isolate it: the contract gap shows the turn is sent, and the numbers show what the turn cost. Two of the three drivers fail a contract line, none fails the suite.
