import json
import logging
import os
import socket
import time

import redis
from pydantic import ValidationError

from contracts.eval_conversations_v1 import EvalConversations
from contracts.eval_sample_v1 import EvalSample
from contracts.eval_scores_v1 import EvalScores
from workers.embed_worker import (
    ATTEMPTS,
    DEAD,
    GROUP,
    MAX_ATTEMPTS,
    RECLAIM_EVERY_SECONDS,
    RESULTS,
    TASK_TIMEOUT_MS,
    beat,
    connect,
)

STREAM = "py:eval-sample"
BLOCK_MS = 5000

log = logging.getLogger("ledgerly.eval-worker")


def judge_name() -> str:
    return os.environ.get("JUDGE_MODEL", "llama3.1:8b")


def parse_task(raw: str) -> EvalSample | EvalConversations:
    schema = json.loads(raw).get("schema")  # the schema field says which contract to validate against

    if schema == "eval-sample.v1":
        return EvalSample.model_validate_json(raw, strict=True)

    if schema == "eval-conversations.v1":
        return EvalConversations.model_validate_json(raw, strict=True)

    raise ValueError(f"unknown task schema {schema!r}")


def run_task(task: EvalSample | EvalConversations) -> list[dict]:
    if isinstance(task, EvalSample):
        from evals import nightly_rag

        return nightly_rag.score(
            [
                {
                    "id": i.id,
                    "question": i.question,
                    "answer": i.answer,
                    "contexts": [c.root for c in i.contexts],
                    "reference": i.reference,
                }
                for i in task.items
            ]
        )

    from evals import nightly_conversations

    return nightly_conversations.score(
        [{"id": i.id, "turns": [{"role": t.role.value, "content": t.content} for t in i.turns]} for i in task.items]
    )


def process(r: redis.Redis, entry_id: str, fields: dict, scorer=run_task) -> bool:
    try:
        task = parse_task(fields["task"])  # the contract
    except (ValidationError, ValueError, KeyError) as error:
        log.warning("entry %s failed: %s", entry_id, error)
        return False

    start = time.perf_counter()

    try:
        items = scorer(task)  # the actual work: a judge model, so it can fail for reasons that are not the task's fault
    except Exception as error:  # noqa: BLE001 - any judge failure leaves the entry pending for another attempt
        log.warning("entry %s: the judge failed: %s", entry_id, error)
        return False

    if not items:
        log.warning("entry %s: the judge scored nothing", entry_id)
        return False

    result = EvalScores(
        schema="eval-scores.v1",
        task_id=task.task_id,
        request_id=task.request_id,
        judge=judge_name(),
        items=items,
        elapsed_ms=int((time.perf_counter() - start) * 1000),
    )

    r.xadd(RESULTS, {"task": result.model_dump_json(by_alias=True)})
    r.xack(STREAM, GROUP, entry_id)  # only after the result is safe
    r.hdel(ATTEMPTS, entry_id)
    log.info("entry %s done: task %s, %d of %d items scored", entry_id, task.task_id, len(items), len(task.items))

    return True


def ensure_group(r: redis.Redis) -> None:
    try:
        r.xgroup_create(STREAM, GROUP, id="0", mkstream=True)
    except redis.ResponseError:
        pass  # the group already exists


def read_new(r: redis.Redis, consumer: str, count: int = 2, block_ms: int = BLOCK_MS, scorer=run_task) -> int:
    done = 0

    for _stream, entries in r.xreadgroup(GROUP, consumer, {STREAM: ">"}, count=count, block=block_ms) or []:
        for entry_id, fields in entries:
            if process(r, entry_id, fields, scorer):
                done += 1

    return done


def reclaim(r: redis.Redis, consumer: str, min_idle_ms: int = TASK_TIMEOUT_MS, count: int = 4, scorer=run_task) -> int:
    claimed = r.xautoclaim(STREAM, GROUP, consumer, min_idle_time=min_idle_ms, start_id="0-0", count=count)
    handled = 0

    for entry_id, fields in claimed[1]:
        if not fields:
            continue

        attempts = r.hincrby(ATTEMPTS, entry_id, 1)

        if attempts > MAX_ATTEMPTS:
            r.xadd(
                DEAD, {"task": fields.get("task", ""), "error": f"exceeded {MAX_ATTEMPTS} attempts", "stream": STREAM}
            )
            r.xack(STREAM, GROUP, entry_id)
            r.hdel(ATTEMPTS, entry_id)
            log.error("entry %s moved to %s", entry_id, DEAD)
        else:
            process(r, entry_id, fields, scorer)

        handled += 1

    return handled


def run() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s %(message)s")
    r = connect()
    ensure_group(r)
    consumer = os.environ.get("HOSTNAME", socket.gethostname())
    last_reclaim = 0.0
    log.info("worker %s scoring %s on %s with judge %s", consumer, STREAM, STREAM, judge_name())

    while True:
        beat()
        read_new(r, consumer)

        if time.monotonic() - last_reclaim >= RECLAIM_EVERY_SECONDS:
            reclaim(r, consumer)
            last_reclaim = time.monotonic()


if __name__ == "__main__":
    run()
