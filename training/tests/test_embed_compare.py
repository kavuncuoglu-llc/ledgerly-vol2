import numpy as np
import pytest

from scripts import embed_compare


class FakeResponse:
    def __init__(self, body):
        self.body = body

    def raise_for_status(self):
        return None

    def json(self):
        return self.body


def test_unit_gives_every_row_length_one():
    result = embed_compare.unit(np.array([[3.0, 4.0], [0.0, 2.0]]))

    assert np.allclose(np.linalg.norm(result, axis=1), 1.0)


def test_best_matches_picks_the_closest_document_per_query():
    queries = np.array([[1.0, 0.0], [0.0, 1.0]])
    docs = np.array([[0.0, 1.0], [1.0, 0.0]])

    assert embed_compare.best_matches(queries, docs) == [(1, 1.0), (0, 1.0)]


@pytest.mark.parametrize(
    ("url", "expected"),
    [
        ("http://localhost:11434/v1", "http://localhost:11434"),
        ("http://localhost:11434", "http://localhost:11434"),
    ],
)
def test_ollama_base_drops_the_openai_suffix(monkeypatch, url, expected):
    monkeypatch.setenv("OLLAMA_URL", url)

    assert embed_compare.ollama_base() == expected


def test_ollama_embed_posts_to_the_native_embed_endpoint_and_normalizes(monkeypatch):
    seen = {}

    def fake_post(url, json, timeout):
        seen.update(url=url, json=json, timeout=timeout)
        return FakeResponse({"embeddings": [[3.0, 4.0]]})

    monkeypatch.setenv("OLLAMA_URL", "http://ollama.test:11434/v1")
    monkeypatch.setattr(embed_compare.requests, "post", fake_post)

    vectors = embed_compare.ollama_embed(["search_query: hello"])

    assert seen["url"] == "http://ollama.test:11434/api/embed"
    assert seen["json"] == {"model": "nomic-embed-text", "input": ["search_query: hello"]}
    assert np.allclose(vectors, [[0.6, 0.8]])
