# Ledgerly: Volume 2 companion code

The companion app for *Large Language Models for Laravel Developers, Volume 2 of 3: Running, Training, and Securing Large Language Models*. It is Ledgerly, a fictional invoicing product with a support assistant, exactly as Volume 1 leaves it. This volume adds evaluation, tracing, security, cost controls and fine-tuned models to it, one chapter at a time.

This repository is public and free. You do not need an account, a claim code or proof of purchase to clone it.

The book: [Running, Training, and Securing Large Language Models](https://kavuncuoglu.com/books/llm/running-training-and-securing-large-language-models) (Volume 2 of 3). It continues the app that [The Foundations of Large Language Models](https://kavuncuoglu.com/books/llm/foundations-of-large-language-models) (Volume 1) builds; `vol2-start` below is that app, so you can start here without Volume 1's repository.

## Get the code

```bash
git clone https://github.com/kavuncuoglu-llc/ledgerly-vol2.git ledgerly
cd ledgerly

git tag --list 'ch*'          # ch26 ... ch51
git checkout vol2-start       # where Chapter 0 begins
git diff ch28 ch29            # exactly what Chapter 29 added
git checkout main             # back to the newest code
```

No Git? On GitHub choose a tag from the branch menu, then Code, then Download ZIP. Every tag has its own download.

Each chapter of this volume is a tag, `ch26` through `ch51`, taken at the end of that chapter. `vol2-start` is the app as Volume 1 leaves it (its Chapter 25), with this README. `vol3-start` is the state after Chapter 51, which Volume 3 builds on. Chapter tags never move. A fix goes on `main` with an entry in [CHANGELOG.md](CHANGELOG.md), and a chapter whose code had to change gets a new tag next to the old one, such as `ch32-r2`.

## Chapter tags

| Tag | What it contains |
| --- | --- |
| `vol2-start` | Volume 1's finished app, ready to run: the support assistant, the help center, four local model servers, `ai:doctor`, Horizon and Reverb. |
| `ch26` | `training/`: a `uv` project (Python 3.12, `pyproject.toml`, `uv.lock`, extras `cuda`, `rocm` and `mlx`) with `hello_model.py`, `tokens.py`, `embed_compare.py` and `token_lengths.py`, and `ops/compose/ollama.localhost.yml`. |
| `ch27` | Python beside PHP: JSON Schema contracts in `contracts/` validated by PHP and by generated Pydantic models, the embedding worker (`py-worker`) and `py:listen` over Redis streams, the shadow table `help_chunk_vectors`, `ai:reembed`, `ai:compare-vectors`, `ai:promote-vectors`, `retrieval:eval --vectors=shadow`, `dataset:validate`, the first CI (`.github/workflows/ci.yml`: PHP, Python and contracts jobs). |
| `ch28` | The Python AI stack, mapped: the `mlflow` Compose profile (`ops/compose/mlflow.yml`, `127.0.0.1:5050`), `help:export --jsonl`, `training/scripts/dataset_stats.py`, and the decision record `docs/adr/python-libraries.md`. |
| `ch29` | Tests for a system whose output varies: failure-path tests with `Ai::fake()`, prompt snapshots and `prompts.lock`, the golden set in `tests/golden/`, `POST /eval/answer` (only with `AI_EVAL_ENABLED=true`), promptfoo gates in `evals/`, `eval:gates`, `eval:check`, `eval:synthesize`, `eval:capture` and friends, and `.github/workflows/ai-evals.yml`. |
| `ch30` | Evaluation as an ongoing practice: thumbs and reason chips (`answer_feedback`), `ComputeOnlineSignals`, `eval:sample` with the Python judge (`training/evals/`, RAGAS and DeepEval), `Experiment` with shadow runs and A/B tests (`ai:experiment`), `eval:scorecard`, `eval:tripwire` and `ai:models:audit`. |
| `ch31` | Observability: the `observability` Compose profile (OpenTelemetry collector that deletes content attributes, Jaeger on `127.0.0.1:16686`), `TracingModel` and tool spans, trace context through queues and Redis streams, `trace_id`/`span_id` on `model_calls` and `tool_calls`, Laravel Pulse cards (`/pulse`), `ai:sample --trace` and `ai:drift`. |
| `ch32` | Direct and indirect prompt injection: `Untrusted::wrap` (random-token tags) around every customer-written turn, retrieved passage and tool-result text; the untrusted-content convention and a canary in the system message; quarantined ticket extraction (the privileged triage agent never sees the ticket body); `ExtractionGuard`, `AnswerGuard` and `AnswerRenderer` (blocked answers, links and images to unlisted hosts stripped); the fooled-model Pest suite (`--group=security`), injection fixtures in `tests/Fixtures/injection/` and `php artisan eval:injection`. |
| `ch33` | Tool guardrails: `ToolGate::dispatch` (schema, policy, budget, execution by declared risk, result scan, audit), the `ledgerly_tool_read` and `ledgerly_tool_write` Postgres roles with column grants, `pending_tool_actions` with single-use confirmation tokens, the new tools `check_payment_status`, `resend_invoices`, `retry_payment`, `change_billing_email` and `issue_credit`, the support agents' approval queue (`/staff/approvals`), per-tool restricted keys and scoped STS credentials, and the `ledgerly-sandbox` image with `sandbox:run`. |
| `ch34` | Data privacy and PII redaction: `RedactingModel` in the provider chain (detect, tokenize through an encrypted per-conversation vault, call, restore, streams included; hosted calls fail closed to `local` when names cannot be screened), validated detectors for cards, SSNs, emails, phones (libphonenumber) and IBANs with an allow-list for invoice and ticket numbers, the `privacy` Compose profile (Presidio behind `/pii/analyze`), a `privacy` block per provider and `allowed_providers`/`requires` per feature, declared fields per prompt, a log processor and an error-report scrubber, and `user:forget` for every deletable copy with an expiry report and an `erasure_requests` receipt (`--group=privacy`). |
| `ch35` | The anatomy of an AI surprise bill: `config/ai-pricing.php` and `CallCost` (every call priced from its logged usage; an unpriced model fails), `cached_input_tokens` and `cache_write_tokens` on `model_calls`, `ai:cost-audit` (cost per successful action, the toxic tail, five signature detectors) with `ai:cost-replay` for each postmortem, code ceilings on agent rounds, tokens and time and a stop on repeated tool errors, a hard token budget for retrieved sources and a chunk-size gate on `help:ingest`, the cacheable-prefix test, per-provider rate limits, cached steps and a freshness check on `AnswerQuestion`, and the public `/widget` (local role only, short answers, a proof-of-work challenge, its own budgets). |
| `ch36` | Real-time cost observability and budget governance: the append-only `ai_ledger` (integer micro-dollars, price version, five labels) written from a Redis stream by `ai:ledger-consume` and `WriteLedgerBatch` on a `ledger` Horizon queue, `BudgetedModel` (kill switch, token bucket, atomic all-or-none reservation with `reserve_all.lua`, settlement, release on failure), nested budgets per tenant, feature, anonymous traffic and the app hour, a degrade ladder for the support assistant (cheaper role, tighter limits, cache only, search and a person) and a pause for background features, `ai:spend-breaker`, `ai:kill`, `ai:spend`, `ai:showback` and `ai:reconcile`, and a Pulse budgets card. |
| `ch37` | Cost optimization and context engineering: `ai:cost-breakdown` (a call's cost by prompt section), `eval:route-table` and the committed `config/ai-routes.php` (the cheapest role that clears the scorecard's floor per category), `CascadingAnswer` inside the support assistant (start cheap, check, escalate with the same sources, hold back an attempt until it passes, hand off to a person) with `ai:cascade-report`, `ai:cache-tune` and cache invalidation by cited chunk version, `ai:cache-share`, deduplication and optional trimming of retrieved passages, per-feature `max_tokens`, `ai:batch:submit`, `ai:batch:poll` and `ai:batch:collect` for work nobody is waiting on, and the total cost per conversation including escalations. |
| `ch38` | When to fine-tune: `php artisan eval:failure-modes` (every failing billing case sorted into retrieval miss, coverage gap, behavior and format, arithmetic or unclear, with a judge for what the checks cannot place and a backlog file for the cases that are not fine-tuning problems), the decision record `docs/adr/fine-tune-local-billing.md` with its evidence, `docs/backlog/billing-retrieval-and-coverage.md`, and the `calculate_proration` tool so arithmetic stays out of training. |
| `ch39` | Fine-tuning methods: the first LoRA run at a size that finishes in minutes: `training/scripts/first_lora.py` (Qwen2.5-0.5B-Instruct, rank 16 on all linear layers, logged to the `first-lora` MLflow experiment), `quick_eval.py` (required facts present, base against base plus adapter, on 20 held-out cases in `tests/golden/billing_holdout.jsonl`), `training/data/mix.py` (replay mixing), and the 200 synthetic billing examples in `training/data/fixtures/billing_sft_small.jsonl` (`php artisan dataset:fixture` writes both from the seeded database). `peft` and `trl` are in the lock now, `trl` pinned below 1.0. |
| `ch40` | Building a training dataset: tenant training-use settings (off by default), a per-user exclusion and residency-aware exports; `php artisan dataset:export` (every answered turn rebuilt by `ContextAssembler::rebuild` with the fine-tuned system message, personal data replaced by consistent surrogates, sources hashed with `AI_TRAINING_HASH_KEY`); `dataset:review` (accept, edit, reject with reasons, a judge-sorted queue, a second reviewer's agreement); `training/data/clean.py` and `split.py` (filters, near-duplicates, decontamination against the golden set, balance caps, split by conversation, mixing); `dataset:publish`, `dataset:show`, `dataset:forget` and `dataset:scan` (planted instructions, trigger phrases, unsourced links, concentration); `ai:template-check` (training and serving chat templates compared by token); and `user:forget` finding the dataset versions that hold a person's conversations. `db:seed --class=TrainingCorpusSeeder` builds the corpus the Build runs on. |
| `ch41` | A hosted fine-tune as a baseline: `php artisan ai:ft:prepare` (the parts of `billing-v1` with no customer data, as chat JSON lines, every row validated and the tokens counted before anything is uploaded), `ai:ft:submit` (idempotent by dataset hash, base and settings), `ai:ft:poll` (every five minutes: events, loss to MLflow, a `training` ledger row, the uploaded files deleted, the team told), `ai:ft:cancel`, `ai:ft:register`; the `FineTuningClient` interface with an OpenAI-shaped driver and an offline fake one; the `fine_tune_jobs` table; the `frontier-billing-ft` role (pinned, with provenance, its own price and the short system message bound to it); `php artisan eval:compare` (roles compared on the golden billing slice) and `adr:append` (the result into the decision record); `dataset:allow`. No hosted account was used to build it: see "From `ch41`". |
| `ch42` | Fine-tuning on unified memory: `training/configs/billing-mlx.yaml` and `billing-peft.yaml` (the 8B runs, committed), `training/data/to_mlx.py` (prompt-and-completion rows to `mlx_lm.lora`'s chat files), `training/scripts/train_estimate.py` (a fifty-step trial, then the run's length), `run_mlx.py` (`mlx_lm.lora` with its losses copied to MLflow), `train_lora.py` (PyTorch, CUDA, ROCm, MPS or CPU) and `export_to_ollama.py` (fuse, convert to GGUF, quantize, create); `training/configs/*-smoke.yaml` run the same pipeline on a 135M model in a minute; the `local-billing-ft` role; and `registry:add` with the `model_registry` table (a candidate, with its dataset hash and artifact hashes). The 8B run itself was not made here: see "From `ch42`". |
| `ch43` | Fine-tuning on CUDA, with Unsloth, Axolotl and rented GPUs: `training/notebooks/billing_qlora_colab.ipynb` (a free-notebook QLoRA with Unsloth, non-customer data only), `train_lora.py --unsloth`, `training/configs/axolotl/billing-qlora.yml` and `training/scripts/sweep.py` (a grid of configs with fixed seeds and a repeated centre point; the thirteen it makes for the Build are committed in `training/configs/axolotl/sweep-billing/`, and a test regenerates them); `ops/cloud/gpu-session.sh train` (a rented session that checks the manifest first, uses an encrypted volume, has a hard time limit and an idle stop, pushes only the results and writes the ledger) with a `local` provider that runs the whole flow on this machine; `dataset:allows`, `ai:training-session` and `php artisan eval:sweep-report` (ranks a sweep's MLflow runs on the golden set, checks the winner, writes its settings into the production configs). The `cuda` extra now has `bitsandbytes` and `unsloth`. Nothing in it ran on an NVIDIA GPU or a rented machine: see "From `ch43`". |
| `ch44` | Quantizing, serving and releasing a fine-tune: `php artisan eval:quant-ladder` (the fine-tune and its base at each quantization level on the golden billing slice, the advantage at each level, the smallest level that keeps it), `training/scripts/quantize.py` (importance matrix from the training split's own text, then `llama-quantize`) with llama.cpp pinned by tag and commit (`training/llama-cpp.lock`, built by `ops/models/build-llama-cpp.sh`); `serve:fine-tune` (the merged GGUF to Ollama, the adapter to vLLM beside the exact base it was trained on, after the hash, signature and API-key checks); the model registry's stages, aliases and history (`registry:stage`, `promote`, `rollback`, `history`, `verify`, `sign`, `keygen`, `retire`, `sync`); the `billing@production` alias that `local-billing-ft` resolves its model from; the residency route with in-region roles only; `ai:guardrails` and the automatic rollback of a fresh promotion. Nothing in it ran on CUDA or vLLM: see "From `ch44`". |
| `ch45` | Training a small model from scratch: `training/tiny/` (`prepare_corpus.py` for Gutenberg books, the help center and templated support dialogue, cleaned, deduplicated and split by document; `train_tokenizer.py`, an 8,000-token byte-level BPE; `model.py`, a 13.8M-parameter GPT with a parameter count; `train.py` with warmup, cosine decay, clipping, resumable checkpoints and MLflow; `sample.py`; `sft.py`, post-training on about 500 question-and-answer pairs) and the registry guard: `registry:add` refuses anything that comes from `training/tiny/`. The run was made for real on an M1 Pro, at 6,000 steps instead of 10,000: see "From `ch45`". |

## Set up `vol2-start`

1. `cp ops/compose/.env.example .env && composer install && php artisan key:generate`. Set `AI_LOCAL_PROVIDER` (`ollama`, `llama`, `vllm` or `triton`). Add `FRONTIER_*` only if you have a key, and read what it means first: customer text can leave your network.
2. `docker compose --env-file .env -f ops/compose/base.yml -f ops/compose/<profile>.yml --profile <profile> up -d` with `<profile>` one of `ollama`, `llama`, `vllm`, `triton`; a Mac developer runs `-f ops/compose/macos.override.yml` with native Ollama instead. Postgres and Redis start with any of them. `llama`, `vllm` and `triton` need `LLAMA_API_KEY`, `VLLM_API_KEY` or `TRITON_API_KEY` in `.env`; Compose will not start them without one.
3. `php artisan migrate --seed && php artisan help:ingest && php artisan ai:doctor`
4. `php artisan serve`, `php artisan horizon` and `php artisan reverb:start` in three terminals, then open <http://127.0.0.1:8000/support> and sign in as `maya.okafor@acme-studios.example.com` with the password `password` (every seeded account uses it). Or run the app in containers too: `-f ops/compose/app.yml --profile app` (see "Chapter 25" below).

You need PHP 8.3 or later with the `gd`, `zip`, `pcntl`, `posix` and `pdo_pgsql` extensions, Composer, Docker and Git. Host ports, all on `127.0.0.1`: app 8000, Reverb 8080, Postgres 5432, Redis 6379, Ollama 11434, llama-server 8081, vLLM 8001, Triton 9000, Grafana 3000; every one has a variable in `.env` (`DB_PORT`, `REVERB_PORT`, `LLAMA_PORT`, ...) if it is taken on your machine. The tests need no model server: `vendor/bin/pest`.

If a host port is already taken on your machine, set its variable in `.env` before you start the containers: Compose and Laravel read the same values.

## The Python side: `training/`

From `ch26` the repository has a second project beside the Laravel app: `training/`, managed by [`uv`](https://docs.astral.sh/uv/) (built with 0.11; any recent version reads the lockfile). Python 3.12 is pinned in `training/.python-version`, and every dependency is pinned in the committed `training/uv.lock`.

```bash
cd training
uv python install 3.12
uv sync                      # CPU, or Apple Silicon (MPS)
uv sync --extra cuda         # NVIDIA: PyTorch's CUDA 12.8 build
uv sync --extra rocm         # AMD on Linux: PyTorch's ROCm 7.0 build
uv sync --extra mlx          # Apple Silicon: mlx and mlx-lm (Chapter 42)
uv run pytest                # the Python tests; none of them downloads a model
uv run python scripts/hello_model.py
```

The first run of a script downloads its model from the Hugging Face Hub into `~/.cache/huggingface` (set `HF_HOME` to move it). Nothing the tests do needs the network. Model revisions in the scripts are commit hashes, so they do not change under you. `training/data/*.jsonl` and the other exported datasets are git-ignored.

`embed_compare.py` calls Ollama on `http://localhost:11434` (`OLLAMA_URL`, without `/v1`, overrides it). On Linux with Ollama inside Compose, publish its port first: `docker compose -f ops/compose/base.yml -f ops/compose/ollama.yml -f ops/compose/ollama.localhost.yml --profile ollama up -d`.

### From `ch27`: Python beside PHP

```bash
docker compose --env-file .env -f ops/compose/base.yml --profile python up -d --build py-worker py-listen
php artisan queue:work redis --queue=default      # or Horizon: py:listen dispatches ApplyEmbeddings on the default queue
php artisan ai:reembed --via=python --model=nomic-ai/nomic-embed-text-v1.5 --batch=64
php artisan py:status
php artisan ai:compare-vectors --model=nomic-ai/nomic-embed-text-v1.5 --against=ollama
php artisan retrieval:eval --vectors=shadow
php artisan ai:promote-vectors --model=nomic-ai/nomic-embed-text-v1.5      # only if both look right
```

`py-worker` has Redis and no database credentials; the first start downloads the model (about 550 MB) into the `py-models` volume, so give it a few minutes (its health check waits ten). `py-listen` is `php artisan py:listen` in the app image; to run it on the host instead, `php artisan py:listen` with Redis on `REDIS_PORT`. On an NVIDIA host add `-f ops/compose/python.gpu.yml`. The messages are described by `contracts/*.json`; after you change one, `training/scripts/generate_contracts.sh` regenerates `training/contracts/` and CI fails if the committed copy is stale. `NOMIC_REVISION` and `NOMIC_CODE_REVISION` pin the two Hugging Face repositories whose code the worker runs; they are in `.env.example`.

### From `ch28`: MLflow, the help-center export, and the decision record

```bash
docker compose --env-file .env -f ops/compose/base.yml -f ops/compose/mlflow.yml --profile mlflow up -d mlflow
php artisan help:export --jsonl --to=training/data/help_chunks.jsonl
cd training && uv run python scripts/dataset_stats.py       # prints the share of chunks over the reranker's 512 tokens, and logs the run
open http://localhost:5050                                  # the run, its parameters, and its metrics
```

MLflow's server has no login: the file publishes it on `127.0.0.1` only (`MLFLOW_PORT`, default 5050), and it must stay there or behind a private network. Run data lives in the `mlflow-db` and `mlflow-artifacts` volumes. The training scripts read `MLFLOW_TRACKING_URI` (`http://localhost:5050` in `.env.example`). The image is pinned in `MLFLOW_IMAGE`. `docs/adr/python-libraries.md` records which Python libraries the app adopts, defers or leaves alone, and why.

### From `ch29`: golden set, gates and the evaluation endpoint

The Pest suite never calls a model. The gates do: they send the golden questions through the whole pipeline (retrieval, routing, tools, citation checking) against your local model, at temperature 0 with a seed.

```bash
# in .env: AI_EVAL_ENABLED=true, EVAL_TOKEN=<any long random string>, AI_MODEL=llama3.1:8b (or the model you built), BROADCAST_CONNECTION=null
php artisan migrate --seed && php artisan help:ingest
php artisan eval:gates --set=golden --sample=smoke          # in PHP: the 25-case smoke subset (all, human, synthetic, or a number)
php artisan serve --port=8000 &                              # the endpoint promptfoo calls: POST /eval/answer
php artisan eval:export-promptfoo --subset=smoke             # writes tests/golden/promptfoo.yaml (git-ignored)
EVAL_TOKEN=... npx promptfoo@0.125.1 eval -c evals/promptfooconfig.yaml -o results.json --no-cache
php artisan eval:check results.json --quality=0.95 --never=0
php artisan eval:synthesize --generator=late-invoices --count=40 --writer=local   # --writer=frontier needs FRONTIER_* in .env
php artisan prompts:lock                                    # after you bump a prompt's VERSION and review the snapshot
```

`/eval/answer` exists only when `AI_EVAL_ENABLED=true`, wants `EVAL_TOKEN` as a bearer token and answers only as the seeded fixture customers (`acme` is Acme Studios). Keep the flag off anywhere real customers are. `promptfoo` is a Node tool; the repository itself has no Node dependency, and `npx` fetches the pinned version. A CPU model takes 20 to 30 seconds per answer, so the smoke subset takes ten minutes or so. `eval:report --to=mlflow` logs a run to the tracker from Chapter 28. The workflow `.github/workflows/ai-evals.yml` runs the smoke subset on every push to `main` and the full set with the judge nightly; the judge needs `FRONTIER_MODEL` and `FRONTIER_KEY` as repository variables and secrets and is skipped without them.

### From `ch30`: feedback, signals, a judge, shadow runs and a scorecard

```bash
php artisan migrate                                                       # answer_feedback, answer_signals, online_judgments, experiments, shadow_answers
php artisan conversation:replay storage/samples/seeded --users=6 --feedback-rate=0.3     # simulated customers, three short scripts, a share of them click a thumb
php artisan ai:experiment support-prompt-v5 --mode=shadow --percent=10 --candidate=storage/samples/worse-prompt.php    # a candidate answers 10% of requests, unseen
php artisan eval:signals --date=$(date +%F)                               # re-asks, follow-up tickets, escalations and resolution (the nightly job does yesterday)
php artisan eval:sample --rate=1 --since=1d                               # answers and finished conversations to the Python judge
(cd training && uv run python -m workers.eval_worker)                     # scores them with RAGAS and DeepEval; set JUDGE_* in .env first
php artisan py:listen --once                                              # applies the scores
php artisan eval:scorecard support.reply --days=1 --by=variant
php artisan ai:models:audit
cd training && uv run lm_eval --model hf --model_args pretrained=Qwen/Qwen2.5-0.5B-Instruct --tasks arc_easy,hellaswag,ifeval,gsm8k --limit 20 --device mps --output_path runs/lm-eval/base
php artisan eval:tripwire runs/lm-eval/base runs/lm-eval/support-v1 --max-drop=2.0
```

`worse-prompt.php` is a candidate that is worse on purpose; `candidate-prompt.php` is the one to A/B test (`--mode=ab --percent=50`: a customer's variant is a hash of the experiment and the customer, so it never flips). The judge in `.env` (`JUDGE_BASE_URL`, `JUDGE_MODEL`) defaults to your local Ollama, which is fine for checking the wiring and wrong for judging: use a different model family for real scores. Scorecard targets and floors are in `config/ai.php` (`ai.scorecards`), deprecation dates and digests under `ai.providers`. The simulated users are one owner per seeded tenant, so `--users` stops at 40. `lm_eval` on a small model takes a while for the two generation tasks (`ifeval`, `gsm8k`); use `--limit`.

### From `ch31`: one question, one trace

```bash
docker compose --env-file .env -f ops/compose/base.yml -f ops/compose/observability.yml --profile observability up -d
# in .env: OTEL_PHP_AUTOLOAD_ENABLED=true (the other OTEL_* lines are already there; the app on the host exports to 127.0.0.1:4318)
php artisan migrate                                                       # trace_id and span_id on model_calls and tool_calls, the Pulse tables, messages.category
php artisan ai:sample "Why did my March invoice go up by \$18?" --trace      # prints  trace <id> · http://localhost:16686/trace/<id>
php artisan ai:drift --feature=support.reply --window=7d --baseline=28d   # scheduled daily; reads the audit trail only
open http://127.0.0.1:8000/pulse                                           # staff users only: AI tokens, cost, time to first token, breaker trips per feature
```

The collector deletes any span attribute named like prompt or completion content (`ops/compose/otel-collector.yaml`), whatever the code sets; the audit tables are where content lives. Jaeger keeps the last `JAEGER_MAX_TRACES` traces in memory. Both ports are bound to `127.0.0.1`; the Jaeger UI has no login. To trace the sidecar and the Python worker too, add `--profile sidecar --profile python` to the command above (the file adds `opentelemetry-instrument` and the exporter to both). The app image (`ops/app/Dockerfile`) now builds `ext-opentelemetry` 1.4.2 from PECL; on a host without it, manual spans (the model calls, the tools, the queue) work, and the auto-instrumentation of requests and queries does not. `OTEL_PHP_AUTOLOAD_ENABLED` is a real environment variable to the SDK, but the app starts the SDK itself after `.env` is read, so setting it in `.env` is enough. Tracing is off by default and adds nothing then.

### From `ch32`: injection defences and `eval:injection`

```bash
php artisan migrate                                                       # messages.guard: what the answer checks found
vendor/bin/pest --group=security                                          # the fooled-model suite and the fixture checks: no model, seconds
php artisan eval:injection --smoke                                        # 10 planted payloads through the real pipeline and your local model; exit 1 on any system-level success
php artisan eval:injection --carrier=invoice_line_item --report=mlflow     # one carrier; log the rates to MLflow (the mlflow profile)
```

`eval:injection` plants each payload in its carrier inside a database transaction (an invoice line item, a payment reference, a tenant-private help passage, a ticket body, or the customer's own message), asks the assistant (or runs triage for a ticket), reads what happened and rolls the plant back. It needs a seeded database (`php artisan migrate --seed`), `help:ingest` and a model; with `llama3.1:8b` expect a minute or two per fixture. Model-level success will not be zero. System-level success must be: the command prints which fixture broke through and exits 1. Set your own `AI_PROMPT_CANARY` in `.env` (the one in `.env.example` is public) and list the hosts an answer may link to in `AI_LINK_HOSTS`; a link or image to any other host is cut out of the stored answer. A blocked answer is stored with `status = blocked` and `messages.guard` names the rule that stopped it, never the text.

### From `ch33`: the tool gate, approvals and the sandbox

```bash
php artisan migrate                                              # roles + column grants, pending_tool_actions, payment_retries, invoice_credits, customers.billing_email_pending
vendor/bin/pest --group=tools                                     # every stage of the gate, every misuse of a confirmation, the grants themselves
docker build -t ledgerly-sandbox:vol2 ops/sandbox                  # the image for code a model wrote (Volume 3 uses it)
SANDBOX_ENABLED=true php artisan sandbox:run my_script.py --file=invoice.json   # runs /in/script.py with no network; the script writes /out/result.json
```

Every tool call goes through `App\Ai\Tools\ToolGate::dispatch`. Reads run in a read-only transaction after `SET LOCAL ROLE ledgerly_tool_read`; low-risk writes run under `ledgerly_tool_write`; a high-risk write only writes a pending action, and the customer (or, for credits, a support agent at `/staff/approvals`) approves it on a page that holds the token. The migration that creates the roles needs a database user allowed to `CREATE ROLE` (the Compose user is); roles are shared by every database on the server, the grants are per database. Your application must not connect as a superuser in production, or `SET LOCAL ROLE` is the only thing standing between a tool and the whole database; the tests run as one, so they prove the role, not the connection. Tools now select explicit columns (`Invoice::forTools()`): a tool that reads `invoices.notes` fails with `permission denied`, on purpose. Unknown arguments are refused, not ignored. `AI_TOOL_CONVERSATION_BUDGET` (default 8) caps the tool calls in one conversation.

The sandbox runs on a worker host with Docker, never the app host: `SANDBOX_ENABLED` is off by default. Add `SANDBOX_RUNTIME=runsc` where gVisor is installed. `PAYMENTS_KEY_RETRY_PAYMENT` is where a restricted key for `retry_payment` goes (the payment processor is fictional here); `DOCUMENTS_ROLE_ARN` and `DOCUMENTS_BUCKET` are for the short-lived credentials Volume 3's document tools mint.

### From `ch34`: privacy, redaction and `user:forget`

```bash
composer install && php artisan migrate                          # giggsey/libphonenumber-for-php; erasure_requests
vendor/bin/pest --group=privacy                                   # detectors, vault, decorator, logs, isolation, erasure
# the name and address detector (Presidio), in its own profile; the first start loads a language model:
docker compose --env-file .env -f ops/compose/base.yml -f ops/compose/privacy.yml --profile privacy up -d --build presidio
# in .env: PRESIDIO_URL=http://127.0.0.1:5002
php artisan user:forget customer:12 --reason="erasure request 2026-11-03"                # counts only
php artisan user:forget customer:12 --reason="erasure request 2026-11-03" --confirm     # deletes, reports what expires, writes the receipt
```

Every call to a provider whose `privacy.redact` is true (`frontier`, and `local-70b` with `LOCAL_70B_REDACT=true`) goes through `RedactingModel`: cards (Luhn), SSNs, emails, phone numbers (libphonenumber, region `AI_PRIVACY_PHONE_REGION`) and IBANs are found in PHP, names and addresses by Presidio, and each value is replaced by `<EMAIL_1>`-style placeholders kept in a vault in Redis (encrypted with `APP_KEY`, `AI_VAULT_TTL_DAYS`) and restored in the answer. Invoice numbers, ticket ids and help-center labels are never touched. **Without Presidio a hosted call is not made**: it is rerouted to the `local` role and the span says so (`ledgerly.redaction.rerouted`); the `local` role itself is not redacted, because nothing leaves the machine. `ChangeBillingEmail` is the one tool that may be handed a restored value (it implements `AcceptsVaultReferences`); every other tool sees the placeholder. Record the answers to Figure 34-2 (training, retention, ZDR, region, DPA) in the `privacy` block of each hosted provider in `config/ai.php`; `support.reply` may only escalate to a provider whose `dpa_signed` is true, and `invoice.extract` never leaves `local`/`vision`.

Logs are scrubbed on every channel (`App\Logging\ScrubPersonalData`, tapped in `config/logging.php`); for an error tracker, set its `before_send` to `[App\Logging\ErrorReportScrubber::class, 'beforeSend']` (no tracker is installed here). `user:forget` takes a user id or `customer:ID`; it deletes conversations, messages, memories, tool calls, pending actions, agent runs, feedback and signals, the cache entries and private passages of a customer, production golden cases and review-queue rows, and the vaults; it anonymizes `model_calls` (the row stays for cost history); and it prints what it cannot touch (logs, traces, backups, a provider's copy) with the expiry from `ai.privacy.expiry`. The Presidio image is built from `ops/privacy` (pinned in `requirements.txt`; the language model is `en_core_web_sm` 3.8.0, downloaded from GitHub at build time).

### From `ch35`: where the money goes

```bash
php artisan migrate                                  # cached_input_tokens (renamed from cached_tokens), cache_write_tokens, job_uuid, attempt
php artisan ai:cost-replay cache                     # fake usage rows that reproduce a postmortem: healthy, loop, context, cache, drain, anonymous
php artisan ai:cost-audit --since=7d --by=version    # cost per resolution, the toxic tail, five signatures (--strict exits 1 on one; --conversation=ID for turn by turn)
php artisan ai:cost-replay --wipe                    # remove only the replay rows
vendor/bin/pest tests/Feature/Ai/Cost                # each detector must flag its own replay
```

Prices live in `config/ai-pricing.php` (illustrative round numbers, dated, versioned; copy your provider's rates into `FRONTIER_RATE_IN`, `FRONTIER_RATE_OUT` and the cache rates, `.env.example` explains). A hosted model with no price is not free: the audit refuses it. The public page is `/widget`; it answers from shared help-center passages on the `local` role only, with a proof-of-work challenge solved in the browser, eight questions per session and a daily token budget (`AI_WIDGET_*`).

### From `ch36`: the ledger, the budgets and the kill switch

```bash
php artisan migrate                                           # ai_ledger
php artisan horizon                                           # the `ledger` queue is one of its supervisors (config/horizon.php)
php artisan ai:ledger-consume                                 # the stream to Postgres, in batches; also scheduled every minute with --once
php artisan ai:spend --by=tenant,feature --since=1h           # live spend by any label, breaker state, the app hour against its ceiling
php artisan ai:spend-breaker                                  # scheduled every minute: 15 minutes of spend against the normal for this hour
php artisan ai:kill --provider=frontier --reason="spend 40x baseline"      # and: ai:kill --reset --provider=frontier --reason="..."   ai:kill --list
php artisan ai:showback --send                                # Mondays: each team's week
php artisan ai:reconcile --usage=usage-2026-11-10.json        # nightly: the ledger against the provider's export, and the counters rebuilt from the ledger
docker compose --env-file .env -f ops/compose/base.yml -f ops/compose/app.yml --profile app up -d ledger-consume    # the consumer as a service
```

Budgets are in `config/ai.php` under `budgets` (dollars; plans, features, anonymous traffic, the app's hour) and are enforced from Redis counters on keys that share the hash tag `{ai-budget}`, with the two Lua scripts in `resources/redis/`. At 80% the owning team (and a tenant's admin) is mailed; at 100% the support assistant falls down its ladder: a cheaper role, then shorter answers, then a cached answer, then help-center articles and a link to a person. `AI_BUDGETS_ENABLED=false` removes the decorator from the chain; `AI_BUDGETS_STORE=array` keeps the counters in memory for a machine with no Redis. `ai:reconcile` needs a usage export (JSON rows of `date`, `provider`, `model`, `input_tokens`, `output_tokens`, `cost_usd`) or a `usage_url` on the provider; no hosted provider was called while building this chapter.

### From `ch37`: send each question to the cheapest role that can answer it

```bash
php artisan migrate                                                     # cascade columns, cited_chunks, ai_batches
php artisan ai:cost-breakdown --since=7d --as=illustrative-frontier     # Figure 37-1: where the money in one answer goes (--as prices local calls as a hosted role would)
php artisan eval:route-table --roles=local,mid,frontier --margin=1 --write           # measure each role on the golden set, write config/ai-routes.php (--from=rates.json reuses a run)
php artisan ai:cascade-report --days=7 --compare=previous-week          # where each category settles, why answers escalated, cost per answer and per conversation
php artisan ai:cache-tune                                               # hit rate and false-hit rate per threshold from tests/Fixtures/cache/pairs.jsonl
php artisan ai:cache-share                                              # cached share of input per feature against its floor (hourly with --notify)
php artisan ai:batch:submit judge-nightly --provider=local --from=replies:1d     # then ai:batch:poll --collect (every ten minutes) or ai:batch:collect judge-nightly
```

`config/ai-routes.php` ships as Figure 37-2's illustrative pass rates, written by `eval:route-table --from`: regenerate it from your own golden-set run once more than the `local` role is set up (a role that is not set up is skipped, and nothing leaves the machine because a route names it). With a table in place the support assistant tries each category's chain cheapest first and checks every answer with what it already trusts (citations after their one repair, the answer guard, truncation, a decline of a question its passages answer); an attempt that is not the last is held back and sent at once after it passes. `AI_CASCADE=false` turns it off. The batch commands need a provider with a batch API (`batch_driver => openai` on the provider, not run against a real one here) or run on your own hardware (`--provider=local`) a share of the requests at a time.

### From `ch38`: failure analysis before a fine-tune

```bash
php artisan eval:failure-modes --role=local --category=billing --since=30d      # runs the category's golden cases on one role (live: about two minutes a case on an 8B model) and sorts every failure
php artisan eval:failure-modes --from=storage/eval/last-failure-modes.json      # sorts a saved run again, without a model (every run saves one; --save= names the file)
php artisan eval:failure-modes --judge                                          # the judge (ai.tiers.small) places what the checks cannot; its verdict is marked judged and never closes a case
```

The buckets and where each goes (Figure 38-8): behavior and format is the only fine-tuning problem; a retrieval miss goes to retrieval tuning, a coverage gap to the help center, arithmetic to a tool (`calculate_proration`: a plan change prorated by days left, a late fee at 1.5% a month by day), and anything left to a person. Retrieval misses and coverage gaps are written to `storage/eval/backlog.jsonl` (git-ignored); `docs/backlog/billing-retrieval-and-coverage.md` is the committed copy of the first run. The decision record `docs/adr/fine-tune-local-billing.md` quotes that run: on this machine, with the reranker off, 9 of the 41 billing cases pass and half the failures are behavior, so the retrieval backlog comes before any training.

### From `ch39`: a first LoRA run, small and fast

```bash
cd training && uv sync
MLFLOW_TRACKING_URI=http://localhost:5050 uv run python scripts/first_lora.py          # 200 examples, 2 epochs, rank 16: about five minutes on an M1 Pro (MPS); a CUDA card was not available here
uv run python scripts/quick_eval.py --adapter runs/first-lora/adapter --cases ../tests/golden/billing_holdout.jsonl
uv run python data/mix.py --task data/fixtures/billing_sft_small.jsonl --replay <the base model's own answers> --share 0.15 --out data/mixed.jsonl
cd .. && php artisan dataset:fixture          # rewrites the fixture and the held-out cases from the seeded database (a test checks the committed files are exactly this output)
```

`MLFLOW_TRACKING_URI` is the server of Chapter 28 (`--profile mlflow`); without one the Hugging Face integration cannot log and the run fails, so use `--report-to none` to train without tracking. The run prints `trainable%: 1.7497` (8,798,208 of 502,830,976 parameters) and saves the adapter to `training/runs/first-lora/adapter` (git-ignored). The 200 rows are templated from the seeded invoices (a late fee explained, a balance, a payment confirmed), in the prompt-and-completion format, with the account data in the prompt; the 20 held-out cases are late-fee questions on invoices that appear in no training row, and they are part of the golden set, so `eval:gates` runs them too. On Apple Silicon `trl` 1.x breaks the GPU (its fused loss head returns zeros and Metal reports command-buffer errors), which is why `trl` is pinned below 1.0.

### From `ch40`: a reviewed, versioned training set

```bash
php artisan migrate                                                       # tenants.training_use (off), training_terms_version, data_residency; users.training_excluded; reply_edits
php artisan db:seed --class=TrainingCorpusSeeder                          # 240 invented billing conversations on top of the seeded app (not in the default seed): opted-in tenants, edits, thumbs, and the flaws the pipeline exists to find
# set AI_TRAINING_HASH_KEY in .env first: php artisan tinker --execute='echo bin2hex(random_bytes(32));'
php artisan dataset:export billing --since=2026-03-01 --out=storage/datasets/billing-v1 --patterns-only   # --patterns-only: names need the privacy profile (PRESIDIO_URL); without it the export refuses
php artisan dataset:scan billing-v1                                       # injected instructions, trigger phrases, unsourced links, concentration (before review)
php artisan dataset:review billing-v1 --triage=judge                      # the queue, doubtful first; the judge never labels
php artisan dataset:review billing-v1 --apply=storage/samples/billing-v1.review.json   # the Build's prepared review (written from the seeder: decisions keyed by conversation id)
cd training
uv run python data/clean.py --in ../storage/datasets/billing-v1 --cap-tenant=0.10 --cap-user=0.05 --cap-subcategory=0.75   # caps scaled to a corpus of 160 examples
uv run python data/split.py --in ../storage/datasets/billing-v1 --mix synthetic=0.15,replay=0.15 --val=0.08
cd .. && php artisan dataset:publish billing-v1 --region=eu               # training-eu disk (a local folder; set TRAINING_EU_BUCKET for S3); --hub-private=ledgerly/billing-synthetic needs HF_TOKEN
php artisan dataset:show billing-v1
php artisan dataset:forget billing-v1 --conversation=900001 --reason="erasure request"     # billing-v2 without that conversation's examples; billing-v1 scheduled for retirement
php artisan ai:template-check llama3.1:8b --tokenizer=NousResearch/Meta-Llama-3.1-8B-Instruct --rows=storage/datasets/billing-v1/train.jsonl   # --server=ollama|llama|vllm; the Llama repository itself is gated
```

The datasets live in `storage/datasets/` (git-ignored: they are customer-derived even when redacted) and the regional copy in `storage/app/training/<region>/`. Training data takes the fine-tuned system message (`SupportPrompt::fineTuned()`, version `2026-11-11.ft1`, never the canary), the passages and tool traffic of the turn, and the agent's edit where there is one; replacing a name needs Presidio (`--profile privacy`), the rest does not. Exemplars, the synthetic set and the replay set (the base model's own answers, written by `training/data/replay.py` through Ollama) are in `training/data/fixtures/`. `ai:template-check` found a real difference on the first try: with tool turns in the row, Ollama's `llama3.1:8b` template renders 557 tokens where the Hugging Face template renders 538, and without them the two agree (122 and 122).

### From `ch41`: a hosted fine-tune, and an offline one

The hosted path needs an account with a provider that lets you tune a model through an OpenAI-shaped API; the **offline path needs nothing** and exercises every line of the Laravel side.

```bash
php artisan migrate                                                       # fine_tune_jobs, fine_tune_job_events
# Offline (no account, no money): set FRONTIER_FT_DRIVER=fake, FT_FAKE_SECONDS=20, and for the comparison AI_EVAL_ENABLED=true, AI_FAKE_FT_CHAT=true, FRONTIER_URL=http://localhost:8000/fake-ft/v1, FRONTIER_KEY=fake, FRONTIER_MODEL=fake-base-8b
# Hosted: FRONTIER_FT_DRIVER=openai, FRONTIER_URL, FRONTIER_KEY, and BILLING_FT_BASE (a model the provider lets you tune: Appendix F.8)
php artisan ai:ft:prepare billing-v1 --provider=frontier --only=exemplars,synthetic,replay --out=storage/ft/billing-hosted.jsonl --validation
php artisan ai:ft:submit billing-v1 --provider=frontier --base="$BILLING_FT_BASE" --method=supervised --epochs=3
php artisan ai:ft:poll                                                    # scheduled every five minutes by `schedule:run`; shown here once
php artisan ai:ft:register 1 --role=frontier-billing-ft                   # writes BILLING_FT_* to .env; fill BILLING_FT_RATE_IN, _OUT and _TRAIN_RATE from the provider's page, then `config:clear`
php artisan eval:compare --set=golden:billing --roles=frontier,frontier-billing-ft,cascade --with=gates,judge,injection,tripwire:generation
php artisan adr:append docs/adr/fine-tune-local-billing.md --section="Hosted baseline" --from=eval:last
```

What may leave is decided before the format: `--only` defaults to the three parts of `billing-v1` with no customer data. Production conversations and agent edits need a recorded decision (`php artisan dataset:allow billing-v1 --provider=frontier --reason="..."`) **and** a provider whose privacy block says it does not train on API data and has a signed DPA. A second submission of the same dataset hash, base and settings is refused unless you pass `--again`. The role `frontier-billing-ft` is sent the short system message (`SupportPrompt::boundTo`) and `local` and every other role the long one. The OpenAI-shaped driver has been exercised with faked HTTP only; nothing here has called a hosted fine-tuning API.

### From `ch42`: train on a Mac (or an AMD APU), serve from Ollama

The 8B run needs a 32 GB Mac (16-bit LoRA is about 22 to 26 GB, Appendix E) or more, and the gated Llama 3.1 8B (accept the license on the Hub and log in). **The same commands run on a small ungated model in about a minute**, which is how this chapter was checked, and the steps after training do not change.

```bash
cd training
uv sync --extra mlx                                                  # macOS arm64: mlx and mlx-lm, pinned in uv.lock (uv sync --extra rocm on an AMD APU)
# The small run: SmolLM2-135M-Instruct (ungated), the 200 synthetic billing rows
uv run python data/to_mlx.py data/fixtures/billing_sft_small.jsonl data/billing-small/mlx
uv run python scripts/train_estimate.py --config configs/billing-mlx-smoke.yaml --steps 50     # sustained tokens/s, peak memory, run time
uv run python scripts/run_mlx.py configs/billing-mlx-smoke.yaml --experiment billing-mlx       # losses go to MLFLOW_TRACKING_URI
../ops/models/build-llama-cpp.sh                                                                  # llama.cpp at the tag training/llama-cpp.lock pins: the converter and llama-quantize (from ch44; ch42 cloned it unpinned)
uv run python scripts/export_to_ollama.py runs/billing-mlx-smoke/adapters --base HuggingFaceTB/SmolLM2-135M-Instruct --name ledgerly-billing-ft-tiny --quant none
cd .. && php artisan ai:template-check ledgerly-billing-ft-tiny --against=training --tokenizer=HuggingFaceTB/SmolLM2-135M-Instruct --rows=training/data/fixtures/billing_sft_small.jsonl
php artisan migrate && php artisan registry:add ledgerly-billing-ft-tiny --dataset=billing-v1 --run=mlflow:billing-mlx --status=candidate --export=training/models/ledgerly-billing-ft-tiny/export.json
# The full run, on a Mac with the Llama base and billing-v1:
#   uv run python data/to_mlx.py ../storage/datasets/billing-v1 data/billing-v1/mlx
#   uv run python scripts/train_estimate.py --config configs/billing-mlx.yaml --steps 50
#   uv run python scripts/run_mlx.py configs/billing-mlx.yaml          (set iters to rows × epochs ÷ batch)
#   uv run python scripts/export_to_ollama.py runs/billing-mlx/adapters --base meta-llama/Llama-3.1-8B-Instruct --quant Q4_K_M --name ledgerly-billing-ft
# On an AMD APU or an NVIDIA card: uv run python scripts/train_lora.py --config configs/billing-peft.yaml
# A CPU smoke run of that script: uv run python scripts/train_lora.py --config configs/billing-peft-smoke.yaml --device cpu --report-to none
```

`--quant none` serves the 16-bit GGUF; `Q4_K_M` needs `llama-quantize` from the llama.cpp checkout (built or from a package), which `ollama create` cannot replace: this Ollama (0.40) imports only pre-quantized GGUF files, and its own safetensors importer did not accept the Qwen2 or SmolLM2 files tried. `to_mlx.py --tool-turns=drop` leaves out the rows with tool turns, the alternative Chapter 40 gives for the training and serving templates that disagree on them. To use the fine-tune from the app, set `BILLING_FT_URL` (the Ollama server that has it) and route a role to `local-billing-ft`; Chapter 44 resolves the model name from the registry. Nothing in the repository ran the 8B base: its configs are written, and `train_estimate.py` is how you find out what your machine does with them.

### From `ch43`: NVIDIA, a free notebook, a rented GPU and a sweep

None of this needs a GPU to read or to test, and none of it was run on one: the pieces that can run on any machine did (the sweep generator, the session script with its `local` provider, the sweep report against faked MLflow, the CPU smoke run of `train_lora.py`).

```bash
cd training && uv sync --extra cuda                                      # Linux x86_64 with an NVIDIA GPU: PyTorch's CUDA build, bitsandbytes, Unsloth (pinned in uv.lock)
uv run python scripts/train_lora.py --config configs/billing-peft.yaml --unsloth     # the production run on an NVIDIA server in the data's region (add --load-in-4bit for QLoRA)
# A free notebook: open training/notebooks/billing_qlora_colab.ipynb in Colab, add a read-only HF_TOKEN secret, run all cells (non-customer data only)
uv run python scripts/sweep.py configs/axolotl/billing-qlora.yml --grid learning_rate=1e-4,2e-4,4e-4 lora_r=8,16 num_epochs=1,2 --seed 42 --repeat-center 2 --out configs/axolotl/sweep-billing/
cd .. && php artisan dataset:allows billing-synthetic@a41c09e --provider=my-gpu-host --region=eu     # the check the session script makes before it creates anything
ops/cloud/gpu-session.sh train --provider=my-gpu-host --region=eu --gpu=a100-80gb --interruptible --dataset=billing-synthetic@a41c09e \
    --config=training/configs/axolotl/sweep-billing/lr0.0002_r16_ep2.yml --max-hours=1 --idle-stop=10m --results=s3://my-bucket/runs/
php artisan eval:compare --roles=local-billing-ft --with=gates --log-to=<mlflow run id>             # score each sweep run's served adapter into its own MLflow run
php artisan eval:sweep-report mlflow:billing-sweep --manifest=training/configs/axolotl/sweep-billing/sweep.json --base-injection=0.14 --write-best=training/configs/
```

`ops/cloud/providers/template.sh` lists the ten functions a provider script must define (create a machine, an encrypted volume, short-lived credentials, a self-shutdown deadline, run the training, report GPU use, push results, destroy, stop); this repository has no provider's CLI in it, so you write yours. `providers/local.sh` is the provider that is this machine: `GPU_SESSION_LOCAL_ALLOW_UNENCRYPTED=1 GPU_SESSION_TRAIN_CMD='...' ops/cloud/gpu-session.sh train --provider=local ...` runs the whole flow (manifest check, stops, results, receipt in `storage/ft/sessions/`, two `training` rows in the ledger) with no machine and no money. A session needs `--max-hours` and `--results`; customer-derived data needs a recorded `dataset:allow` for that provider and region. Each run in a sweep needs the dataset on the machine; `axolotl` itself is installed on the rented machine (its install is the provider script's job), not in this project's lock.

### From `ch44`: quantize, serve, register, canary and roll back

The registry, the commands and the routes need nothing but PHP; the quantization and the Ollama path were run for real on the 135M model from Chapter 42; **the vLLM path was written from the book and never run** (no NVIDIA machine).

```bash
./ops/models/build-llama-cpp.sh                       # clones llama.cpp at the tag in training/llama-cpp.lock, checks the commit, builds llama-quantize and llama-imatrix (needs cmake)
cd training && uv run python scripts/export_to_ollama.py runs/billing-mlx/adapters --base meta-llama/Llama-3.1-8B-Instruct --name ledgerly-billing-ft-v1 --quant Q4_K_M && cd ..
php artisan registry:add ledgerly-billing-ft-v1 --dataset=billing-v1 --export=training/models/ledgerly-billing-ft-v1/export.json --base-revision=<commit> --run=mlflow:billing-mlx
php artisan eval:quant-ladder ledgerly-billing-ft-v1 --base-f16=training/models/base/base.f16.gguf --pick-smallest-passing --record=billing:1   # f16, q8_0, q5_k_m, q4_k_m with and without an importance matrix, q3_k_m
php artisan registry:keygen ~/keys && php artisan registry:sign billing 1 && php artisan registry:verify billing 1   # REGISTRY_SIGNING_KEY_FILE on the pipeline, REGISTRY_SIGNING_PUBLIC_KEY on every server
php artisan serve:fine-tune billing 1 --ollama=local                            # servers are the names in config ai.registry.servers; --vllm=<name> --adapter-name=billing-v1 for the adapter
php artisan ai:template-check local-billing-ft --server=ollama --tokenizer=<base repo> --rows=<train.jsonl>
php artisan registry:stage billing 1 --to=shadow && php artisan registry:stage billing 1 --to=canary --percent=10   # an experiment, `registry-billing`, on the live scorecard
php artisan registry:promote billing 1                 # refused unless the stored comparison meets docs/adr/fine-tune-local-billing.md's criteria; --force records that it was skipped
php artisan registry:rollback billing --reason="drill" --time   # the alias moves back; --time sends one request to the role and says how long it took
php artisan registry:history billing
```

Set `BILLING_FT_URL` (and `BILLING_FT_REGION` for a residency tenant) so the `local-billing-ft` role exists; its model name is the registry alias `billing@production`, which the registry writes to `storage/app/registry/aliases.json` and `config/ai.php` reads, so a promotion or a rollback is a registry change plus a config refresh (`registry:sync` on each host after one; with `config:cache` on, the command rebuilds the cache and restarts the queue workers). A tenant with `data_residency` set is served by the `residency` route in `config/ai-routes.php` (`local-billing-ft`, then `mid-in-region`, then a person), and any role whose recorded region is not the tenant's is dropped from every route. `MID_IN_REGION_URL`, `MID_IN_REGION_MODEL` and `MID_IN_REGION_LICENSE` name that larger in-region model. The Compose files still publish each model server on `127.0.0.1` (BOOK-ISSUES 3); a test fails if any published port is open to other machines.

### From `ch45`: a small GPT from nothing

```bash
cd training/tiny
uv run python prepare_corpus.py --gutenberg-list books.txt --help-center ../../resources/help --synthetic data/synthetic/support-dialogue.jsonl --out corpus/ --val-fraction 0.02   # downloads the books once, politely, into ~/.cache/ledgerly-tiny
uv run python train_tokenizer.py --corpus corpus/ --vocab 8000 && uv run python model.py --count                                                                          # about 13.8M parameters
MLFLOW_TRACKING_URI=http://localhost:5050 uv run python train.py --steps 6000 --batch 64 --ctx 256        # step 0 prints a loss near ln(8000) = 8.99; resume with --resume auto
uv run python sample.py ckpt/step-6000.pt --prompt "Your invoice" --temperature 0.8
uv run python sft.py ckpt/step-6000.pt --pairs data/qa_pairs.jsonl --steps 300 --lr 1e-4 && uv run python sample.py ckpt/sft-300.pt --chat "Why was a late fee added to my invoice?"
```

The model is for understanding: it is never exported, registered or served. A full run takes about 80 minutes on an M1 Pro (MPS) and 4 GB of checkpoints; `uv run pytest` tests every piece in seconds on a CPU. The corpus, tokenizer and checkpoints are git-ignored; `books.txt` lists the Project Gutenberg ids used.

## The app as Volume 1 left it

What follows is reference for the app that `vol2-start` contains: how each piece runs, in the order Volume 1 introduced it. Chapter 0 of this volume walks through the same ground once.

### Chapter 18: the queue and the real-time server

Since Chapter 18 the assistant answers on a queue and streams over WebSockets, so two more processes run next to `php artisan serve`. There is no Node, no `npm install` and no build step: the page scripts are plain files in `public/js`.

```bash
php artisan horizon           # the queue workers (Redis); the dashboard is at /horizon
php artisan reverb:start      # the WebSocket server on port 8080
```

If port 8080 is taken, change `REVERB_PORT` and `REVERB_SERVER_PORT` in `.env` and restart both. The chat is at `/support` (plain JavaScript) and `/support/chat` (Livewire). Set `AI_CONCURRENCY` to how many model calls your server can run at once. Horizon needs PHP's `pcntl` and `posix` extensions, so on Windows use WSL. Without Reverb the page falls back to polling the stored reply; without Horizon nothing answers.

### Chapter 19: two providers, and comparing them

`php artisan ai:providers` lists the named providers. `local` is your Ollama; `frontier` is a second, hosted, OpenAI-compatible endpoint set by `FRONTIER_URL`, `FRONTIER_KEY` and `FRONTIER_MODEL` in `.env`. It is unset by default, and while it is unset nothing is ever sent to a hosted model. Since Chapter 20, `php artisan ai:compare` runs `storage/golden/support.jsonl` against the providers you name and prints quality, tokens, latency and cost; the prices in `.env.example` are examples, so copy your own.

### Chapter 21: sizing and profiling a big model

`php artisan ai:doctor --size --model=llama3.1:70b-instruct-q4_K_M --ctx=8192 --slots=2` does the sum of Chapter 21 (weights plus KV cache plus headroom) against the memory your machine can give a model, and says whether it fits. `php artisan ai:profile --provider=mid` runs `storage/samples/profile-set.json` against a provider while sampling memory every second. The `mid` tier is unset until you build `ops/ollama/Modelfile.ledgerly-support-70b` and set `LOCAL_70B_MODEL`; `ops/hardware/` has the Mac and Linux APU notes. Neither command needs a 70B to run: they work on the 8B.

### Chapter 22: Compose in `ops/compose/`, Ollama in the stack, and `ai:doctor`

The Compose files moved to `ops/compose/` (Appendix L). `docker compose up -d` at the root still works: `compose.yaml` includes them, so Chapter 0's command starts Postgres and Redis as before. The pieces compose with `-f`; add `--env-file .env` so Compose reads your ports:

```bash
# Linux, Ollama in Docker (CPU; add -f ops/compose/ollama.gpu.yml for an NVIDIA GPU). ollama-init builds ledgerly-support and pulls nomic-embed-text.
docker compose --env-file .env -f ops/compose/base.yml -f ops/compose/ollama.yml --profile ollama up -d
# Mac developer: native Ollama for Metal, no inference profile
docker compose --env-file .env -f ops/compose/base.yml -f ops/compose/macos.override.yml up -d
# or, from the root: docker compose --profile ollama up -d
```

`cp ops/compose/.env.example .env` and `cp .env.example .env` give the same file. Every published port is bound to `127.0.0.1`; Ollama has no authentication, so keep it that way. Without Docker, build the model once with `ollama create ledgerly-support -f ops/ollama/Modelfile.ledgerly-support`.

`php artisan ai:doctor` checks the server the app depends on (models present, `num_ctx` against the budget window, where the layers went, slots against `AI_CONCURRENCY`, keep-alive, exposure, round trips) and exits non-zero on the context-window check, so it can gate CI. `php artisan ai:doctor --health` is the one-line reachable, loaded, warm probe.

### Chapter 23: llama-server as a second local provider

```bash
./ops/llama/pull.sh                   # the pinned GGUF into ./models (4.9 GB); `draft` and `cpu` fetch the other two; every file is checked against a SHA-256
openssl rand -hex 24                  # put the result in .env as LLAMA_API_KEY: Compose will not start llama-server without one
docker compose --env-file .env -f ops/compose/base.yml -f ops/compose/llama.yml --profile llama up -d --build   # CPU image; LLAMA_VARIANT=cuda and -f ops/compose/llama.gpu.yml for an NVIDIA GPU
php artisan ai:doctor --provider=local-llama
php artisan ai:compare --providers=local,local-llama
```

`local-llama` is a second provider behind the same driver (`LLAMA_URL`, `LLAMA_API_KEY`). Set `AI_LOCAL_PROVIDER=llama` and the `local` role follows it; Ollama stays the default for development. With `AI_TOKEN_COUNTER=server` the token budget is counted by the server's own `/tokenize`, and on llama-server the ticket classifier is grammar-constrained (it cannot answer with a label that does not exist). The server is published on `127.0.0.1` only and refuses requests without the key.

### Chapter 24: vLLM for GPU production, and a load test

vLLM needs an NVIDIA GPU. Without one, Appendix L.7 rents one for an hour: on the rented Ubuntu 24.04 instance, run `./ops/cloud/gpu-session.sh` (it checks the driver, installs `nvidia-container-toolkit`, writes `.env`, starts the `vllm` profile and prints the `ai:doctor` result); `./ops/cloud/gpu-session.sh --down` stops it. The instance bills until you stop it in the provider's console.

```bash
openssl rand -hex 24                  # put the result in .env as VLLM_API_KEY: Compose will not start vLLM without one
export HF_TOKEN=...                   # only for a gated model; read from your shell, never written to a file
docker compose --env-file .env -f ops/compose/base.yml -f ops/compose/vllm.yml --profile vllm up -d
php artisan ai:doctor --provider=vllm
php artisan ai:load --provider=vllm --concurrency=1,4,8,16,32 --duration=90   # the knee: set AI_CONCURRENCY just below it
php artisan conversation:replay storage/samples/long-conversation.json --users=16   # needs Horizon and QUEUE_CONNECTION=redis
```

The image (`vllm/vllm-openai`), the model repository and the commit of that repository are pinned in `.env.example`, not `latest`, so a new vLLM release or a moved model branch cannot change your answers under you. Change them on purpose and run `ai:compare` and `ai:load` before and after. `AI_LOCAL_PROVIDER=vllm` makes the `local` role follow it. vLLM is published on `127.0.0.1:8001` only (`php artisan serve` has 8000), and `/health` and `/metrics` are not behind the key, so never publish it wider.

### Chapter 25: the operational layer, monitoring and Triton

Whichever server the `local` role points at (`AI_LOCAL_PROVIDER=ollama`, `llama`, `vllm` or `triton`), the same four things apply:

```bash
php artisan ai:doctor --profile=vllm    # check a Compose profile against this machine (GPU, container toolkit, keys, ports) before you start it
php artisan ai:doctor --health          # reachable, loaded, warm, and the circuit breaker, in one line
php artisan ai:warm                     # send the system message once, so the first customer does not pay the cold start
php artisan ai:stats                    # now also: the breaker, and where the window's requests were answered
```

Three consecutive "the server is not there" errors open a circuit breaker for 60 seconds. While it is open the person gets the cached answer to a how-to question if there is one, and otherwise an honest "briefly unavailable" notice, and no Horizon job fails. `AI_FALLBACK_LOCAL` is empty by default; naming `frontier` there sends `support.reply` to the hosted provider during an outage, which means customer text leaves your network, so it is a decision, not a default.

```bash
# Prometheus and Grafana for whichever server is running. Set GRAFANA_ADMIN_PASSWORD (and OPS_METRICS_TOKEN for the breaker panel) in .env first.
docker compose --env-file .env -f ops/compose/base.yml -f ops/compose/vllm.yml -f ops/compose/monitoring.yml --profile vllm --profile monitoring up -d
# Everything in containers: the app, Reverb, Horizon, and an ai-warm step that must succeed before Horizon starts
docker compose --env-file .env -f ops/compose/base.yml -f ops/compose/vllm.yml -f ops/compose/app.yml --profile vllm --profile app up -d --build
# Triton (NVIDIA GPU): the ONNX models first, then a key for the proxy that is the only door to it
python3 ops/triton/export_onnx.py
docker compose --env-file .env -f ops/compose/base.yml -f ops/compose/triton.yml --profile triton up -d
```

Grafana is the one monitoring port that is published, on `127.0.0.1`, behind its login; Prometheus publishes nothing. Triton has no authentication of its own, so `ops/compose/triton.yml` puts an nginx proxy in front that checks `TRITON_API_KEY` and refuses model control. `ops/k8s/` has values and a small chart for vLLM and Triton on Kubernetes. Every image tag is pinned in `.env.example`.

## Fixes and changes

See [CHANGELOG.md](CHANGELOG.md). If you find a problem in the code, open an issue on this repository. Errata for the book itself are listed on kavuncuoglu.com.

## License

For personal and educational use, including adapting the code for your own projects. It is not licensed for republishing or resale. See [LICENSE](LICENSE).
