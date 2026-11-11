import os

import numpy as np
import requests
from sentence_transformers import SentenceTransformer

BGE_REPO = "BAAI/bge-small-en-v1.5"
BGE_REVISION = "5c38ec7c405ec4b44b94cc5a9bb96e735b38267a"

DOCS = ["How the late fee is calculated", "Setting up recurring invoices", "Changing your billing email address"]
QUERIES = [
    "why was I charged extra for paying late",
    "can an invoice repeat every month",
    "where do my receipts get emailed",
]


def unit(m: np.ndarray) -> np.ndarray:
    return m / np.linalg.norm(m, axis=1, keepdims=True)


def ollama_base() -> str:
    return os.environ.get("OLLAMA_URL", "http://localhost:11434").removesuffix("/v1")


def ollama_embed(texts: list[str]) -> np.ndarray:
    r = requests.post(f"{ollama_base()}/api/embed", json={"model": "nomic-embed-text", "input": texts}, timeout=60)
    r.raise_for_status()

    return unit(np.array(r.json()["embeddings"]))


def best_matches(query_vectors: np.ndarray, doc_vectors: np.ndarray) -> list[tuple[int, float]]:
    scores = query_vectors @ doc_vectors.T

    return [(int(row.argmax()), float(row.max())) for row in scores]


def main() -> None:
    bge = SentenceTransformer(BGE_REPO, revision=BGE_REVISION)
    b_docs = bge.encode(DOCS, normalize_embeddings=True)
    b_q = bge.encode(QUERIES, normalize_embeddings=True)

    n_docs = ollama_embed([f"search_document: {d}" for d in DOCS])  # nomic's task prefixes (Volume 1, Ch 6)
    n_q = ollama_embed([f"search_query: {q}" for q in QUERIES])

    bge_best = best_matches(b_q, b_docs)
    nomic_best = best_matches(n_q, n_docs)

    for i, q in enumerate(QUERIES):
        (b_idx, b_score), (n_idx, n_score) = bge_best[i], nomic_best[i]
        print(f"{q[:38]:38}  bge → {DOCS[b_idx][:28]:28} {b_score:.2f}   nomic → {DOCS[n_idx][:28]:28} {n_score:.2f}")


if __name__ == "__main__":
    main()
