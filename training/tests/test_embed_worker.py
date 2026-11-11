import json

import fakeredis
import numpy as np
import pytest

from workers import embed_worker as worker

TASK_ID = "0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11"
CONSUMER = "worker-test"


class FakeModel:
    def __init__(self):
        self.calls = []

    def encode(self, texts, batch_size, normalize_embeddings):
        self.calls.append({"texts": texts, "batch_size": batch_size, "normalize": normalize_embeddings})

        return np.array([[1.0, 0.0, 0.0]] * len(texts))


def task_json(**overrides) -> str:
    body = {
        "schema": "embed-batch.v1",
        "task_id": TASK_ID,
        "request_id": "req-1",
        "model": worker.REPO,
        "items": [{"id": 7, "text": "Late fees"}, {"id": 9, "text": "Recurring invoices"}],
    } | overrides

    return json.dumps(body)


@pytest.fixture
def r():
    client = fakeredis.FakeRedis(decode_responses=True)
    worker.ensure_group(client)

    return client


@pytest.fixture
def model(monkeypatch):
    fake = FakeModel()
    monkeypatch.setattr(worker, "_model", fake)

    return fake


def queue(r, raw: str) -> str:
    return r.xadd(worker.STREAM, {"task": raw})


def test_a_task_comes_back_as_a_result_and_is_acknowledged(r, model):
    entry = queue(r, task_json())

    done = worker.read_new(r, CONSUMER, block_ms=10)

    assert done == 1
    assert r.xpending(worker.STREAM, worker.GROUP)["pending"] == 0
    [(_, fields)] = r.xrange(worker.RESULTS)
    result = json.loads(fields["task"])
    assert result["schema"] == "embed-result.v1"
    assert result["task_id"] == TASK_ID
    assert result["request_id"] == "req-1"
    assert [item["id"] for item in result["items"]] == [7, 9]
    assert result["items"][0]["vector"] == [1.0, 0.0, 0.0]
    assert entry


def test_documents_are_embedded_with_the_nomic_prefix_in_one_batch(r, model):
    queue(r, task_json())

    worker.read_new(r, CONSUMER, block_ms=10)

    assert model.calls == [
        {
            "texts": ["search_document: Late fees", "search_document: Recurring invoices"],
            "batch_size": 32,
            "normalize": True,
        }
    ]


def test_a_task_for_another_model_is_refused_and_stays_pending(r, model):
    queue(r, task_json(model="some/other-model"))

    done = worker.read_new(r, CONSUMER, block_ms=10)

    assert done == 0
    assert r.xlen(worker.RESULTS) == 0
    assert r.xpending(worker.STREAM, worker.GROUP)["pending"] == 1
    assert model.calls == []


def test_a_message_that_breaks_the_contract_is_not_embedded(r, model):
    queue(r, task_json(items=[{"id": "42", "text": "Late fees"}]))

    done = worker.read_new(r, CONSUMER, block_ms=10)

    assert done == 0
    assert model.calls == []
    assert r.xpending(worker.STREAM, worker.GROUP)["pending"] == 1


def test_a_message_without_a_task_field_is_not_embedded(r, model):
    r.xadd(worker.STREAM, {"other": "x"})

    assert worker.read_new(r, CONSUMER, block_ms=10) == 0


def test_a_crashed_workers_entry_is_reclaimed_and_finished(r, model):
    queue(r, task_json())
    r.xreadgroup(worker.GROUP, "crashed-worker", {worker.STREAM: ">"}, count=8)

    handled = worker.reclaim(r, CONSUMER, min_idle_ms=0)

    assert handled == 1
    assert r.xlen(worker.RESULTS) == 1
    assert r.xpending(worker.STREAM, worker.GROUP)["pending"] == 0


def test_an_entry_that_keeps_failing_goes_to_the_dead_letter_stream_after_three_attempts(r, model):
    queue(r, task_json(model="some/other-model"))
    r.xreadgroup(worker.GROUP, "crashed-worker", {worker.STREAM: ">"}, count=8)

    for _ in range(worker.MAX_ATTEMPTS):
        worker.reclaim(r, CONSUMER, min_idle_ms=0)

    assert r.xlen(worker.DEAD) == 0
    assert r.xpending(worker.STREAM, worker.GROUP)["pending"] == 1

    worker.reclaim(r, CONSUMER, min_idle_ms=0)

    [(_, dead)] = r.xrange(worker.DEAD)
    assert dead["error"] == "exceeded 3 attempts"
    assert dead["stream"] == worker.STREAM
    assert json.loads(dead["task"])["task_id"] == TASK_ID
    assert r.xpending(worker.STREAM, worker.GROUP)["pending"] == 0
    assert r.hlen(worker.ATTEMPTS) == 0


def test_a_finished_entry_forgets_its_attempts(r, model):
    entry = queue(r, task_json())
    r.xreadgroup(worker.GROUP, "crashed-worker", {worker.STREAM: ">"}, count=8)

    worker.reclaim(r, CONSUMER, min_idle_ms=0)

    assert r.hget(worker.ATTEMPTS, entry) is None


def test_ensure_group_is_safe_to_call_twice(r):
    worker.ensure_group(r)

    assert [group["name"] for group in r.xinfo_groups(worker.STREAM)] == [worker.GROUP]


def test_the_model_is_loaded_once_with_both_repositories_pinned(monkeypatch):
    import sentence_transformers

    loads = []

    class Recorder:
        def __init__(self, repo, **kwargs):
            loads.append((repo, kwargs))

    monkeypatch.setattr(sentence_transformers, "SentenceTransformer", Recorder)
    monkeypatch.setattr(worker, "_model", None)
    monkeypatch.setenv("NOMIC_REVISION", "a" * 40)
    monkeypatch.setenv("NOMIC_CODE_REVISION", "b" * 40)

    first = worker.get_model()
    second = worker.get_model()

    assert first is second
    assert loads == [
        (
            worker.REPO,
            {
                "trust_remote_code": True,
                "revision": "a" * 40,
                "model_kwargs": {"code_revision": "b" * 40},
                "config_kwargs": {"code_revision": "b" * 40},
            },
        )
    ]


def test_the_model_is_not_loaded_without_a_pinned_revision(monkeypatch):
    monkeypatch.setattr(worker, "_model", None)
    monkeypatch.delenv("NOMIC_CODE_REVISION", raising=False)

    with pytest.raises(KeyError):
        worker.get_model()


def test_the_redis_client_waits_longer_than_a_blocking_read(monkeypatch):
    monkeypatch.setenv("REDIS_URL", "redis://localhost:6379/0")

    client = worker.connect()

    assert client.connection_pool.connection_kwargs["socket_timeout"] > worker.BLOCK_MS / 1000
