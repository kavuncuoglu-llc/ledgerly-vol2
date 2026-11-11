# Python libraries: what the companion app adopts, defers and leaves alone

Status: accepted · Owner: platform team · Review: when a Section 7 chapter adds a dependency, or when someone proposes a library that is not on this list

## Context

Ledgerly is a Laravel app. Volume 1 built the model layer in PHP: providers, prompts, structured output, tools, retrieval, conversations, and the golden set that measures them. Volume 2 adds Python for the work that only has libraries there: training, evaluation frameworks, cross-encoders, PII models, batch embedding.

The Python AI ecosystem sorts into four layers (Figure 28-1): orchestration frameworks, model and training libraries, evaluation tools, and trackers. This record names a decision for every library on that map, so the next proposal starts from the reasoning instead of from nothing.

Two facts drive most of the decisions. The orchestration layer duplicates code the app already has, in a second language, between the app and every model call. And the training sets are customer conversations: redacted, but still customer data, which a tracker stores samples of.

## Decisions

| Library | Layer | Decision | Why |
| --- | --- | --- | --- |
| transformers | Model and training | Adopted | Loads, runs and trains open models; needed from Chapter 26. Pinned `>=4.57,<5` (the `nomic-embed-text` code the Chapter 27 worker runs does not load on 5.x) |
| datasets | Model and training | Adopted | Arrow-backed rows, cached transforms, reproducible splits; every Section 7 run starts from one |
| sentence-transformers | Model and training | Adopted | The reranking sidecar since Volume 1, the Chapter 27 embedding worker, and the Chapter 46 fine-tunes |
| peft | Model and training | Adopted in Section 7 | Small adapters on a frozen base; the fine-tuning stack |
| trl | Model and training | Adopted in Section 7 | SFT and preference trainers |
| accelerate | Model and training | Adopted in Section 7 | Device placement, one GPU or several |
| bitsandbytes | Model and training | Adopted in Section 7 | 4-bit base for QLoRA; the NVIDIA path |
| Unsloth | Model and training | Adopted in Section 7 | Faster, leaner versions of the same stack; the NVIDIA path (Chapter 43) |
| Axolotl | Model and training | Adopted in Section 7 | Config-driven runs and sweeps on rented GPUs (Chapter 43) |
| mlx-lm | Model and training | Adopted in Chapter 42 | The Apple Silicon fine-tuning path; the `mlx` extra |
| MLflow | Tracker | Adopted | Self-hosted tracking for customer-derived data; the `mlflow` Compose profile |
| promptfoo | Evaluation | Adopted in Section 6A | Prompts and features against assertions, in CI beside Pest; Node, not Python |
| DeepEval | Evaluation | Adopted in Section 6A | Output-quality metrics, nightly |
| RAGAS | Evaluation | Adopted in Section 6A | Faithfulness and retrieval metrics, nightly and after retrieval changes |
| lm-evaluation-harness | Evaluation | Adopted in Section 6A | Standard benchmarks before a fine-tune is promoted |
| DSPy | Orchestration | Offline only | Optimize a prompt against the golden set in a notebook, then copy the winning text into a versioned PHP prompt class. Never in the request path |
| LangChain, LangGraph | Orchestration | Not used | Duplicates `AiManager`, the prompt classes, `StructuredClient`, `ToolRunner` and `AgentRunner`; would put a second language and someone else's release schedule in the request path |
| LlamaIndex | Orchestration | Not used | Duplicates the ingest pipeline, `PgRetriever` and the reranking sidecar |
| Weights & Biases | Tracker | Not used | Hosted by default; MLflow keeps run data on our infrastructure. If the data were synthetic or public, or covered by an agreement, the scripts would change by one line (`report_to`) |

## Options

1. Adopt an orchestration framework for the whole assistant. Rejected: every concept it wraps already exists here, tested, in PHP (Figure 28-2), and a framework in the request path is an abstraction we cannot see through, on a release schedule we do not control, in the language the rest of the app is not written in.
2. Adopt nothing from Python and stay in PHP. Rejected: training, cross-encoders, PII detection and the evaluation frameworks only exist there.
3. Adopt the model, evaluation and tracking layers; keep orchestration in PHP; use DSPy offline. Chosen.

## Plan and upkeep

The boundary rules live in Chapter 27: Python computes and returns results, every decision and every database write stays in PHP, and every crossing has a contract, a timeout, an idempotency key and a correlation ID. A library that does not fit those rules does not get adopted.

Versions are pinned in `training/uv.lock`. Read the documentation for the locked version, not the newest one: these libraries rename arguments between minor releases, and a deprecation warning is the library announcing that the next release breaks that line.

## Chunk length and the reranker

`training/scripts/dataset_stats.py` measures the help center against the reranker's 512-token limit (the sidecar's `max_length`) with the reranker's own tokenizer, and records the run in MLflow (experiment `help-center-chunks`, run `chunk-lengths`). Measured on 2026-11-11 over the 330 embedded shared chunks of the seeded help center (`help:export`, then `dataset_stats.py`; the text measured is what `RerankingRetriever` scores): longest chunk 392 tokens, mean 207, **none over 512 (0.0%)**. Volume 1's chunk size and the reranker's limit agree, so nothing is truncated before scoring. No change to the chunk size or the reranker is needed; run the script again after `help:ingest` changes the strategy (parent-child sections, a larger `child_tokens`) or a customer uploads long documents.

## Exit

Revisit a decision when its reason stops being true: a framework that stops duplicating what the app has, a tracker whose data terms change, a library whose release schedule becomes a risk. Record the change here with the date and the reason.
