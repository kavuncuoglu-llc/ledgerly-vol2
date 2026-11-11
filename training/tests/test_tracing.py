import json

import fakeredis
import numpy as np
import pytest
from opentelemetry.sdk.trace import TracerProvider
from opentelemetry.sdk.trace.export import SimpleSpanProcessor
from opentelemetry.sdk.trace.export.in_memory_span_exporter import InMemorySpanExporter

from workers import embed_worker as worker
from workers import tracing

TASK_ID = "0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11"
TRACE_ID = "4bf92f3577b34da6a3ce929d0e0e4736"
SPAN_ID = "00f067aa0ba902b7"
TRACEPARENT = f"00-{TRACE_ID}-{SPAN_ID}-01"


class FakeModel:
    def encode(self, texts, batch_size, normalize_embeddings):
        return np.array([[1.0, 0.0, 0.0]] * len(texts))


@pytest.fixture
def exporter(monkeypatch):
    memory = InMemorySpanExporter()
    provider = TracerProvider()
    provider.add_span_processor(SimpleSpanProcessor(memory))
    monkeypatch.setattr(tracing, "tracer", provider.get_tracer("test"))
    monkeypatch.setattr(worker, "tracer", tracing.tracer)

    return memory


@pytest.fixture
def r(monkeypatch):
    monkeypatch.setattr(worker, "_model", FakeModel())
    client = fakeredis.FakeRedis(decode_responses=True)
    worker.ensure_group(client)

    return client


def task_json() -> str:
    return json.dumps(
        {
            "schema": "embed-batch.v1",
            "task_id": TASK_ID,
            "request_id": "req-1",
            "model": worker.REPO,
            "items": [{"id": 7, "text": "Late fees"}, {"id": 9, "text": "Recurring invoices"}],
        }
    )


def test_a_batch_gets_its_own_trace_with_a_link_to_the_sender(r, exporter):
    r.xadd(worker.STREAM, {"task": task_json(), "traceparent": TRACEPARENT})

    assert worker.read_new(r, "w", block_ms=10) == 1

    (span,) = exporter.get_finished_spans()

    assert span.name == "embed batch"
    assert span.attributes["ledgerly.batch_size"] == 2
    assert span.attributes["ledgerly.task_id"] == TASK_ID
    assert span.parent is None
    assert format(span.context.trace_id, "032x") != TRACE_ID  # its own trace, not the caller's
    (link,) = span.links
    assert format(link.context.trace_id, "032x") == TRACE_ID
    assert format(link.context.span_id, "016x") == SPAN_ID


def test_a_message_without_a_traceparent_still_works_and_has_no_links(r, exporter):
    r.xadd(worker.STREAM, {"task": task_json()})

    assert worker.read_new(r, "w", block_ms=10) == 1
    (span,) = exporter.get_finished_spans()

    assert span.links == ()


def test_a_malformed_traceparent_is_ignored_not_fatal():
    assert tracing.links_from({"traceparent": "not-a-traceparent"}) == []
    assert tracing.links_from({"traceparent": f"00-{'0' * 32}-{'0' * 16}-01"}) == []


def test_no_provider_means_no_op_spans(r):
    r.xadd(worker.STREAM, {"task": task_json(), "traceparent": TRACEPARENT})

    assert worker.read_new(r, "w", block_ms=10) == 1
    assert r.xlen(worker.RESULTS) == 1
