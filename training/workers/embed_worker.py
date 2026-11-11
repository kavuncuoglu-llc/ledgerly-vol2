import logging
import os
import socket
import time
from pathlib import Path

import redis
from pydantic import ValidationError

from contracts.embed_batch_v1 import EmbedBatch
from contracts.embed_result_v1 import EmbedResult

STREAM = "py:tasks:embed"
GROUP = "py-workers"
RESULTS = "py:results"
DEAD = "py:dead"
ATTEMPTS = "py:attempts"
MAX_ATTEMPTS = 3

REPO = "nomic-ai/nomic-embed-text-v1.5"
TASK_TIMEOUT_MS = int(os.environ.get("TASK_TIMEOUT_MS", "120000"))
RECLAIM_EVERY_SECONDS = int(os.environ.get("RECLAIM_EVERY_SECONDS", "30"))
HEARTBEAT = Path(os.environ.get("WORKER_HEARTBEAT", "/tmp/py-worker.heartbeat"))

log = logging.getLogger("ledgerly.py-worker")

_model = None


def get_model():
    global _model

    if _model is None:
        from sentence_transformers import SentenceTransformer

        code_revision = os.environ[
            "NOMIC_CODE_REVISION"
        ]  # the model's code lives in a second repo, nomic-ai/nomic-bert-2048

        _model = SentenceTransformer(
            REPO,
            trust_remote_code=True,
            revision=os.environ["NOMIC_REVISION"],
            model_kwargs={"code_revision": code_revision},
            config_kwargs={"code_revision": code_revision},
        )  # both pinned: it runs repo code

    return _model


def parse_task(raw: str) -> EmbedBatch:
    return EmbedBatch.model_validate_json(raw, strict=True)  # strict: "42" is not an integer id, as in opis/json-schema


def embed(task: EmbedBatch) -> EmbedResult:
    if task.model != REPO:
        raise ValueError(f"worker serves {REPO}, task asked for {task.model}")  # refuse, don't guess

    start = time.perf_counter()
    vectors = get_model().encode(
        [f"search_document: {item.text}" for item in task.items],
        batch_size=32,
        normalize_embeddings=True,
    )

    return EmbedResult(
        schema="embed-result.v1",
        task_id=task.task_id,
        request_id=task.request_id,
        model=REPO,
        items=[{"id": item.id, "vector": v.tolist()} for item, v in zip(task.items, vectors, strict=True)],
        elapsed_ms=int((time.perf_counter() - start) * 1000),
    )


BLOCK_MS = 5000
SOCKET_TIMEOUT_SECONDS = (
    BLOCK_MS / 1000 + 25
)  # longer than any blocking read, or the client gives up before Redis answers


def connect() -> redis.Redis:
    return redis.Redis.from_url(os.environ["REDIS_URL"], decode_responses=True, socket_timeout=SOCKET_TIMEOUT_SECONDS)


def ensure_group(r: redis.Redis) -> None:
    try:
        r.xgroup_create(STREAM, GROUP, id="0", mkstream=True)
    except redis.ResponseError:
        pass  # the group already exists


def process(r: redis.Redis, entry_id: str, fields: dict) -> bool:
    try:
        task = parse_task(fields["task"])  # the contract
        result = embed(task)  # the actual work
    except (ValidationError, ValueError, KeyError) as error:
        log.warning("entry %s failed: %s", entry_id, error)
        return False

    r.xadd(RESULTS, {"task": result.model_dump_json(by_alias=True)})
    r.xack(STREAM, GROUP, entry_id)  # only after the result is safe
    r.hdel(ATTEMPTS, entry_id)
    log.info("entry %s done: task %s, %d items, request %s", entry_id, task.task_id, len(task.items), task.request_id)

    return True


def read_new(r: redis.Redis, consumer: str, count: int = 8, block_ms: int = BLOCK_MS) -> int:
    done = 0

    for _stream, entries in r.xreadgroup(GROUP, consumer, {STREAM: ">"}, count=count, block=block_ms) or []:
        for entry_id, fields in entries:
            if process(r, entry_id, fields):
                done += 1

    return done


def dead_letter(r: redis.Redis, entry_id: str, fields: dict, error: str) -> None:
    r.xadd(DEAD, {"task": fields.get("task", ""), "error": error, "stream": STREAM})
    r.xack(STREAM, GROUP, entry_id)
    r.hdel(ATTEMPTS, entry_id)
    log.error("entry %s moved to %s: %s", entry_id, DEAD, error)


def reclaim(r: redis.Redis, consumer: str, min_idle_ms: int = TASK_TIMEOUT_MS, count: int = 16) -> int:
    claimed = r.xautoclaim(STREAM, GROUP, consumer, min_idle_time=min_idle_ms, start_id="0-0", count=count)
    handled = 0

    for entry_id, fields in claimed[1]:
        if not fields:
            continue  # the entry was trimmed from the stream

        attempts = r.hincrby(ATTEMPTS, entry_id, 1)

        if attempts > MAX_ATTEMPTS:
            dead_letter(r, entry_id, fields, f"exceeded {MAX_ATTEMPTS} attempts")
        else:
            process(r, entry_id, fields)  # the same path as a fresh entry

        handled += 1

    return handled


def beat() -> None:
    HEARTBEAT.touch()


def run() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s %(message)s")
    r = connect()
    ensure_group(r)
    consumer = os.environ.get("HOSTNAME", socket.gethostname())
    last_reclaim = 0.0
    log.info("worker %s serving %s on %s", consumer, REPO, STREAM)

    while True:
        beat()
        read_new(r, consumer)

        if time.monotonic() - last_reclaim >= RECLAIM_EVERY_SECONDS:
            reclaim(r, consumer)
            last_reclaim = time.monotonic()


if __name__ == "__main__":
    run()
