"""The logic of the PII sidecar that does not need Presidio.

The entity names the app uses, and how a Presidio result becomes a span.
"""

ENTITIES = {
    "PERSON": "PERSON",
    "LOCATION": "ADDRESS",
}

MAX_TEXT = 20_000


def wanted(requested):
    """Presidio entity names to ask for: the intersection of what was requested and what the app knows."""
    if not requested:
        return list(ENTITIES)

    names = {name.upper() for name in requested}

    return [presidio for presidio, app in ENTITIES.items() if app in names or presidio in names]


def to_span(result):
    """A Presidio RecognizerResult (anything with entity_type, start, end, score) as the JSON the app reads."""
    return {
        "type": ENTITIES.get(result.entity_type, result.entity_type),
        "start": int(result.start),
        "end": int(result.end),
        "score": round(float(result.score), 3),
    }


def to_spans(results, threshold):
    spans = [to_span(r) for r in results if r.score >= threshold]

    return sorted(spans, key=lambda s: (s["start"], -s["end"]))
