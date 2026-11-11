# Ledgerly: Volume 2 companion code

The companion app for *Large Language Models for Laravel Developers, Volume 2 of 3: Running, Training, and Securing Large Language Models*. It is Ledgerly, a fictional invoicing product with a support assistant, exactly as Volume 1 leaves it. This volume adds evaluation, tracing, security, cost controls and fine-tuned models to it, one chapter at a time.

This repository is public and free. You do not need an account, a claim code or proof of purchase to clone it.

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
