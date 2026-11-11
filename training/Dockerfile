# The Python image (Chapter 27, Topic 6): a slim base, uv, and training/'s lockfile. Separate from the app image on purpose; the worker runs `python -m workers.embed_worker`.
# Plain `uv sync` installs PyPI's torch: the CPU build on arm64, a CUDA-bundled (large) one on x86_64. For a GPU host build with --build-arg UV_SYNC_ARGS="--extra cuda".
#   docker build -t ledgerly-training:vol2 training
FROM python:3.12.13-slim-bookworm

RUN pip install --no-cache-dir uv==0.11.6

ENV PYTHONUNBUFFERED=1 \
    UV_COMPILE_BYTECODE=1 \
    UV_LINK_MODE=copy \
    UV_PYTHON_DOWNLOADS=never \
    UV_NO_SYNC=1 \
    HF_HOME=/home/worker/.cache/huggingface

RUN useradd --create-home --uid 10001 worker

WORKDIR /app

ARG UV_SYNC_ARGS=""

COPY pyproject.toml uv.lock .python-version ./

RUN uv sync --locked --no-dev ${UV_SYNC_ARGS}

COPY contracts ./contracts
COPY workers ./workers
COPY evals ./evals
COPY scripts ./scripts

RUN mkdir -p /home/worker/.cache/huggingface && chown -R worker:worker /home/worker /app

USER worker

CMD ["uv", "run", "python", "-m", "workers.embed_worker"]
