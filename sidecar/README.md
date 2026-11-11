# Ledgerly sidecar

Small HTTP service for the Laravel app: cross-encoder reranking and PDF-to-Markdown parsing. Logic lives in `ledgerly_sidecar/core.py` (stdlib only); `app.py` is the thin FastAPI wrapper.

- `GET /health` -> `{"status":"ok","reranker":"...","reranker_loaded":true,"parser":"marker"}`
- `POST /rerank` -> `curl -X POST localhost:8008/rerank -H 'Content-Type: application/json' -d '{"question":"How do I retry a job?","passages":["Use tries.","Use cache."]}'` returns `{"scores":[...]}`, same order as `passages` (max 100)
- `POST /parse` -> `curl -X POST localhost:8008/parse -F file=@invoice.pdf` returns `{"markdown":"...","parser":"marker"}`
- 422 for bad input, 503 when the model or marker is unavailable (the reranker retries loading on the next request).

Environment variables: `SIDECAR_RERANK_MODEL` (default `BAAI/bge-reranker-base`), `SIDECAR_MAX_PASSAGE_CHARS` (2000), `SIDECAR_MAX_BYTES` (26214400).

Tests (stdlib only, offline; the FastAPI tests are skipped unless fastapi, httpx and python-multipart are installed):

    python3 -I -m unittest discover -s tests -v

Build and run (the first start downloads model weights from Hugging Face, so it needs network once):

    docker build -t ledgerly-sidecar . && docker run --rm -p 8008:8000 ledgerly-sidecar

The container holds no credentials and has no database access. Dependency pins were chosen offline and must be re-verified with pip.
