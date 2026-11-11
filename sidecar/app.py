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
