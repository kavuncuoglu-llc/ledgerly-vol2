"""Chapter 34, Topic 4: Presidio's named-entity recognition behind /pii/analyze.

Names and addresses only; the card, SSN, email, phone and IBAN detectors run in PHP, where they cannot be down.
"""

from functools import lru_cache

from fastapi import FastAPI, HTTPException
from privacy_sidecar.core import MAX_TEXT, to_spans, wanted
from pydantic import BaseModel, Field

app = FastAPI(title="Ledgerly PII sidecar")


class AnalyzeRequest(BaseModel):
    text: str = Field(max_length=MAX_TEXT)
    entities: list[str] | None = None
    score_threshold: float = 0.5
    language: str = "en"


@lru_cache(maxsize=1)
def engine():
    from presidio_analyzer import AnalyzerEngine
    from presidio_analyzer.nlp_engine import NlpEngineProvider

    provider = NlpEngineProvider(
        nlp_configuration={
            "nlp_engine_name": "spacy",
            "models": [{"lang_code": "en", "model_name": "en_core_web_sm"}],
        }
    )

    return AnalyzerEngine(nlp_engine=provider.create_engine(), supported_languages=["en"])


@app.get("/health")
def health():
    engine()

    return {"status": "ok", "engine": "presidio"}


@app.post("/pii/analyze")
def analyze(request: AnalyzeRequest):
    if request.language != "en":
        raise HTTPException(status_code=422, detail="Only English is loaded.")

    results = engine().analyze(
        text=request.text,
        language=request.language,
        entities=wanted(request.entities),
        score_threshold=request.score_threshold,
    )

    return {"spans": to_spans(results, request.score_threshold)}
