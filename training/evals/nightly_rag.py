import math

from evals.judge import judge_embeddings, judge_llm


def to_dataset(rows: list[dict]):
    from ragas import EvaluationDataset

    return EvaluationDataset.from_list(
        [
            {
                "user_input": row["question"],
                "response": row["answer"],
                "retrieved_contexts": row["contexts"],
                "reference": row["reference"],
            }
            for row in rows
        ]
    )


def metrics_for(rows: list[dict]) -> list:
    from ragas.metrics import Faithfulness, LLMContextPrecisionWithReference, LLMContextRecall, ResponseRelevancy

    metrics = [Faithfulness(), ResponseRelevancy()]  # no reference answer needed: these score live answers

    if all(row["reference"] for row in rows):
        metrics += [LLMContextPrecisionWithReference(), LLMContextRecall()]  # the golden set has references

    return metrics


COLUMNS = {
    "faithfulness": "faithfulness",
    "answer_relevancy": "response_relevancy",
    "llm_context_precision_with_reference": "context_precision",
    "context_recall": "context_recall",
}


def score(rows: list[dict], evaluate=None) -> list[dict]:
    """Returns [{id, metrics: {name: 0..1}}], one per row. A metric the judge could not compute (NaN) is left out."""
    if evaluate is None:
        from ragas import evaluate as ragas_evaluate

        evaluate = ragas_evaluate

    result = evaluate(
        to_dataset(rows), metrics=metrics_for(rows), llm=judge_llm(), embeddings=judge_embeddings(), show_progress=False
    )

    frame = result.to_pandas()
    scored = []

    for row, (_, values) in zip(rows, frame.iterrows(), strict=True):
        metrics = {}

        for column, name in COLUMNS.items():
            value = values.get(column)

            if value is None or (isinstance(value, float) and math.isnan(value)):
                continue

            metrics[name] = min(1.0, max(0.0, float(value)))

        if metrics:
            scored.append({"id": row["id"], "metrics": metrics})

    return scored
