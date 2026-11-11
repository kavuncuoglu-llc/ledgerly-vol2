from opentelemetry import propagate, trace
from opentelemetry.trace import Link

tracer = trace.get_tracer("ledgerly.py-worker")


def links_from(fields: dict) -> list[Link]:
    """The stream message carries the sender's trace context in a `traceparent` field. A batch worker serves requests
    it does not belong to, so it starts its own trace and LINKS to the sender's instead of becoming its child."""
    traceparent = fields.get("traceparent")

    if not traceparent:
        return []

    context = propagate.extract({"traceparent": traceparent})
    span_context = trace.get_current_span(context).get_span_context()

    return [Link(span_context)] if span_context.is_valid else []
