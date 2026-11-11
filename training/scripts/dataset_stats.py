import os
import sys

RERANK_MAX = 512
TOKENIZER_REPO = "BAAI/bge-reranker-base"  # the sidecar's own tokenizer
TOKENIZER_REVISION = "2cfc18c9415c912f9d8155881c133215df768a70"
DATA = "data/help_chunks.jsonl"
EXPERIMENT = "help-center-chunks"


def stats(lengths: list[int], rerank_max: int = RERANK_MAX) -> dict:
    over = sum(n > rerank_max for n in lengths)

    return {
        "rows": len(lengths),
        "max_tokens": max(lengths),
        "mean_tokens": sum(lengths) / len(lengths),
        "over": over,
        "share_over_rerank_max": over / len(lengths),
    }


def summary(result: dict, rerank_max: int = RERANK_MAX) -> str:
    return (
        f"{result['rows']} chunks · max {result['max_tokens']} · "
        f"{result['over']} over {rerank_max} ({result['share_over_rerank_max']:.1%})"
    )


def measure(path: str, tokenizer) -> list[int]:
    from datasets import load_dataset

    ds = load_dataset("json", data_files=path, split="train")  # Arrow-backed, memory-mapped
    ds = ds.map(
        lambda b: {"n_tokens": [len(t) for t in tokenizer(b["text"], truncation=False)["input_ids"]]}, batched=True
    )

    return ds["n_tokens"]


def log_run(result: dict, tracking_uri: str, rerank_max: int = RERANK_MAX) -> None:
    import mlflow

    mlflow.set_tracking_uri(tracking_uri)
    mlflow.set_experiment(EXPERIMENT)

    with mlflow.start_run(run_name="chunk-lengths"):
        mlflow.log_params({"tokenizer": TOKENIZER_REPO, "rerank_max": rerank_max, "rows": result["rows"]})
        mlflow.log_metrics(
            {
                "max_tokens": result["max_tokens"],
                "mean_tokens": result["mean_tokens"],
                "share_over_rerank_max": result["share_over_rerank_max"],
            }
        )


def main(path: str = DATA) -> int:
    from transformers import AutoTokenizer

    tok = AutoTokenizer.from_pretrained(TOKENIZER_REPO, revision=TOKENIZER_REVISION)
    result = stats(measure(path, tok))

    print(summary(result))

    tracking_uri = os.environ.get("MLFLOW_TRACKING_URI", "http://localhost:5050")

    try:
        log_run(result, tracking_uri)
    except (
        Exception
    ) as error:  # the numbers are printed either way; the tracker being down is a message, not a traceback
        print(
            f"MLflow at {tracking_uri} did not take the run ({type(error).__name__}). Start it with: "
            "docker compose --env-file .env -f ops/compose/base.yml -f ops/compose/mlflow.yml "
            "--profile mlflow up -d mlflow",
            file=sys.stderr,
        )

        return 1

    print(f"logged to {tracking_uri} (experiment {EXPERIMENT})")

    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1] if len(sys.argv) > 1 else DATA))
