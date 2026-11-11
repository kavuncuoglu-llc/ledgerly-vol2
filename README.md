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

## Set up `vol2-start`

1. `cp ops/compose/.env.example .env && composer install && php artisan key:generate`. Set `AI_LOCAL_PROVIDER` (`ollama`, `llama`, `vllm` or `triton`). Add `FRONTIER_*` only if you have a key, and read what it means first: customer text can leave your network.
2. `docker compose --env-file .env -f ops/compose/base.yml -f ops/compose/<profile>.yml --profile <profile> up -d` with `<profile>` one of `ollama`, `llama`, `vllm`, `triton`; a Mac developer runs `-f ops/compose/macos.override.yml` with native Ollama instead. Postgres and Redis start with any of them. `llama`, `vllm` and `triton` need `LLAMA_API_KEY`, `VLLM_API_KEY` or `TRITON_API_KEY` in `.env`; Compose will not start them without one.
3. `php artisan migrate --seed && php artisan help:ingest && php artisan ai:doctor`
4. `php artisan serve`, `php artisan horizon` and `php artisan reverb:start` in three terminals, then open <http://127.0.0.1:8000/support> and sign in as `maya.okafor@acme-studios.example.com` with the password `password` (every seeded account uses it). Or run the app in containers too: `-f ops/compose/app.yml --profile app` (see "Chapter 25" below).

You need PHP 8.3 or later with the `gd`, `zip`, `pcntl`, `posix` and `pdo_pgsql` extensions, Composer, Docker and Git. Host ports, all on `127.0.0.1`: app 8000, Reverb 8080, Postgres 5432, Redis 6379, Ollama 11434, llama-server 8081, vLLM 8001, Triton 9000, Grafana 3000; every one has a variable in `.env` (`DB_PORT`, `REVERB_PORT`, `LLAMA_PORT`, ...) if it is taken on your machine. The tests need no model server: `vendor/bin/pest`.

If a host port is already taken on your machine, set its variable in `.env` before you start the containers: Compose and Laravel read the same values.

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
