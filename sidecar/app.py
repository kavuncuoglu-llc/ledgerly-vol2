import logging
import re
from contextlib import asynccontextmanager

from fastapi import FastAPI, File, UploadFile
from fastapi.responses import JSONResponse
from pydantic import BaseModel

from ledgerly_sidecar.core import (
    ModelUnavailable,
    ParseFailed,
    ParserUnavailable,
    ParseService,
    RerankService,
    load_cross_encoder,
    marker_convert,
)


log = logging.getLogger("ledgerly.sidecar")


def clean_request_id(value):
    return re.sub(r"[^A-Za-z0-9._-]", "", value or "")[:64]


class RerankRequest(BaseModel):
    question: str
    passages: list[str]


def create_app(rerank=None, parse=None):
    rerank = rerank or RerankService(load_cross_encoder)
    parse = parse or ParseService(marker_convert)

    @asynccontextmanager
    async def lifespan(app):
        rerank.load()
        yield

    app = FastAPI(title="Ledgerly sidecar", lifespan=lifespan)

    @app.middleware("http")
    async def echo_request_id(request, call_next):
        request_id = clean_request_id(request.headers.get("x-request-id"))

        response = await call_next(request)

        if request_id:
            response.headers["X-Request-Id"] = request_id
            log.info("%s %s status=%s request_id=%s", request.method, request.url.path, response.status_code, request_id)

        return response

    def problem(status, error):
        return JSONResponse(status_code=status, content={"detail": str(error)})

    @app.exception_handler(ValueError)
    async def on_value_error(request, error):
        return problem(422, error)

    @app.exception_handler(ParseFailed)
    async def on_parse_failed(request, error):
        return problem(422, error)

    @app.exception_handler(ParserUnavailable)
    async def on_parser_unavailable(request, error):
        return problem(503, error)

    @app.exception_handler(ModelUnavailable)
    async def on_model_unavailable(request, error):
        return problem(503, error)

    @app.get("/health")
    def health():
        return {
            "status": "ok",
            "reranker": rerank.model_name,
            "reranker_loaded": rerank.loaded,
            "parser": parse.parser_name,
        }

    @app.post("/rerank")
    def rerank_passages(body: RerankRequest):
        return {"scores": rerank.rerank(body.question, body.passages)}

    @app.post("/parse")
    def parse_pdf(file: UploadFile = File(...)):
        data = file.file.read(parse.max_bytes + 1)

        return parse.parse(data, file.filename or "")

    return app


app = create_app()
