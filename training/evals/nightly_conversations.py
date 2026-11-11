from evals.judge import api_key, base_url, judge_name

THRESHOLD = 0.7


def judge_model():
    from deepeval.models import (
        LocalModel,
    )  # any OpenAI-compatible endpoint: Ollama here, a hosted judge through JUDGE_BASE_URL

    return LocalModel(model=judge_name(), base_url=base_url(), api_key=api_key(), temperature=0)


def score(conversations: list[dict], model=None) -> list[dict]:
    """Returns [{id, metrics: {conversation_completeness, knowledge_retention}}] (DeepEval's conversational metrics)."""
    from deepeval.metrics import ConversationCompletenessMetric, KnowledgeRetentionMetric
    from deepeval.test_case import ConversationalTestCase, Turn

    model = model or judge_model()
    scored = []

    for conversation in conversations:
        case = ConversationalTestCase(turns=[Turn(role=t["role"], content=t["content"]) for t in conversation["turns"]])
        metrics = {}

        for name, metric in (
            ("conversation_completeness", ConversationCompletenessMetric(threshold=THRESHOLD, model=model)),
            ("knowledge_retention", KnowledgeRetentionMetric(threshold=THRESHOLD, model=model)),
        ):
            metric.measure(case)

            if metric.score is not None:
                metrics[name] = min(1.0, max(0.0, float(metric.score)))

        if metrics:
            scored.append({"id": conversation["id"], "metrics": metrics})

    return scored
