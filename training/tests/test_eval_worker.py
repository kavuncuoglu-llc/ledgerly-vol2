import json

import fakeredis
import pytest

from contracts.eval_scores_v1 import EvalScores
from workers import eval_worker as worker

TASK_ID = "0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11"
CONSUMER = "worker-test"


def sample_json(**overrides) -> str:
    body = {
        "schema": "eval-sample.v1",
        "task_id": TASK_ID,
        "request_id": "req-1",
        "judge": "llama3.1:8b",
        "items": [
            {
                "id": "message:41",
                "question": "How is the late fee calculated?",
                "answer": "1.5% a month.",
                "contexts": ["The fee is 1.5% of the balance per month."],
                "reference": None,
            }
        ],
    } | overrides

    return json.dumps(body)


def conversations_json() -> str:
    return json.dumps(
        {
            "schema": "eval-conversations.v1",
            "task_id": TASK_ID,
            "request_id": None,
            "judge": "llama3.1:8b",
            "items": [
                {
                    "id": "conversation:5",
                    "turns": [{"role": "user", "content": "Hi"}, {"role": "assistant", "content": "Hello."}],
                }
            ],
        }
    )


@pytest.fixture
def r():
    client = fakeredis.FakeRedis(decode_responses=True)
    worker.ensure_group(client)

    return client


def queue(r, raw: str) -> str:
    return r.xadd(worker.STREAM, {"task": raw})


def scores(task):
    return [{"id": item.id, "metrics": {"faithfulness": 0.8, "response_relevancy": 0.9}} for item in task.items]


def test_a_sample_comes_back_as_scores_and_is_acknowledged(r):
    queue(r, sample_json())

    assert worker.read_new(r, CONSUMER, block_ms=10, scorer=scores) == 1

    (entry,) = r.xrange(worker.RESULTS)
    result = EvalScores.model_validate_json(entry[1]["task"], strict=True)

    assert str(result.task_id) == TASK_ID
    assert result.request_id == "req-1"
    assert result.items[0].metrics == {"faithfulness": 0.8, "response_relevancy": 0.9}
    assert r.xpending(worker.STREAM, worker.GROUP)["pending"] == 0


def test_a_conversation_task_goes_to_the_conversation_scorer(r):
    queue(r, conversations_json())
    seen = []

    def scorer(task):
        seen.append(type(task).__name__)

        return [{"id": task.items[0].id, "metrics": {"conversation_completeness": 1.0}}]

    assert worker.read_new(r, CONSUMER, block_ms=10, scorer=scorer) == 1
    assert seen == ["EvalConversations"]


def test_an_unknown_schema_or_a_broken_task_is_left_pending_not_acknowledged(r):
    queue(r, json.dumps({"schema": "eval-whatever.v9"}))
    queue(r, sample_json(items=[]))

    assert worker.read_new(r, CONSUMER, block_ms=10, scorer=scores) == 0
    assert r.xpending(worker.STREAM, worker.GROUP)["pending"] == 2
    assert r.xlen(worker.RESULTS) == 0


def test_a_judge_that_fails_leaves_the_entry_for_another_attempt(r):
    queue(r, sample_json())

    def broken(task):
        raise ConnectionError("the judge is down")

    assert worker.read_new(r, CONSUMER, block_ms=10, scorer=broken) == 0
    assert r.xpending(worker.STREAM, worker.GROUP)["pending"] == 1
    assert r.xlen(worker.RESULTS) == 0


def test_a_judge_that_scores_nothing_is_a_failure_too(r):
    queue(r, sample_json())

    assert worker.read_new(r, CONSUMER, block_ms=10, scorer=lambda task: []) == 0
    assert r.xlen(worker.RESULTS) == 0


def test_a_pending_entry_is_reclaimed_and_finished(r):
    entry = queue(r, sample_json())
    r.xreadgroup(worker.GROUP, "dead-worker", {worker.STREAM: ">"}, count=1)

    assert worker.reclaim(r, CONSUMER, min_idle_ms=0, scorer=scores) == 1
    assert r.xlen(worker.RESULTS) == 1
    assert r.xpending(worker.STREAM, worker.GROUP)["pending"] == 0
    assert entry


def test_after_three_failed_attempts_the_task_is_dead_lettered(r):
    queue(r, sample_json())
    r.xreadgroup(worker.GROUP, "dead-worker", {worker.STREAM: ">"}, count=1)

    def broken(task):
        raise ConnectionError("down")

    for _ in range(worker.MAX_ATTEMPTS + 1):
        worker.reclaim(r, CONSUMER, min_idle_ms=0, scorer=broken)

    (dead,) = r.xrange(worker.DEAD)

    assert dead[1]["stream"] == worker.STREAM
    assert "attempts" in dead[1]["error"]
    assert r.xpending(worker.STREAM, worker.GROUP)["pending"] == 0


def test_run_task_hands_plain_strings_to_the_scorers(monkeypatch):
    from evals import nightly_conversations, nightly_rag

    seen = {}
    monkeypatch.setattr(nightly_rag, "score", lambda rows: seen.setdefault("rag", rows) and [])
    monkeypatch.setattr(nightly_conversations, "score", lambda rows: seen.setdefault("conv", rows) and [])

    worker.run_task(worker.parse_task(sample_json()))
    worker.run_task(worker.parse_task(conversations_json()))

    assert seen["rag"][0]["contexts"] == ["The fee is 1.5% of the balance per month."]
    assert seen["rag"][0]["reference"] is None
    assert seen["conv"][0]["turns"][0] == {"role": "user", "content": "Hi"}
